<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * لا وسم يحمل السمة نفسها مرّتين.
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا هذا الاختبار موجود
 * ══════════════════════════════════════════════════════════════
 *
 * لأن HTML لا يعتبر السمة المكرّرة خطأً. المحلّل يأخذ **الأولى**
 * ويتجاهل الثانية بصمت — لا رسالة في الـconsole، ولا شيء في أدوات
 * المطوّر يقول إن سمةً أُسقطت. الصفحة تبدو سليمة والنمط غائب.
 *
 * وقد وقع ذلك في تسعة وعشرين موضعاً دفعةً واحدة، كلّها من ترحيل واحد:
 * نقل 234 سمة `style="…"` إلى أصناف `u-*` في base/utilities.css. أُضيف
 * الصنف الجديد في سمة `class` **ثانية** بدل دمجه في الموجودة، فذهب
 * كلّه هدراً.
 *
 * وثمن ذلك تجاوز الشكل. الفائز يتبع ترتيب الكتابة لا القصد:
 *
 *   · admin/login.php — `class="form-group" … class="d-none"`
 *     فبقي حقل رمز المصادقة الثنائية **ظاهراً دائماً**، وهو حقل لا
 *     يُفترض أن يُرى إلّا حين يطلبه الخادم.
 *   · product/add.php وedit.php — نفس الشكل على رسالة الخطأ الحمراء،
 *     فكانت «Please select at least one category» معروضة منذ فتح
 *     الصفحة قبل أن يخطئ أحد.
 *   · branding/_templates.php — سقط `u-thumb-preview` عن صور
 *     السلايدر، فلم يبقَ لها تحديد أبعاد إطلاقاً وصارت تمطّ حاويتها.
 *   · product_dit.php — هنا فازت `d-none` وسقطت `mt-2 p-3 rounded
 *     border`، فاللوحة مخفيّة كما يجب لكن بلا حشو ولا إطار.
 *
 * الفحص يشمل كل السمات لا `class` وحدها: `id` أو `href` أو `data-*`
 * مكرّرة تسقط بنفس الصمت.
 */
final class DuplicateAttributeTest extends TestCase
{
    // ملاحظة: لا قائمة استثناءات هنا عمداً. لا سمة في HTML يصحّ تكرارها
    // في وسم واحد، فقائمةٌ فارغة تنتظر أول استثناء ليست تصميماً بل باباً
    // مفتوحاً — ويرفضها PHPStan أصلاً كشرطٍ لا يتحقّق. أوّل حاجة حقيقية
    // إلى استثناء تُضيفه مع سببه المكتوب.

