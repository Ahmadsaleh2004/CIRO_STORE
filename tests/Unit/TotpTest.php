<?php

namespace Tests\Unit;

use App\Core\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Totp — المصادقة الثنائية لحسابات الأدمن.
 *
 * تُختبر مقابل **متجهات RFC 6238 المرجعية** لا مقابل نفسها. الفرق
 * جوهري: تنفيذ TOTP معطوب يبقى متّسقاً مع ذاته تماماً (يولّد رمزاً
 * ويقبله)، فاختبار «ولّد ثم تحقّق» يمرّ على كود لا يعمل مع Google
 * Authenticator إطلاقاً. المتجهات المرجعية وحدها تكشف ذلك.
 */
final class TotpTest extends TestCase
{
    /** السرّ المرجعي في RFC 6238: "12345678901234567890" بترميز Base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private function generateCodeAt(string $secret, int $timeSlice): string
    {
        $m = new ReflectionMethod(Totp::class, 'generateCode');
        $m->setAccessible(true);
        return $m->invoke(null, $secret, $timeSlice);
    }

    /**
     * متجهات RFC 6238 (SHA-1). القيم في المعيار من ثماني خانات؛
     * المشروع يستعمل ستّاً، فتُؤخذ الخانات الستّ الأخيرة.
     *
     * @return array<string, array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            'T=59'          => [59,          '287082'],
            'T=1111111109'  => [1111111109,  '081804'],
            'T=1111111111'  => [1111111111,  '050471'],
            'T=1234567890'  => [1234567890,  '005924'],
            'T=2000000000'  => [2000000000,  '279037'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function testMatchesRfc6238ReferenceVectors(int $unixTime, string $expected): void
    {
        $timeSlice = (int) floor($unixTime / 30);

        $this->assertSame(
            $expected,
            $this->generateCodeAt(self::RFC_SECRET, $timeSlice),
            "الرمز عند T={$unixTime} لا يطابق متجه RFC 6238 — التطبيق لن يعمل مع Google Authenticator."
        );
    }

    public function testGeneratedSecretIsValidBase32OfTheExpectedLength(): void
    {
        $secret = Totp::generateSecret();

        $this->assertSame(20, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{20}$/', $secret);
    }

    public function testGeneratedSecretsDiffer(): void
    {
        $secrets = [];
        for ($i = 0; $i < 20; $i++) {
            $secrets[] = Totp::generateSecret();
        }

        // سرّ ثابت يعني أن كل حسابات الأدمن تتشارك المصادقة الثنائية نفسها.
        $this->assertCount(20, array_unique($secrets));
    }

    public function testVerifyAcceptsTheCurrentCode(): void
    {
        $secret = Totp::generateSecret();
        $code   = $this->generateCodeAt($secret, (int) floor(time() / 30));

        $this->assertTrue(Totp::verifyCode($secret, $code));
    }

    /**
     * نافذة التسامح ±30 ثانية — لا أكثر.
     *
     * توسيعها يسهّل الاستعمال ويوسّع نافذة إعادة اللعب بالقدر نفسه.
     * هذا الاختبار يثبّت الحدّ: ما داخل النافذة يُقبل، وما خارجها يُرفض.
     */
    public function testVerifyAcceptsOneStepBeforeAndAfter(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        $this->assertTrue(Totp::verifyCode($secret, $this->generateCodeAt($secret, $now - 1)));
        $this->assertTrue(Totp::verifyCode($secret, $this->generateCodeAt($secret, $now + 1)));
    }

    public function testVerifyRejectsCodesOutsideTheTolerance(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        $this->assertFalse(Totp::verifyCode($secret, $this->generateCodeAt($secret, $now - 5)));
        $this->assertFalse(Totp::verifyCode($secret, $this->generateCodeAt($secret, $now + 5)));
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $secret = Totp::generateSecret();

        foreach (['', '12345', '1234567', 'abcdef', '12 34 56', '<script>'] as $bad) {
            $this->assertFalse(
                Totp::verifyCode($secret, $bad),
                "قُبل مدخل غير صالح: [{$bad}]"
            );
        }
    }

    public function testVerifyIgnoresSurroundingWhitespace(): void
    {
        // المستخدم ينسخ الرمز من التطبيق فتأتي معه مسافة — رفضه هنا
        // عطل استعمال لا حماية.
        $secret = Totp::generateSecret();
        $code   = $this->generateCodeAt($secret, (int) floor(time() / 30));

        $this->assertTrue(Totp::verifyCode($secret, "  {$code}  "));
    }

    public function testACodeFromADifferentSecretIsRejected(): void
    {
        $mine   = Totp::generateSecret();
        $theirs = Totp::generateSecret();
        $code   = $this->generateCodeAt($theirs, (int) floor(time() / 30));

        $this->assertFalse(Totp::verifyCode($mine, $code));
    }
}
