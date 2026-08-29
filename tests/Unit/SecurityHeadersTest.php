<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * الترويسات الأمنية مُعلَنة في public/.htaccess.
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا اختبار على ملف إعداد
 * ══════════════════════════════════════════════════════════════
 *
 * لأن كل حماية الاستجابة في هذا المشروع تعيش في ملف واحد لا يمرّ به
 * أي كود PHP ولا أي اختبار: CSP وnosniff وX-Frame-Options و
 * Referrer-Policy وPermissions-Policy وHSTS جميعاً في `.htaccess`.
 *
 * ومعنى ذلك أن حذف سطر واحد منه — أو الكتلة كلّها — لا يُفشل شيئاً:
 * الاختبارات خضراء، وPHPStan نظيف، والموقع يعمل. والفرق الوحيد أن
 * الزائر لم يعد محمياً.
 *
 * هذا الاختبار يحرس **ما نَعِد به**. ويرافقه في `composer smoke` فحصٌ
 * يسأل خادماً حيّاً عمّا يرسله فعلاً — ويغطّي العطل الآخر: أن يكون
 * الملف سليماً ولا يقرأه الخادم (nginx، أو Apache بلا AllowOverride،
 * أو بلا mod_headers). الاثنان لازمان: هذا يعمل في CI بلا خادم، وذاك
 * يمسك ما لا يراه أي قارئ كود.
 */