    /**
     * @param  string       $root      مجلد البحث
     * @param  string       $extension الامتداد المطلوب
     * @param  list<string> $skip      أجزاء مسار تُستبعَد
     * @return list<string>
     */
    private function filesIn(string $root, string $extension, array $skip = []): array
    {
        $base  = dirname(__DIR__, 2) . '/' . $root;
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== $extension) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            foreach ($skip as $fragment) {
                if (str_contains($path, $fragment)) {
                    continue 2;
                }
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function viewFiles(): array
    {
        return $this->filesIn('app/views', 'php');
    }

    /**
     * ملفات المصدر وحدها — `public/js/dist` ناتج بناء، وأي عطل فيه
     * انعكاسٌ لعطل في المصدر. الإبلاغ عنه مرّتين يشوّش لا أكثر.
     *
     * @return list<string>
     */
    private function scriptFiles(): array
    {
        return $this->filesIn('public/js', 'js', ['/dist/']);
    }

    /**
     * كتل PHP تُستبدل بمسافات بنفس الطول قبل التحليل.
     *
     * سببان: `<?= $x ? 'a' : 'b' ?>` قد يحوي `>` فيقطع الوسم على
     * المحلّل النصّي البسيط، و`class="<?= … ?>"` قيمة لا شكل فلا يعني
     * الاختبارَ محتواها. والاستبدال يحافظ على الأسطر فتبقى أرقامها
     * صحيحة في رسالة الفشل.
     */
    private function maskPhp(string $src): string
    {
        return (string) preg_replace_callback(
            '/<\?(?:php|=).*?\?>/s',
            static function (array $m): string {
                $newlines = substr_count($m[0], "\n");

                return str_repeat(' ', strlen($m[0]) - $newlines) . str_repeat("\n", $newlines);
            },
            $src
        );
    }

    /**
     * يفحص نصّاً واحداً ويُرجع مواضع السمات المكرّرة فيه.
     *
     * @return list<string>
     */
    private function offendersIn(string $path, string $src): array
    {
        $found = [];

        if (preg_match_all('/<[a-zA-Z][^<>]*>/s', $src, $tags, PREG_OFFSET_CAPTURE)) {
            foreach ($tags[0] as [$tag, $offset]) {
                // اسم السمة: يسبقه فراغ، ويتلوه `=`. النفي الخلفي يمنع
                // التقاط جزء من اسم مركّب مثل data-class.
                if (!preg_match_all('/(?<=\s)([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=/', $tag, $attrs)) {
                    continue;
                }

                $seen = [];
                foreach ($attrs[1] as $name) {
                    $name = strtolower($name);

                    if (isset($seen[$name])) {
                        $line = substr_count(substr($src, 0, $offset), "\n") + 1;

                        // ⚠️ التوحيد قبل القصّ لا بعده. على ويندوز يخلط
                        // RecursiveDirectoryIterator الفاصلَين — الجذر
                        // بما مُرّر إليه (`/`) والأبناء بفاصل النظام
                        // (`\`) — فقصُّ جذرٍ مكتوبٍ بفاصل واحد يفشل
                        // بصمت، وتخرج رسالة الفشل بمسار مطلق طويل.
                        $normalised = str_replace('\\', '/', $path);
                        $root       = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';

                        $found[] = str_replace($root, '', $normalised)
                            . ':' . $line . "  (السمة: {$name})";
                        break;
                    }

                    $seen[$name] = true;
                }
            }
        }

        return $found;
    }

    public function testNoTagRepeatsAnAttribute(): void
    {
        $offenders = [];

        foreach ($this->viewFiles() as $path) {
            $src = $this->maskPhp((string) file_get_contents($path));
            $offenders = [...$offenders, ...$this->offendersIn($path, $src)];
        }

        $this->assertSame(
            [],
            $offenders,
            "وسم يحمل السمة نفسها مرّتين:\n  - " . implode("\n  - ", $offenders)
            . "\n\nالمتصفّح يأخذ الأولى ويُسقط الثانية بصمت. ادمج القيمتين في سمة واحدة."
        );
    }

    /**
     * والماركب المُولَّد من جافاسكربت يخضع للقاعدة نفسها.
     *
     * ══════════════════════════════════════════════════════════
     * لماذا فحصٌ ثانٍ لا توسيعُ الأول
     * ══════════════════════════════════════════════════════════
     *
     * لأن نصف واجهة هذا المشروع تُبنى في المتصفّح: بطاقات المفضّلة،
     * قائمة الإشعارات، منتقي المنتجات في Manage Slider، منتقي
     * الكاتوجريز. وهي كلّها `innerHTML` من قوالب نصّية — فالمتصفّح
     * يحلّلها كما يحلّل ماركب الـview تماماً، ويُسقط السمة المكرّرة
     * بنفس الصمت.
     *
     * وقد وُجدت هناك فعلاً بعد تنظيف الـviews: `product-picker-row`
     * في js/admin/branding.js و`cat-delete-icon` في
     * js/admin/category-picker.js، كلتاهما تفقد صنف `u-*` الثاني.
     * أي أن حصر الفحص في app/views كان يترك نصف السطح بلا حارس.
     *
     * والقوالب النصّية تُفحص كما هي بلا تفسير: `${...}` تبقى داخل
     * قيمة السمة، وهي لا تعني الفاحص — يعنيه اسم السمة وتكراره.
     */
    public function testNoScriptBuildsMarkupWithARepeatedAttribute(): void
    {
        $offenders = [];

        foreach ($this->scriptFiles() as $path) {
            $src = (string) file_get_contents($path);

            // التعليقات تُنزَع أوّلاً: هذا المشروع يشرح أعطاله في
            // تعليقاته، ويذكر فيها الشكل المعطوب نفسه — فبدون النزع
            // يمسك الاختبارُ التوثيقَ بدل الكود.
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            $offenders = [...$offenders, ...$this->offendersIn($path, $src)];
        }

        $this->assertSame(
            [],
            $offenders,
            "قالب في جافاسكربت يبني وسماً بسمة مكرّرة:\n  - "
            . implode("\n  - ", $offenders)
            . "\n\nالمتصفّح يأخذ الأولى ويُسقط الثانية بصمت. ادمج القيمتين في سمة واحدة."
        );
    }
}
