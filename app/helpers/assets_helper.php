<?php
/**
 * app/helpers/assets_helper.php
 * وسوم الأصول (CSS) + سكربت تهيئة الثيم.
 *
 * يُحمَّل تلقائياً من public/index.php عبر glob على مجلد helpers،
 * فلا يحتاج require يدوي.
 *
 * لماذا هذا الملف؟
 * بعد تقسيم style.css إلى ملفات صغيرة، صار لكل صفحة "حزمة" واحدة
 * (store أو admin) بدل قائمة وسوم <link> طويلة داخل الـ View.
 * الحزمة نفسها ملف @import فقط — راجع public/css/store.css.
 */

/**
 * قائمة ملفات الدخول لكل حزمة، بالترتيب.
 * admin يُحمِّل store أولاً لأن لوحة التحكم تعيد استخدام كل طبقة المتجر.
 */
function cssBundleFiles(string $bundle): array
{
    return match ($bundle) {
        'admin'      => ['css/store.css', 'css/admin.css'],
        'admin-auth' => ['css/base/tokens.css', 'css/admin/pages/login.css'],
        default      => ['css/store.css'],
    };
}

/**
 * يطبع وسوم <link> الخاصة بحزمة.
 *
 * ملاحظة حول الأداء: الحزمة ملف @import، أي طلب HTTP لكل ملف داخلي
 * بشكل متسلسل. هذا مقبول تماماً على localhost ومناسب للتطوير لأن كل
 * ملف يظهر منفصلاً في DevTools. إن احتجنا لاحقاً طلباً واحداً فقط،
 * الترقية هي دمج الملفات في public/css/dist/<bundle>.css وإرجاع
 * وسم واحد من هنا — بلا أي تغيير في الـ Views.
 */
function cssBundle(string $bundle = 'store'): string
{
    $out = '';
    foreach (cssBundleFiles($bundle) as $file) {
        $out .= '    <link rel="stylesheet" href="' . URLROOT . '/' . $file . '">' . "\n";
    }
    return $out;
}

/**
 * وسم <link> لملف CSS خاص بصفحة واحدة (يُستدعى من الـ Controllers
 * عبر extraHead).
 */
function pageCss(string ...$paths): string
{
    $out = '';
    foreach ($paths as $p) {
        $out .= '<link rel="stylesheet" href="' . URLROOT . '/css/' . ltrim($p, '/') . '">' . "\n";
    }
    return $out;
}

/**
 * سكربت صغير يُطبع داخل <head> قبل أي محتوى مرئي.
 *
 * يقرأ الثيم المحفوظ ويضبط data-bs-theme على <html> فوراً. سببان:
 *
 * 1) Bootstrap 5.3 يقرأ وضعه المظلم من data-bs-theme على <html> فقط.
 *    المشروع كان يضبط body.dark-mode وحدها، فبقيت كل مكوّنات
 *    Bootstrap (الـ pagination، القوائم المنسدلة، .text-muted،
 *    سهم الـ select …) على ألوان النهار فوق خلفية داكنة.
 *
 * 2) js/core/theme.js يعمل بعد رسم الصفحة، فكانت تظهر ومضة بيضاء
 *    عند كل تنقّل في الوضع الليلي. ضبط السمة هنا يسبق أول رسم.
 *
 * class="dark-mode" على <body> يبقى كما هو — كل CSS المشروع يعتمد
 * عليها — ويضيفه theme.js عند التحميل.
 */
function themeBootScript(): string
{
    return <<<'HTML'
    <script>
    (function () {
        try {
            var t = localStorage.getItem('theme');
            document.documentElement.setAttribute('data-bs-theme', t === 'dark' ? 'dark' : 'light');
        } catch (e) {
            document.documentElement.setAttribute('data-bs-theme', 'light');
        }
    })();
    </script>

HTML;
}
