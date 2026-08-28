<?php

namespace Tests\Integration;

use App\Core\Throttle;
use Tests\Support\DatabaseTestCase;

/**
 * Throttle — عدّاد المحاولات العامّ لنقاط الدخول الحسّاسة.
 *
 * يُختبر على قاعدة حقيقية لا على بديل في الذاكرة، لأن نصف منطقه في
 * الـSQL نفسه: النافذة تُحسب بـDATE_SUB داخل MySQL، والفهرس المركّب هو
 * ما يجعل العدّ ممكناً. بديلٌ في PHP كان سيختبر شيئاً آخر.
 *
 * الدلو المستعمل هنا مُعرَّف محلياً ولا يطابق أي دلو حقيقي في جدول
 * المسارات: الاختبار يفحص الآلية، وربطه بأسماء الإنتاج كان سيجعله يفشل
 * كلّما غُيّر حدٌّ في مسار لا علاقة له به.
 */
final class ThrottleTest extends DatabaseTestCase
{
    private const BUCKET = 'test-bucket';
    private const WHO    = '203.0.113.7';

    public function testAFreshIdentifierIsNotThrottled(): void
    {
        $this->assertFalse(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));
    }

    public function testTheLimitIsReachedAtTheThresholdNotAfterIt(): void
    {
        // الحدّ 3 يعني «ثلاث محاولات مسموحة، والرابعة مرفوضة». الخطأ
        // بواحد هنا ليس تجميلاً: >= بدل > يضاعف عملياً ما يسمح به
        // الحارس على كل نقطة في المشروع.
        Throttle::record(self::BUCKET, self::WHO);
        Throttle::record(self::BUCKET, self::WHO);
        $this->assertFalse(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));

        Throttle::record(self::BUCKET, self::WHO);
        $this->assertTrue(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));
    }

    /**
     * الدلاء معزولة: استنفاد أحدها لا يقفل الآخر.
     *
     * بدون العزل يقفل من يجرّب استعادة كلمة المرور بابَ تسجيل الدخول
     * على نفسه — وهما نقطتان لا علاقة لإحداهما بالأخرى.
     */
    public function testBucketsAreIsolatedFromEachOther(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Throttle::record('bucket-one', self::WHO);
        }

        $this->assertTrue(Throttle::tooMany('bucket-one', self::WHO, 3, 15));
        $this->assertFalse(Throttle::tooMany('bucket-two', self::WHO, 3, 15));
    }

    /**
     * والمصادر معزولة: خنق مصدر لا يخنق غيره.
     *
     * هذا هو الفرق الجوهري عن خنق مرتبط بالبريد — ولو انهار العزل هنا
     * لصار مهاجم واحد قادراً على قفل الموقع أمام كل الزوّار.
     */
    public function testIdentifiersAreIsolatedFromEachOther(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Throttle::record(self::BUCKET, self::WHO);
        }

        $this->assertTrue(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));
        $this->assertFalse(Throttle::tooMany(self::BUCKET, '198.51.100.4', 3, 15));
    }

    /**
     * ما خرج من النافذة لا يُحتسب.
     *
     * الأثر يُزرع بتاريخ قديم مباشرةً بدل الانتظار: اختبار ينام ربع
     * ساعة ليثبت انقضاء نافذة هو اختبار لا يُشغَّل.
     */
    public function testAttemptsOlderThanTheWindowDoNotCount(): void
    {
        $this->pdo->exec(
            "INSERT INTO throttle_attempts (bucket, identifier, attempted_at) VALUES
             ('" . self::BUCKET . "', '" . self::WHO . "', DATE_SUB(NOW(), INTERVAL 40 MINUTE)),
             ('" . self::BUCKET . "', '" . self::WHO . "', DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
             ('" . self::BUCKET . "', '" . self::WHO . "', DATE_SUB(NOW(), INTERVAL 20 MINUTE))"
        );

        // نافذة 15 دقيقة: الثلاثة كلها خارجها.
        $this->assertFalse(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));

        // نافذة 60 دقيقة: الثلاثة كلها داخلها.
        $this->assertTrue(Throttle::tooMany(self::BUCKET, self::WHO, 3, 60));
    }

    public function testClearRemovesTheIdentifiersTraceOnThatBucketOnly(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Throttle::record(self::BUCKET, self::WHO);
            Throttle::record('other-bucket', self::WHO);
        }

        Throttle::clear(self::BUCKET, self::WHO);

        $this->assertFalse(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));
        $this->assertTrue(Throttle::tooMany('other-bucket', self::WHO, 3, 15));
    }

    /**
     * الكنس يحذف القديم ولا يمسّ ما داخل مدّة الاحتفاظ.
     *
     * الحدّان معاً: لو حذف الحديث لصار الخنق بلا ذاكرة، ولو أبقى القديم
     * لنما الجدول بلا حدّ في مشروع بلا cron مضمون.
     */
    public function testRecordPrunesOnlyTracesOlderThanTheRetention(): void
    {
        $this->pdo->exec(
            "INSERT INTO throttle_attempts (bucket, identifier, attempted_at) VALUES
             ('" . self::BUCKET . "', '" . self::WHO . "', DATE_SUB(NOW(), INTERVAL 3 DAY)),
             ('" . self::BUCKET . "', '" . self::WHO . "', DATE_SUB(NOW(), INTERVAL 2 HOUR))"
        );

        Throttle::record(self::BUCKET, self::WHO);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM throttle_attempts WHERE bucket = ? AND identifier = ?'
        );
        $stmt->execute([self::BUCKET, self::WHO]);

        // بقي أثر الساعتين والأثر الجديد؛ ذهب أثر الأيام الثلاثة.
        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    /**
     * عنوان المصدر لا يُقرأ من ترويسة يرسلها العميل.
     *
     * X-Forwarded-For قابلة للتزوير بالكامل: لو قُرئت بلا بروكسي موثوق
     * أمامها لصار تجاوز الخنق بتغيير ترويسة في كل طلب — أي أن الحارس
     * يصير تزييناً. هذا الاختبار يمنع «تحسيناً» لاحقاً يفتحها.
     */
    public function testClientIpIgnoresForwardedHeaders(): void
    {
        $_SERVER['REMOTE_ADDR']          = '192.0.2.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $this->assertSame('192.0.2.10', Throttle::clientIp());

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }
}
