<?php

namespace App\Core;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    /**
     * اتصال محقون يتقدّم على الاتصال الحقيقي — للاختبارات وحدها.
     *
     * هذا هو **كل** ما احتاجه المشروع ليصير قابلاً للاختبار. المودلز
     * كلها static وتنادي Database::connect() — مئة وثمانية وخمسون
     * موضعاً — فنقطة الاختناق واحدة، ويكفي أن تُرجع اتصالاً آخر لتصير
     * 4,827 سطر مودل قابلة للاختبار بلا لمس سطر واحد فيها.
     *
     * وهذا مقصود: CLEANUP-PLAN قرّر «المودلز تبقى static»، والقرار
     * صحيح ولم يُنقض. البديل — تحويل كل مودل إلى كائن يستقبل PDO في
     * الباني — كان سيغيّر مئة وثمانية وخمسين نداءً وكل مستدعيها لمكسب
     * لا يزيد على ما يعطيه هذان السطران.
     */
    private static ?PDO $injected = null;

    private function __construct()
    {
        // الثوابت مضمونة: config.php يعرّفها كلها من .env، وهو يُحمَّل
        // قبل أي وصول لهذا الكلاس من كل نقطة دخول (public/index.php
        // وسكربتات scripts/). كان هنا `defined(...) ? ... : $_ENV[...]`
        // لكل مفتاح — احتياط لم يكن يعمل: الثوابت كانت **دائماً**
        // معرَّفة بقيم مكتوبة صراحةً في config.php، فالفرع الثاني ميّت
        // وملف .env يُقرأ ثم يُتجاهل.
        $dsn = 'mysql:host=' . DB_HOST
             . ';port='     . DB_PORT
             . ';dbname='   . DB_NAME
             . ';charset='  . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // ⚠️ لا تُمرَّر رسالة PDO إلى المتصفح أبداً. كانت هنا
            //     die("خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage())
            // ورسالة PDO تحمل اسم المضيف واسم القاعدة واسم المستخدم
            // حرفياً — أي أن أول خطأ اتصال على الإنتاج كان يسلّم الزائرَ
            // نصفَ بيانات الدخول. وdie تُنهي الطلب بكود **200**، فتقرأ
            // محرّكات البحث وأدوات المراقبة الصفحةَ المكسورة كصفحة سليمة.
            //
            // 503 لا 500: القاعدة ساقطة يعني الخدمة غير متاحة مؤقتاً.
            ErrorPage::serverError('فشل الاتصال بقاعدة البيانات: ' . $e->getMessage(), 503);
        }
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton instance.");
    }

    /**
     * الحصول على النسخة الوحيدة من كلاس Database (Singleton Gateway)
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * الحصول على كائن الـ PDO
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * اختصار استدعاء سريع للحصول على PDO مباشرة: Database::connect()
     *
     * يفحص الاتصال المحقون أولاً. في الإنتاج يكون null دائماً
     * (setConnection ترفض العمل خارج CLI)، فالمسار كما كان تماماً.
     */
    public static function connect(): PDO
    {
        return self::$injected ?? self::getInstance()->getConnection();
    }

    /**
     * يحقن اتصالاً بديلاً — **للاختبارات وحدها**.
     *
     * ⚠️ محصورة في CLI عمداً وترمي خارجها. الحصر ليس تزيّناً: بدونه
     * يكفي أن يستدعيها مسار طلب واحد — عن سهو أو عبر ثغرة تنفيذ —
     * ليُحوّل كل استعلامات التطبيق إلى قاعدة يسيطر عليها المهاجم.
     * PHPUnit يعمل على CLI دائماً، فالحصر لا يكلّف الاختبارات شيئاً.
     *
     * @throws \LogicException إن استُدعيت من سياق ويب.
     */
    public static function setConnection(PDO $pdo): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException(
                'Database::setConnection() is a test-only seam and must never run outside CLI.'
            );
        }

        self::$injected = $pdo;
    }

    /**
     * يمسح الاتصال المحقون ونسخة الـsingleton معاً.
     *
     * تُستدعى في tearDown كي لا يتسرّب اتصال اختبار إلى الاختبار التالي.
     * مسح $instance أيضاً مقصود: اختبار يريد اتصالاً حقيقياً بعد اختبار
     * حقن يجب أن يبنيه من جديد لا أن يرث نسخة قديمة.
     */
    public static function reset(): void
    {
        self::$injected = null;
        self::$instance = null;
    }
}