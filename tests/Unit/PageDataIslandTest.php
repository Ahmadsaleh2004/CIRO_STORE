<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * جزر بيانات الصفحة تُطبع قبل الفوتر لا داخله.
 *
 * ══════════════════════════════════════════════════════════════
 * العطل الذي وُجد هذا الاختبار له
 * ══════════════════════════════════════════════════════════════
 *
 * `js/core/page-data.js` يُحمَّل **متزامناً** أعلى الفوتر عمداً: أي
 * ملف بعده يقرأ `window`، فوجب أن يسبقهم جميعاً. وثمن ذلك أنه ينفَّذ
 * لحظة يبلغه محلّل HTML — فلا يرى من المستند إلا ما حُلِّل قبله.
 *
 * وكانت `app/views/admin/orders/details.php` تكتب:
 *
 *     $extraScripts = pageData([ 'ADMIN_ORDER_DETAILS' => [...] ]);
 *
 * و`$extraScripts` يطبعه الفوتر **بعد** وسم page-data.js. فالجزيرة
 * تولد بعد أن مسح الملفُّ المستندَ، ولا تصل `window` أبداً.
 *
 * ولأن `orders.js` يحرس نفسه بـ
 * `if (typeof window.ADMIN_ORDER_DETAILS !== 'undefined')`، لم يُرمَ
 * أي خطأ: الشرط يفشل بهدوء، فلا تُعرَّف `window.handleTakeIt`، فينقر
 * الأدمن «Take It» فلا يحدث شيء إطلاقاً. عطلٌ بلا أثر في الشاشة ولا
 * في الـconsole سوى سطر تفويض واحد — وقد شُخِّص خطأً على أنه سباق
 * تزامن في قاعدة البيانات.
 *
 * الحارس هنا نصّي لأن العطل نصّي: الموضع في المستند هو كل الفرق،
 * ولا يظهر في أي اختبار وحدة أو تكامل.
 */
final class PageDataIslandTest extends TestCase
{
    /** @return list<string> كل ملفات الـviews */
    private function viewFiles(): array
    {
        $root  = dirname(__DIR__, 2) . '/app/views';
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * `$extraScripts = pageData(...)` هو الشكل المعطوب بعينه: إسناد
     * جزيرة إلى متغيّر يطبعه الفوتر بعد قارئها.
     *
     * الجزيرة تُطبع في جسم الـview (`<?= pageData([...]) ?>` أو
     * `echo pageData([...])`)، فتسبق الفوتر بحكم الترتيب.
     */
    public function testNoViewPutsAPageDataIslandIntoExtraScripts(): void
    {
        $offenders = [];

        foreach ($this->viewFiles() as $path) {
            $src = (string) file_get_contents($path);

            // تعليقات المشروع تشرح العطل وتذكر الشكل نفسه، فتُنزع أوّلاً
            // كي لا يمسك الاختبارُ التوثيقَ بدل الكود.
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (preg_match('/\$extraScripts\s*(\.?=)\s*pageData\s*\(/', $src)) {
                $offenders[] = str_replace(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "جزيرة pageData مُسنَدة إلى \$extraScripts في:\n  - "
            . implode("\n  - ", $offenders)
            . "\n\nالفوتر يطبع \$extraScripts بعد js/core/page-data.js المتزامن، "
            . "فلا تصل البيانات إلى window وتفشل الميزة بصمت.\n"
            . 'اطبع الجزيرة في جسم الـview بدلاً من ذلك.'
        );
    }

    /**
     * وسم page-data.js يجب أن يبقى متزامناً وأعلى قائمة السكربتات في
     * كلا الفوترين. `defer` عليه يجعل أسبقيته رهينةَ ترتيب الوسوم بدل
     * أن تكون صفةً لا تُنقض.
     */
    public function testPageDataScriptIsLoadedFirstAndNotDeferred(): void
    {
        $footers = [
            'app/views/inc/footer.php',
            'app/views/admin/inc/footer.php',
        ];

        foreach ($footers as $relative) {
            $path = dirname(__DIR__, 2) . '/' . $relative;
            $src  = (string) file_get_contents($path);

            $this->assertMatchesRegularExpression(
                "/jsTag\(\s*'js\/core\/page-data\.js'\s*,\s*false\s*\)/",
                $src,
                "{$relative}: page-data.js يجب أن يُحمَّل بـ jsTag(..., false) — متزامناً."
            );

            $pageDataPos = strpos($src, 'js/core/page-data.js');
            $this->assertNotFalse($pageDataPos, "{$relative}: page-data.js غير مُدرَج.");

            foreach (['vendorJs(', 'jsBundle('] as $later) {
                $laterPos = strpos($src, $later);
                if ($laterPos === false) {
                    continue;
                }

                $this->assertLessThan(
                    $laterPos,
                    $pageDataPos,
                    "{$relative}: {$later} يسبق page-data.js — كل ما بعده يقرأ window."
                );
            }
        }
    }
}
