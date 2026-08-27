<?php

namespace Tests\Support;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * أساس اختبارات التكامل.
 *
 * يفعل ثلاثة أشياء لكل اختبار:
 *   1. يتخطّى الاختبار كلّه إن لم تتوفّر قاعدة اختبار — كي يبقى
 *      `composer test` قابلاً للتشغيل على جهاز بلا MySQL، وكي لا تفشل
 *      اختبارات الوحدة بسبب غياب خدمة لا تحتاجها.
 *   2. يحقن اتصال قاعدة الاختبار في Database — فتذهب كل استعلامات
 *      المودلز الـ158 إليها بدل قاعدة التطوير.
 *   3. يُفرّغ الجداول قبل كل اختبار كي لا يرث اختبارٌ حالةَ سابقه.
 *
 * التفريغ يسبق الاختبار لا يليه عمداً: اختبار فاشل يترك بياناته على
 * القاعدة لتُفحص، والاختبار التالي ينظّف قبل أن يبدأ.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = prepareTestDatabase();
        if ($pdo === null) {
            $this->markTestSkipped(
                'قاعدة الاختبار غير متاحة (MySQL لا يستجيب أو tests/fixtures/schema.sql مفقود).'
            );
        }

        $this->pdo = $pdo;
        Database::setConnection($pdo);
        $this->truncateAll();
    }

    protected function tearDown(): void
    {
        // مسح الحقن كي لا يتسرّب اتصال الاختبار إلى ما بعده.
        Database::reset();
        parent::tearDown();
    }

    /**
     * يُفرّغ كل جداول قاعدة الاختبار.
     *
     * فحص المفاتيح الأجنبية يُطفأ مؤقتاً: الجداول مترابطة (orders →
     * order_items → products)، وأي ترتيب ثابت للتفريغ سينكسر لحظة
     * إضافة علاقة جديدة. الإطفاء يجعل الترتيب غير ذي صلة.
     *
     * **DELETE لا TRUNCATE** — والفرق مقيس لا مفترض:
     *
     *     TRUNCATE (28 جدولاً):  8.585 ثانية
     *     DELETE   (28 جدولاً):  0.256 ثانية   ← أسرع 33 مرّة
     *
     * السبب أن TRUNCATE في InnoDB يُسقط مساحة الجدول ويعيد إنشاءها،
     * وهي عملية على نظام الملفات لا على الصفوف — فتكلفتها ثابتة مهما
     * كان الجدول فارغاً. وDELETE على جدول فارغ لا يفعل شيئاً تقريباً.
     *
     * جُرّب أولاً تقليل عدد الرحلات (عبارة واحدة بدل 29)، فلم يُحسّن
     * شيئاً بل زاد الزمن — أي أن العنق لم يكن في الشبكة إطلاقاً.
     * القياس هو ما كشف ذلك؛ التخمين كان سيبقي المشكلة.
     *
     * الأثر: مجموعة اختبارات بطيئة لا تُشغَّل، ومجموعة لا تُشغَّل لا
     * تحمي شيئاً.
     *
     * ملاحظة: DELETE لا يُصفّر AUTO_INCREMENT. لا اختبار هنا يعتمد على
     * معرّف بعينه (كلها تقرأ lastInsertId)، فالفرق بلا أثر — ولو
     * احتاجه اختبار لاحق فليُصفّره بنفسه صراحةً.
     */
    protected function truncateAll(): void
    {
        // ⚠️ القائمة تُقرأ في كل مرّة، **ولا تُخبَّأ**.
        //
        // كانت مخبّأة في حقل static كتحسين، والتحسين كان خاطئاً من
        // وجهين: أوّلاً أن القياس أثبت أن العنق هو TRUNCATE نفسه لا عدد
        // الرحلات (راجع أدناه)، فالتخبئة لم توفّر شيئاً يُذكر. وثانياً —
        // وهو الأهمّ — أنها تفترض مخطّطاً ثابتاً طوال التشغيل.
        //
        // وMigratorTest ينقض ذلك: يُسقط schema_migrations ويُنشئ جداول
        // مؤقّتة. فالقائمة المخبّأة من أول اختبار تصير كاذبة، وتفشل
        // سبعة عشر اختباراً بـ«Base table doesn't exist».
        //
        // استعلام SHOW TABLES رحلة واحدة بأجزاء من الملّي ثانية. الثمن
        // الحقيقي كان في مكان آخر.
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        $sql = 'SET FOREIGN_KEY_CHECKS = 0; ';
        foreach ($tables as $table) {
            $sql .= 'DELETE FROM `' . str_replace('`', '``', $table) . '`; ';
        }
        $sql .= 'SET FOREIGN_KEY_CHECKS = 1;';

        $this->pdo->exec($sql);
    }

    /** يعدّ صفوف جدول — مساعد يتكرّر في كل اختبار تقريباً. */
    protected function countRows(string $table): int
    {
        return (int) $this->pdo
            ->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')
            ->fetchColumn();
    }
}
