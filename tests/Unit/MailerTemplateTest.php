<?php

namespace Tests\Unit;

use App\Core\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Mailer::template — القيم المتغيّرة تُهرَّب، ولا مستدعي يحقنها.
 *
 * كان جسم الإيميل يُبنى بالحقن المباشر، ومن بين ما يُحقَن
 * `$_SERVER['HTTP_USER_AGENT']` في إيميل تنبيه الدخول — وهي ترويسة
 * يتحكّم بها المرسِل كلياً. أي أن مهاجماً يكتب HTML في اسم متصفّحه
 * فيصل إلى صندوق بريد الأدمن كما هو.
 *
 * الاختبارات هنا على مستويين: أن الدالة تهرّب ما يُمرَّر إليها، وأن
 * **لا مستدعي** يلتفّ حولها بالحقن. الأول وحده لا يكفي — الحقن يبقى
 * ممكناً في السطر التالي الذي يكتبه أحدهم.
 */
final class MailerTemplateTest extends TestCase
{
    public function testPlaceholderValuesAreEscaped(): void
    {
        $html = Mailer::template('عنوان', 'المتصفح: {ua}', [
            'ua' => '<img src=x onerror="alert(1)">',
        ]);

        // الفحص على الوسم لا على السلسلة: نصّ «onerror=» يبقى موجوداً
        // داخل القيمة المهرَّبة وهو خامل تماماً هناك. الخطر أن يوجد
        // وسمٌ حقيقي — أي `<` غير مهرَّبة يفتحه.
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror="alert(1)">', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('onerror=&quot;alert(1)&quot;', $html);
    }

    public function testTheTitleIsEscapedToo(): void
    {
        $html = Mailer::template('<script>alert(1)</script>', 'نصّ');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * القالب الثابت يبقى HTML — وهذا هو المقصود.
     *
     * لولا ذلك لما أمكن وضع رابط في إيميل إعادة تعيين كلمة المرور.
     * القالب يُكتب في الكود ولا يأتي من الشبكة، والخطر كان في القيم لا فيه.
     */
    public function testTheStaticTemplateKeepsItsMarkup(): void
    {
        $html = Mailer::template('عنوان', '<a href="{link}">اضغط</a>', [
            'link' => 'https://example.test/reset?token=abc',
        ]);

        $this->assertStringContainsString('<a href="https://example.test/reset?token=abc">', $html);
    }

    /**
     * قيمة تحمل علامة اقتباس لا تكسر السمة التي توضع فيها.
     *
     * ENT_QUOTES لا الافتراضي: بدونها تمرّ `"` سليمةً فيُغلق المهاجم
     * سمة href ويفتح سمة أخرى — حقن داخل الوسم لا حوله.
     */
    public function testAQuoteInAValueCannotBreakOutOfAnAttribute(): void
    {
        $html = Mailer::template('عنوان', '<a href="{link}">x</a>', [
            'link' => '" onmouseover="alert(1)',
        ]);

        $this->assertStringNotContainsString('onmouseover="alert', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    public function testAnUnknownPlaceholderIsLeftAloneNotFilledWithSomethingElse(): void
    {
        $html = Mailer::template('عنوان', 'أ {one} ب {two}', ['one' => 'X']);

        $this->assertStringContainsString('أ X ب {two}', $html);
    }

    /**
     * لا مستدعي يحقن متغيّراً في جسم الإيميل.
     *
     * هذا هو الاختبار الذي يمنع عودة العطل. تهريب الدالة يحمي من يمرّ
     * بها؛ وهذا يمنع الالتفاف حولها — أي `"... {$var} ..."` في وسيط
     * الجسم، وهو بالضبط ما كان مكتوباً في سبعة مواضع.
     */
    public function testNoCallerInterpolatesVariablesIntoAnEmailBody(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2) . '/app/Controllers/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);

            // نلتقط وسيط الجسم: ما بين `Mailer::template(` وبداية
            // المصفوفة أو نهاية الاستدعاء.
            if (!preg_match_all('/Mailer::template\((.*?)\n\s*\)/s', $src, $matches)) {
                continue;
            }

            foreach ($matches[1] as $args) {
                if (preg_match('/\{\$\w+/', $args)) {
                    $offenders[] = basename($file) . ' — يحقن متغيّراً في جسم الإيميل بدل نائبة.';
                }
            }
        }

        $this->assertSame(
            [],
            array_unique($offenders),
            "حقن مباشر في جسم الإيميل:\n  " . implode("\n  ", array_unique($offenders))
        );
    }
}