final class SecurityHeadersTest extends TestCase
{
    private string $htaccess;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 2) . '/public/.htaccess';
        $this->assertFileExists($path, 'public/.htaccess مفقود — جذر الويب بلا حماية ولا توجيه.');

        $this->htaccess = (string) file_get_contents($path);
    }

    /**
     * الاسم وحده لا يكفي: `Content-Security-Policy: default-src *`
     * ترويسة موجودة وبلا قيمة. فلكلٍّ نصٌّ يجب أن يظهر في سطرها.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requiredHeaders(): array
    {
        return [
            'nosniff'            => ['X-Content-Type-Options', 'nosniff'],
            'clickjacking'       => ['X-Frame-Options', 'SAMEORIGIN'],
            'referrer'           => ['Referrer-Policy', 'strict-origin-when-cross-origin'],
            'permissions'        => ['Permissions-Policy', 'camera=()'],
            'hsts'               => ['Strict-Transport-Security', 'max-age=31536000'],
            'csp'                => ['Content-Security-Policy', "default-src 'self'"],
        ];
    }

    #[DataProvider('requiredHeaders')]
    public function testTheHeaderIsDeclaredWithItsValue(string $header, string $needle): void
    {
        $this->assertStringContainsString(
            $header,
            $this->htaccess,
            "الترويسة {$header} غير معلَنة في public/.htaccess."
        );

        $this->assertStringContainsString(
            $needle,
            $this->htaccess,
            "الترويسة {$header} معلَنة بلا «{$needle}»."
        );
    }

    /**
     * `always` ليس تفصيلاً: بدونه لا تُضاف الترويسة إلى استجابات الخطأ.
     * وصفحة 404 أو 500 تحتاج الحماية كما تحتاجها الصفحة الناجحة —
     * وصفحات الخطأ هي بالضبط ما يُعرض فيه محتوى غير متوقَّع.
     */
    public function testEverySecurityHeaderUsesAlways(): void
    {
        preg_match_all('/^\s*Header\s+(\S+)\s+set\s+(\S+)/mi', $this->htaccess, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'لا يوجد أي `Header set` في public/.htaccess.');

        foreach ($matches as [$line, $modifier, $header]) {
            $this->assertSame(
                'always',
                strtolower($modifier),
                "الترويسة {$header} مضبوطة بلا `always` — فتغيب عن استجابات الخطأ."
            );
        }
    }

    // ════════════════════════════════════════════════════════
    // ما يجب ألّا يعود
    // ════════════════════════════════════════════════════════

    /**
     * `unsafe-inline` في script-src يُبطل CSP عملياً: أي نصّ يُحقن في
     * الصفحة يصير قابلاً للتنفيذ، وهو الهجوم الذي وُجدت السياسة له.
     *
     * والمشروع دفع ثمن إزالتها فعلاً — أربع عشرة كتلة <script> مضمّنة
     * وثلاثة وثلاثون معالج onclick خرجت من الـviews. هذا الاختبار يمنع
     * أن يُعاد ذلك الثمن هدراً بسطر واحد.
     */
    public function testScriptSrcHasNoUnsafeInline(): void
    {
        preg_match('/script-src([^;"]*)/', $this->htaccess, $m);

        $this->assertNotEmpty($m, 'لا توجد script-src في CSP.');
        $this->assertStringNotContainsString("'unsafe-inline'", $m[1]);
        $this->assertStringNotContainsString("'unsafe-eval'", $m[1]);
    }

    /**
     * `style-src` بلا unsafe-inline أيضاً — وهي آخر خطوة أُنجزت، وكلفتها
     * كانت 234 سمة style مضمّنة نُقلت إلى أدوات وأصناف. سياسة تسمح
     * بالأنماط المضمّنة لا تستطيع منع نمطٍ محقون.
     */
    public function testStyleSrcHasNoUnsafeInline(): void
    {
        preg_match('/style-src([^;"]*)/', $this->htaccess, $m);

        $this->assertNotEmpty($m, 'لا توجد style-src في CSP.');
        $this->assertStringNotContainsString("'unsafe-inline'", $m[1]);
    }

    /**
     * كل مضيف في script-src نطاقٌ يستطيع تنفيذ جافاسكربت على كل صفحة،
     * بما فيها صفحة الدفع ولوحة التحكّم. فاللائحة تُقرأ كقائمة ثقة لا
     * كقائمة إعداد، ولا يدخلها مضيف بلا سبب قائم.
     *
     * `code.jquery.com` أُسقط بعد أن ثبت أن jQuery غير مستعمَل إطلاقاً.
     * عودته تعني إمّا رجوع المكتبة، أو — وهو الأخطر — نسخ سطر CSP من
     * مصدر خارجي بلا قراءة.
     */
    public function testNoUnusedScriptHostIsAllowed(): void
    {
        preg_match('/script-src([^;"]*)/', $this->htaccess, $m);

        $this->assertStringNotContainsString('code.jquery.com', $m[1] ?? '');
    }

    /**
     * الوسوم التي تحمّل مكتبة خارجية يجب أن تحمل بصمتها.
     *
     * السماح بالمضيف في CSP لا يعني الثقة بأي ملف يرسله: حزمة مخترَقة
     * على الـCDN تمرّ من CSP بلا اعتراض. و`integrity` هو ما يجعل
     * المتصفح يرفض ملفاً تغيّر محتواه.
     *
     * الفحص على assets_helper.php لا على الـviews: الوسوم كانت مكتوبة
     * بيدها في سبعة مواضع وواحدٌ فقط منها كان يحمل بصمة — ولأنها
     * متفرّقة لم يكن أحد يرى الفرق. الآن مصدرها واحد.
     */
    public function testEveryVendorAssetCarriesAnIntegrityHash(): void
    {
        $helper = dirname(__DIR__, 2) . '/app/helpers/assets_helper.php';
        $source = (string) file_get_contents($helper);

        preg_match('/const VENDOR_ASSETS = \[(.*?)\n\];/s', $source, $m);
        $this->assertNotEmpty($m, 'VENDOR_ASSETS غير معرَّفة في assets_helper.php.');

        $urls = preg_match_all("/'url'\s*=>\s*'([^']+)'/", $m[1], $urlMatches);
        $sris = preg_match_all("/'sri'\s*=>\s*'(sha(?:256|384|512)-[^']+)'/", $m[1], $sriMatches);

        $this->assertGreaterThan(0, $urls, 'لا مكتبة خارجية معرَّفة.');
        $this->assertSame(
            $urls,
            $sris,
            'مكتبة خارجية بلا بصمة SRI — راجع VENDOR_ASSETS.'
        );

        // نطاق نسخة مفتوح (@11) يجعل البصمة مستحيلة أصلاً: المحتوى
        // يتغيّر بلا تغيّر الرابط. فالتثبيت شرطٌ للبصمة لا خياراً بجانبها.
        foreach ($urlMatches[1] as $url) {
            $this->assertMatchesRegularExpression(
                '/@\d+\.\d+\.\d+/',
                $url,
                "نسخة غير مثبَّتة تماماً في: {$url}"
            );
        }
    }
}
