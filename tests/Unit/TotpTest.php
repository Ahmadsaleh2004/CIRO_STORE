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

    // ════════════════════════════════════════════════════════
    // صورة QR — تُولَّد محلياً، والسرّ لا يغادر الخادم
    // ════════════════════════════════════════════════════════

    /**
     * الـQR صورة مضمّنة لا رابط إلى مضيف خارجي.
     *
     * كان المولّد يُرجع رابطاً إلى api.qrserver.com يحمل السرّ في
     * الـquery string — أي أن سرّ المصادقة الثنائية لكل أدمن كان يُسلَّم
     * إلى طرف ثالث ويمرّ في سجلّاته وسجلّات أي وسيط. هذا الاختبار يمنع
     * عودة ذلك مهما تغيّر التنفيذ.
     */
    public function testTheQrCodeIsAnInlineImageNotARemoteUrl(): void
    {
        $secret = Totp::generateSecret();
        $src    = Totp::getQrCodeUrl($secret, 'admin@example.com');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $src);
        $this->assertDoesNotMatchRegularExpression(
            '#https?://#i',
            $src,
            'صورة الـQR تشير إلى مضيف خارجي — السرّ يغادر الخادم.'
        );
    }

    /**
     * والأهمّ: السرّ نفسه ليس في أي موضع يمكن أن يُرسَل.
     *
     * الفحص على القيمة المفكوكة لا على النصّ المُرمَّز: base64 يخفي
     * السرّ عن القراءة السريعة ولا يخفيه عن الشبكة.
     */
    public function testTheSecretDoesNotAppearInAnyRequestableForm(): void
    {
        $secret = Totp::generateSecret();
        $src    = Totp::getQrCodeUrl($secret, 'admin@example.com');
        $svg    = base64_decode(substr($src, strlen('data:image/svg+xml;base64,')));

        $this->assertStringContainsString('<svg', $svg, 'الناتج ليس SVG صالحاً.');

        // السرّ مُرمَّز داخل وحدات الـQR نفسها (مربّعات)، لا كنصّ.
        $this->assertStringNotContainsString($secret, $svg);
        $this->assertStringNotContainsString('otpauth://', $svg);
    }

    /**
     * رابط otpauth يحمل ما تحتاجه تطبيقات المصادقة.
     *
     * يُعرض نصّاً للأدمن حين يتعذّر المسح — فلو نقصه المُصدِر أو السرّ
     * لأضاف الأدمن حساباً لا يعمل، ولا يكتشف ذلك إلا عند أوّل دخول.
     */
    public function testTheProvisioningUriCarriesIssuerAndSecret(): void
    {
        $secret = Totp::generateSecret();
        $uri    = Totp::provisioningUri($secret, 'admin@example.com', 'Cairo Store');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=Cairo%20Store', $uri);
    }

    /**
     * لا مصدر في المشروع يذكر خدمة QR خارجية.
     *
     * الاختباران أعلاه يحرسان المولّد الحالي؛ هذا يحرس المشروع من
     * «حلّ سريع» في موضع آخر — سطر في view أو في JS يبني الرابط بنفسه.
     * السرّ في الـquery string لا يصير آمناً بتغيير من يكتبه.
     */
    public function testNoSourceReferencesAnExternalQrService(): void
    {
        $roots = [
            dirname(__DIR__, 2) . '/app',
            dirname(__DIR__, 2) . '/public/js',
        ];

        $offenders = [];

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (!in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }

                $src = (string) file_get_contents($file->getPathname());

                foreach (['qrserver.com', 'chart.googleapis.com', 'quickchart.io'] as $host) {
                    // الذكر داخل تعليق يشرح الإصلاح مسموح؛ الممنوع بناء
                    // رابط فعلي — أي المضيف مسبوقاً بمخطّط.
                    if (preg_match('#https?://[^\s\'"]*' . preg_quote($host, '#') . '#i', $src)) {
                        $offenders[] = $file->getFilename() . " → {$host}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "خدمة QR خارجية — السرّ يغادر الخادم:\n  " . implode("\n  ", $offenders)
        );
    }

    // ════════════════════════════════════════════════════════
    // منع إعادة استخدام الكود — verifyAndGetSlice
    // ════════════════════════════════════════════════════════

    /**
     * النجاح يُرجع الشريحة لا مجرّد true.
     *
     * القيمة المُعادة هي ما يخزّنه المستدعي ليمنع إعادة الاستخدام، فلو
     * أُعيدت شريحة خاطئة لصار المنع إمّا واسعاً (يرفض أكواداً صالحة)
     * وإمّا فارغاً (لا يمنع شيئاً).
     */
    public function testVerifyAndGetSliceReturnsTheMatchingSlice(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        $this->assertSame($now, Totp::verifyAndGetSlice($secret, $this->generateCodeAt($secret, $now)));
        $this->assertSame($now - 1, Totp::verifyAndGetSlice($secret, $this->generateCodeAt($secret, $now - 1)));
        $this->assertSame($now + 1, Totp::verifyAndGetSlice($secret, $this->generateCodeAt($secret, $now + 1)));
    }

    public function testVerifyAndGetSliceReturnsNullOnFailure(): void
    {
        $secret = Totp::generateSecret();

        $this->assertNull(Totp::verifyAndGetSlice($secret, '000000'));
        $this->assertNull(Totp::verifyAndGetSlice($secret, 'abcdef'));
    }

    /**
     * الكود المستهلَك يُرفض داخل نافذته.
     *
     * بدون هذا يبقى الكود صالحاً تسعين ثانية كاملة، فمن يلتقطه — فوق
     * كتف، أو من سجلّ، أو من شاشة مشارَكة — يعيد إرساله ويدخل.
     */
    public function testAConsumedSliceIsRejected(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);
        $code   = $this->generateCodeAt($secret, $now);

        $this->assertSame($now, Totp::verifyAndGetSlice($secret, $code, null));
        $this->assertNull(Totp::verifyAndGetSlice($secret, $code, $now));
    }

    /**
     * والأقدم من المستهلَك يُرفض أيضاً، لا المساواة وحدها.
     *
     * النافذة تحوي ثلاث شرائح. لو مُنعت المطابِقة وحدها لبقي كود
     * الشريحة السابقة صالحاً بعد استعمال اللاحقة — أي ثغرة بحجم ثلاثين
     * ثانية تُفتح بالضبط في اللحظة التي يُفترض أن يكون الباب فيها مغلقاً.
     */
    public function testASliceOlderThanTheConsumedOneIsRejected(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        $this->assertNull(Totp::verifyAndGetSlice($secret, $this->generateCodeAt($secret, $now - 1), $now));
    }

    /**
     * لكن الشريحة الأحدث من المستهلَكة تبقى مقبولة.
     *
     * الحدّ الأعلى للمنع مهمّ كالحدّ الأدنى: لو رُفض ما بعد المستهلَكة
     * لَقُفل الحساب بعد أوّل دخول ناجح.
     */
    public function testANewerSliceIsStillAccepted(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        $this->assertSame(
            $now,
            Totp::verifyAndGetSlice($secret, $this->generateCodeAt($secret, $now), $now - 1)
        );
    }

    /**
     * verifyCode القديمة تبقى غلافاً صادقاً فوق verifyAndGetSlice.
     *
     * ما زالت مستعملة في مسار تفعيل الـ2FA، فانحرافها عن الدالة التي
     * تفوّض إليها كان سيعني قاعدتَي تحقّق مختلفتين في المشروع نفسه.
     */
    public function testVerifyCodeStaysConsistentWithVerifyAndGetSlice(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        foreach ([$this->generateCodeAt($secret, $now), '000000', '', 'zzzzzz'] as $code) {
            $this->assertSame(
                Totp::verifyAndGetSlice($secret, $code) !== null,
                Totp::verifyCode($secret, $code),
                "انحراف بين الدالتين على المدخل [{$code}]"
            );
        }
    }
}
