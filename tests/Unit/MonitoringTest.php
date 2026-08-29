<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * تنظيف بيانات الرصد قبل مغادرتها الخادم.
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا اختبار على هذه الدالة تحديداً
 * ══════════════════════════════════════════════════════════════
 *
 * `monitoringScrub` هي آخر ما يقف بين `$_POST` وطرفٍ ثالث. وسياق
 * الطلب الذي يرسله Sentry مع كل خطأ يشمل جسم الطلب كاملاً — أي كلمات
 * المرور وتوكنات CSRF وأكواد 2FA ومفاتيح الاستعادة.
 *
 * وفشلها لا يظهر في أي شاشة ولا يُفشل أي طلب: كل ما يحدث أن سرّاً
 * يغادر إلى خادم آخر. لا سبيل لاكتشافه إلا باختبار يسأل الدالة
 * مباشرةً — وهذا هو.
 *
 * ⚠️ من يضيف حقلاً حسّاساً في أي نموذج مسؤول عن إضافته إلى
 * MONITORING_SCRUB_KEYS، وعن سطر هنا يثبت أنه يُنظَّف.
 */
final class MonitoringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // الملف يُحمَّل من config.php في التشغيل العادي؛ الاختبار يحمّله
        // وحده كي يبقى وحدةً لا تحتاج بيئة.
        require_once dirname(__DIR__, 2) . '/app/config/monitoring.php';
    }

    /** @return list<array{0: string}> */
    public static function sensitiveKeys(): array
    {
        return [
            ['password'],
            ['confirm_password'],
            ['current_password'],
            ['new_password'],
            ['csrf_token'],
            ['token'],
            ['totp_code'],
            ['totp_secret'],
            ['secret'],
            ['h-captcha-response'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveKeys')]
    public function testASensitiveKeyNeverKeepsItsValue(string $key): void
    {
        $out = monitoringScrub([$key => 'THE-ACTUAL-SECRET']);

        $this->assertSame('[scrubbed]', $out[$key]);
        $this->assertStringNotContainsString('THE-ACTUAL-SECRET', json_encode($out) ?: '');
    }

    /**
     * التعاود ليس تفصيلاً: جسم JSON يصل الكنترولرز كمصفوفة متشعّبة
     * (checkout يرسل `items` مثلاً)، وتنظيف المستوى الأوّل وحده يعطي
     * إحساساً بالأمان بلا أمان.
     */
    public function testNestedSecretsAreScrubbedToo(): void
    {
        $out = monitoringScrub([
            'order' => [
                'items'      => [['qty' => 2, 'csrf_token' => 'LEAK-1']],
                'auth'       => ['current_password' => 'LEAK-2'],
            ],
        ]);

        $encoded = json_encode($out) ?: '';

        $this->assertStringNotContainsString('LEAK-1', $encoded);
        $this->assertStringNotContainsString('LEAK-2', $encoded);
        $this->assertSame(2, $out['order']['items'][0]['qty'], 'البيانات غير الحسّاسة تغيّرت.');
    }

    public function testTheKeyMatchIsCaseInsensitive(): void
    {
        // أسماء الحقول تأتي من نماذج كتبها بشر. `CSRF_Token` و
        // `Password` واردان، وحساسية حالة الأحرف هنا تعني ثغرة صامتة.
        $out = monitoringScrub(['Password' => 'X', 'CSRF_TOKEN' => 'Y']);

        $this->assertSame('[scrubbed]', $out['Password']);
        $this->assertSame('[scrubbed]', $out['CSRF_TOKEN']);
    }

    public function testOrdinaryFieldsSurviveUntouched(): void
    {
        // تنظيفٌ يمحو كل شيء يجعل التقرير عديم الفائدة. الغرض إخفاء
        // الأسرار لا إخفاء العطل.
        $in  = ['email' => 'a@b.c', 'qty' => 3, 'note' => 'hello', 'items' => [1, 2]];
        $out = monitoringScrub($in);

        $this->assertSame($in, $out);
    }

    /**
     * كل مفتاح في القائمة يجب أن يكون بأحرف صغيرة.
     *
     * المقارنة تُصغّر **المفتاح الوارد** لا مفتاح القائمة، فمدخلٌ
     * بأحرف كبيرة في القائمة لا يطابق شيئاً أبداً — ويبدو مع ذلك
     * كأنه يحمي.
     */
    public function testEveryConfiguredKeyIsLowercase(): void
    {
        foreach (MONITORING_SCRUB_KEYS as $key) {
            $this->assertSame(
                strtolower($key),
                $key,
                "المفتاح [{$key}] بأحرف كبيرة — لن يطابق شيئاً."
            );
        }
    }
}
