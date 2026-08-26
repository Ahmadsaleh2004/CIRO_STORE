<?php

namespace App\Core;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

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
     */
    public static function connect(): PDO
    {
        return self::getInstance()->getConnection();
    }
}