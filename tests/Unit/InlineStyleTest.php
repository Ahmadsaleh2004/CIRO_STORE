<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * صفر نمط مضمّن في الـviews — وهو ما يجعل الـCSP صارمة.
 *
 * `style-src` حملت 'unsafe-inline' منذ أوّل يوم لأن الـviews حملت 234
 * سمة `style="…"`. وسياسة تسمح بالأنماط المضمّنة لا تستطيع أن تمنع
 * نمطاً محقوناً — فالسمة كان لا بدّ أن تختفي قبل أن يُشدَّد التوجيه.
 *
 * الاختبار هنا هو ما يبقي التوجيه صادقاً. سمةٌ واحدة تعود إلى view
 * تجعل الصفحة تُعرَض بلا ذلك النمط — وهو عطل بصري صامت لا يظهر إلا
 * على النشر، لأن الـCSP لا تُفرَض عادةً على خادم التطوير المحلي.
 *
 * ⚠️ الفحص على الـviews وحدها. ملفات CSS مكانها الطبيعي للأنماط،
 * وسمة style في نصّ إيميل (Mailer::template) لا يحكمها CSP إطلاقاً —
 * عميل البريد ليس المتصفح.
 */
final class InlineStyleTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function viewFiles(): array
    {
        $root  = dirname(__DIR__, 2) . '/app/views';
        $files = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function testNoViewCarriesAnInlineStyleAttribute(): void
    {
        $offenders = [];

        foreach (self::viewFiles() as $path) {
            $lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [];

            foreach ($lines as $n => $line) {
                if (preg_match('/\sstyle\s*=\s*["\']/i', $line)) {
                    $offenders[] = sprintf(
                        '%s:%d  %s',
                        basename(dirname($path)) . '/' . basename($path),
                        $n + 1,
                        trim(mb_substr($line, 0, 90))
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "نمط مضمّن في view — يكسر style-src الصارمة:\n  " . implode("\n  ", $offenders)
        );
    }

    public function testNoViewCarriesAnInlineStyleBlock(): void
    {
        $offenders = [];

        foreach (self::viewFiles() as $path) {
            // التعليقات تُفرَّغ أوّلاً — **بصنفيها**. وسمٌ *مذكور* داخل
            // تعليق ليس كتلة عاملة، بل هو الشرح الذي يمنع إعادتها.
            // عدّه مخالفةً يعاقب التوثيق.
            // (scripts/audit.php تعلّمت القاعدة نفسها حين قفز عدّادها من
            // 55 إلى 337 بسبب تعليق واحد.)
            //
            // ⚠️ تعليقات PHP أُضيفت إلى التفريغ بعد أن تحوّلت تعليقات
            // الـviews من صيغة HTML إلى صيغة PHP كي لا تُشحن إلى
            // الزائر. والاختبار فشل فوراً على checkout.php — وهو
            // يحمل السطر الذي يشرح أن كتلة <style> **نُقلت** إلى ملف.
            //
            // والفشل كان صحيحاً بحرفه: القاعدة تعرف صنف تعليق واحداً.
            // فالإصلاح في القاعدة لا في الـview — تعديل القالب ليتجنّب
            // ذكر الوسم كان سيحذف التوثيق إرضاءً لاختبار.
            $src = preg_replace(
                ['/<!--.*?-->/s', '#/\*.*?\*/#s', '#//[^\n?]*#'],
                '',
                (string) file_get_contents($path)
            ) ?? '';

            if (preg_match('/<style[\s>]/i', $src)) {
                $offenders[] = basename(dirname($path)) . '/' . basename($path);
            }
        }

        $this->assertSame([], $offenders, "كتلة <style> في view:\n  " . implode("\n  ", $offenders));
    }

    /**
     * ولا ملف JS يبني ترميزاً يحمل نمطاً مضمّناً.
     *
     * ⚠️ هذا الفحص أضافته الـCSP لا المراجعة. بعد تنظيف الـviews كانت
     * الحزمة خضراء والمتصفح يبلّغ عن 27 نمطاً محجوباً في كل صفحة:
     * ثمانية ملفات JS كانت تبني HTML كنصوص فيها `style="…"` ثم تحقنه.
     * والترميز المحقون يخضع لـstyle-src تماماً كالمُصيَّر على الخادم.
     *
     * الدرس المُشفَّر هنا: «صفر نمط مضمّن» سؤالٌ عن **الصفحة النهائية**
     * لا عن ملفات الـviews. اختبار يفحص المصدر الأوّل وحده يعلن النصر
     * بينما يحجب المتصفح ثلاثين نمطاً.
     */
    public function testNoScriptBuildsMarkupWithAnInlineStyle(): void
    {
        $offenders = [];
        $jsRoot    = dirname(__DIR__, 2) . '/public/js';

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($jsRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }

            // dist/ ناتج بناء — يُفحص مصدره لا هو.
            if (str_contains(str_replace('\\', '/', $file->getPathname()), '/dist/')) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            // التعليقات تُفرَّغ: variant-swatches.js يشرح النمط الذي
            // استبدله، وهو الشرح الذي يمنع عودته.
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (preg_match('/style\s*=\s*\\\\?["\']/', $src)) {
                $offenders[] = basename($file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "ملف JS يحقن ترميزاً بنمط مضمّن — تحجبه style-src:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * والتوجيه نفسه صارم فعلاً.
     *
     * بلا هذا الفحص يبقى الاختباران أعلاه يحرسان الـviews بينما تكون
     * السياسة قد رُخِّيت في .htaccess — فيُحرَس الباب ويُترك الشبّاك.
     */
    public function testTheCspItselfForbidsInlineStyles(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');

        $this->assertMatchesRegularExpression(
            '/Header always set Content-Security-Policy/',
            $htaccess,
            'الـCSP غير مفروضة إطلاقاً.'
        );

        if (!preg_match('/style-src([^;"]*)/', $htaccess, $m)) {
            $this->fail('لا توجيه style-src في الـCSP.');
        }

        $this->assertStringNotContainsString(
            'unsafe-inline',
            $m[1],
            "style-src تسمح بالأنماط المضمّنة — التوجيه لا يحرس شيئاً:\n  style-src{$m[1]}"
        );
    }

    /**
     * وscript-src كذلك — فحصٌ مجاور يحرس ما أُنجز في مرحلة سابقة.
     */
    public function testTheCspForbidsInlineScriptsToo(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');

        if (!preg_match('/script-src([^;"]*)/', $htaccess, $m)) {
            $this->fail('لا توجيه script-src في الـCSP.');
        }

        $this->assertStringNotContainsString('unsafe-inline', $m[1]);
        $this->assertStringNotContainsString('unsafe-eval', $m[1]);
    }
}
