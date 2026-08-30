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
 *
 * حُذفت حزمة 'admin-auth' هنا: لم تكن مستدعاة من أي مكان، وكانت تناقض
 * ما تعلنه public/css/admin/pages/login.css صراحةً في رأسها — أنه ملف
 * مستقل لا يعتمد على tokens.css. صفحتا الأدمن المستقلتان تعلنان ملف
 * الـCSS في $bareCss مباشرة.
 *
 * @return list<string>
 */
function cssBundleFiles(string $bundle): array
{
    return match ($bundle) {
        'admin' => ['css/store.css', 'css/admin.css'],
        default => ['css/store.css'],
    };
}

/**
 * البيان الذي ينتجه `npm run build` — اسم الملف المبصوم لكل حزمة.
 *
 * يُقرأ مرّة واحدة لكل طلب. غيابه ليس خطأً بل يعني «لم يُبنَ بعد»،
 * فتعود cssBundle إلى سلسلة @import.
 *
 * @return array<string, string>
 */
function cssManifest(): array
{
    static $manifest = null;
    if ($manifest !== null) {
        return $manifest;
    }

    $path = ROOTPATH . '/public/css/dist/manifest.json';
    if (!is_file($path)) {
        return $manifest = [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return $manifest = is_array($decoded) ? $decoded : [];
}

/**
 * يطبع وسوم <link> الخاصة بحزمة.
 *
 * ── مساران، والاختيار بينهما آليّ ────────────────────────────
 *
 * **مبنيّ** (البيان موجود): وسم واحد لكل حزمة يشير إلى ملف مدموج
 * مضغوط مبصوم. store.css كانت 36 استيراداً و admin.css 19، والمتصفح
 * لا يعرف بوجود أيٍّ منها حتى ينزّل الملف الأب ويحلّله — فالتنزيل
 * متسلسل بطبعه لا متوازٍ.
 * القياس: 55 طلباً و112 كيلوبايت → طلبان و59 كيلوبايت.
 *
 * **غير مبنيّ** (لا بيان): سلسلة @import كما كانت. وهذا هو وضع
 * التطوير المفضَّل — كل ملف يظهر منفصلاً في DevTools، وتعديل واحد
 * منها يظهر فوراً بلا إعادة بناء.
 *
 * ولهذا لا يوجد مفتاح إعداد يختار بينهما: **وجود البيان هو الاختيار**.
 * مفتاح منفصل كان سيسمح بحالتين لا معنى لهما — مبنيّ ومعطَّل، وغير
 * مبنيّ ومفعَّل (وهذه الأخيرة صفحة بلا أي تنسيق).
 *
 * البصمة في اسم الملف تُبطل التخزين المؤقّت من تلقائها: تغيّر المحتوى
 * يغيّر الاسم، وثباته يُبقيه فيستفيد الزائر من ذاكرته.
 *
 * ⚠️ حزمة admin تُحمِّل store أولاً (راجع cssBundleFiles)، والترتيب
 * محفوظ في المسارين.
 */
function cssBundle(string $bundle = 'store'): string
{
    $manifest = cssManifest();

    $out = '';
    foreach (cssBundleFiles($bundle) as $file) {
        // 'css/store.css' → 'store'
        $key  = basename($file, '.css');
        $href = $manifest[$key] ?? $file;
        $out .= '    <link rel="stylesheet" href="' . URLROOT . '/' . $href . '">' . "\n";
    }

    return $out;
}

/**
 * يطبع وسم <script> لملف JS مع إبطال تخزين مؤقّت مبنيّ على المحتوى.
 *
 * ── العطل الذي وُجدت له ─────────────────────────────────────
 *
 * ملفات js/ كانت تُخدَّم بلا Cache-Control إطلاقاً — بـETag و
 * Last-Modified وحدهما. وهذا يترك القرار للمتصفح، وكثير منها يخزّن
 * الملف heuristically ولا يسأل عنه أصلاً.
 *
 * والأثر مقيس لا مفترض: بعد إصلاح دالة في js/core/utils.js، كان
 * الملف المخدوم يحوي الإصلاح بينما window لا تعرفه — أي أن المتصفح
 * يشغّل نسخة قديمة. تحقّقنا بـfetch(cache:'reload') فظهر الفرق.
 *
 * وهذا أخطر ما يمكن أن يحدث لإصلاح أمني: يُنشر ولا يصل.
 *
 * ── الحلّ ───────────────────────────────────────────────────
 *
 * `?v=<بصمة>` من محتوى الملف. تغيّر المحتوى يغيّر الرابط فيُبطل
 * التخزين حتماً، وثباته يُبقيه فيستفيد الزائر من ذاكرته.
 *
 * والبصمة من المحتوى لا من الوقت: الطابع الزمني يتغيّر عند كل نسخ
 * للملفات فيُبطل التخزين بلا سبب، وكل نشر يعيد تنزيل كل شيء.
 *
 * ⚠️ لماذا استعلام لا اسم مبصوم مثل حزم CSS؟ لأن ملفات JS يشير
 * بعضها إلى بعض بالاسم، ولأنها ليست ناتج بناء — تُحرَّر مباشرةً.
 * الاستعلام يعطي الإبطال نفسه بلا خطوة بناء.
 *
 * @param string $path مسار نسبي تحت public/ مثل 'js/core/utils.js'
 * @param bool   $defer
 */
/**
 * المكتبات الخارجية: النسخة والبصمة، معرَّفتان مرّة واحدة.
 *
 * ── لماذا هنا لا في الـviews ──────────────────────────────────
 *
 * كان الوسم مكتوباً بيده في **سبعة** مواضع: layout المتجر ولوحة
 * التحكّم وصفحتا head، وثلاث صفحات bare مستقلّة (admin/login و
 * store-reauth و auth/reset-password). ورقم النسخة مكرّر في كلٍّ منها.
 *
 * والتكرار هنا ليس قبحاً بل خطر أمني مباشر: إضافة `integrity` أو
 * ترقية نسخة تُطبَّق على ستّة مواضع ويُنسى السابع — وهو بالضبط ما كان
 * قائماً فعلاً قبل هذه المرحلة: `head-bare.php` وحده كان يحمل بصمة،
 * والستّة الباقية بلا شيء. ولأن الوسوم متفرّقة، لم يكن أحد يرى الفرق.
 *
 * الآن النسخة والبصمة في ثابت واحد، والترقية تعديل سطرين.
 *
 * ── تجديد البصمة عند ترقية نسخة ───────────────────────────────
 *
 *   curl -sL <url> | openssl dgst -sha384 -binary | openssl base64 -A
 *
 * ⚠️ بصمة خاطئة تعني أن المتصفح **يرفض الملف بصمت** — لا خطأ في
 * الصفحة، فقط مكتبة غائبة ومودالات لا تفتح. غيّر الاثنين معاً دائماً.
 *
 * @var array<string, array{url: string, sri: string}>
 */
const VENDOR_ASSETS = [
    'bootstrap-css' => [
        'url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
        'sri' => 'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH',
    ],
    'bootstrap-js' => [
        'url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
        'sri' => 'sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz',
    ],

    // ⚠️ كان `sweetalert2@11` — نطاقاً مفتوحاً يجلب أي إصدار 11.x
    // يُنشر غداً، أياً كان محتواه، على صفحة الدفع ولوحة التحكّم معاً.
    // الرقم هنا هو ما كان النطاق يحلّه وقت التثبيت، فلا تغيير في
    // السلوك — التغيير أن السلوك صار **معروفاً**.
    //
    // ⚠️ وهي `sweetalert2.min.js` لا `sweetalert2.all.min.js`.
    //
    // الفرق ليس في الحجم: نسخة `all` تحمل أنماطها **داخل الجافاسكربت**
    // وتحقنها وقت التشغيل في وسم <style> تنشئه بنفسها. وسياسة أمن
    // المحتوى في public/.htaccess تمنع `style-src 'unsafe-inline'` —
    // فكان المتصفّح يرفض ذلك الوسم، ويظهر كل حوار SweetAlert نصّاً
    // عارياً أسفل الصفحة بلا تصميم ولا موضع.
    //
    // وأثر ذلك تجاوز الشكل إلى الوظيفة: `orders.js` ينتظر تأكيد
    // `await Swal.fire(...)` قبل أن يأخذ الطلب، وزر التأكيد لم يكن
    // يُرى أصلاً — فبدا أن «أخذ الطلب» معطّل وهو سليم.
    //
    // النسخة العارية لا تحقن شيئاً، وأنماطها تأتي من 'sweetalert2-css'
    // أدناه كورقة خارجية يسمح بها `style-src`. **الاثنان يُدرَجان
    // معاً دائماً** — أحدهما بلا الآخر يعيد العطل نفسه.
    'sweetalert2' => [
        'url' => 'https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.min.js',
        'sri' => 'sha384-hW8ZCQHtRH+nVOAkHZ4amZvYsAtKn1ZOvMV6dNag1Rb1thWmLZMBKTRxFV0cOxiK',
    ],
    'sweetalert2-css' => [
        'url' => 'https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.min.css',
        'sri' => 'sha384-dCW5imOdApH6OwpFau8cZNKjqVbJYnCA5q+8YsMYP3XwXKsV6Jfz1u6MZLnXaBsS',
    ],

    // Chart.js — لوحة تحكّم الأدمن وحدها.
    //
    // كان الوسم مطبوعاً من AdminDashboardController نصّاً، بلا
    // `integrity` ولا `crossorigin` — أي أن أي شيء يرسله المضيف كان
    // يُنفَّذ على صفحة تعرض المبيعات وبيانات المستخدمين. ووجوده هنا
    // يعيده إلى نفس العقد الذي تخضع له بقيّة المكتبات.
    'chartjs' => [
        'url' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
        'sri' => 'sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g',
    ],
];

/**
 * وسم <script> لمكتبة خارجية، مبصوم.
 *
 * `crossorigin="anonymous"` لازم لا زينة: بدونه لا يقرأ المتصفح
 * الاستجابة عبر الأصول، فلا يستطيع التحقّق من البصمة أصلاً — ويصير
 * `integrity` سطراً بلا أثر.
 *
 * @throws \InvalidArgumentException على مفتاح غير معروف — خطأ برمجي
 *         لا حالة وقت تشغيل، والفشل الصاخب أفضل من وسم صامت ناقص.
 */
function vendorJs(string $key, bool $defer = true): string
{
    $asset = VENDOR_ASSETS[$key] ?? null;
    if ($asset === null) {
        throw new \InvalidArgumentException("Unknown vendor asset [{$key}].");
    }

    return '<script src="' . $asset['url'] . '"'
        . ' integrity="' . $asset['sri'] . '"'
        . ' crossorigin="anonymous"'
        . ($defer ? ' defer' : '') . '></script>' . "\n";
}

/** وسم <link> لورقة أنماط خارجية، مبصوم. */
function vendorCss(string $key): string
{
    $asset = VENDOR_ASSETS[$key] ?? null;
    if ($asset === null) {
        throw new \InvalidArgumentException("Unknown vendor asset [{$key}].");
    }

    return '<link rel="stylesheet" href="' . $asset['url'] . '"'
        . ' integrity="' . $asset['sri'] . '"'
        . ' crossorigin="anonymous">' . "\n";
}

function jsTag(string $path, bool $defer = true): string
{
    static $stamps = [];

    $relative = ltrim($path, '/');

    if (!isset($stamps[$relative])) {
        $disk = ROOTPATH . '/public/' . $relative;
        // غياب الملف ليس سبباً لإيقاف الصفحة: نطبع الوسم بلا بصمة
        // ويظهر 404 في الطرفية — وهو تشخيص أوضح من صفحة بيضاء.
        $stamps[$relative] = is_file($disk)
            ? substr(hash_file('sha256', $disk), 0, 10)
            : '';
    }

    $suffix = $stamps[$relative] !== '' ? '?v=' . $stamps[$relative] : '';

    return '<script src="' . URLROOT . '/' . $relative . $suffix . '"'
        . ($defer ? ' defer' : '') . '></script>' . "
";
}

/**
 * بيان حزم JS الذي ينتجه `npm run build`.
 *
 * @return array<string, string>
 */
function jsManifest(): array
{
    static $manifest = null;
    if ($manifest !== null) {
        return $manifest;
    }

    $path = ROOTPATH . '/public/js/dist/manifest.json';
    if (!is_file($path)) {
        return $manifest = [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return $manifest = is_array($decoded) ? $decoded : [];
}

/**
 * يطبع وسم <script> لحزمة مدموجة — أو وسوم الملفات المفردة إن لم تُبنَ.
 *
 * ── لماذا ───────────────────────────────────────────────────
 *
 * الصفحة الرئيسية كانت تطلب **ثمانية عشر ملف JS**. والمتصفح يسمح بستّ
 * اتصالات متزامنة لكل نطاق على HTTP/1.1، فتقف في طابور. مقيس:
 *
 *     أول ملف يبدأ:      467 ms
 *     آخر ملف ينتهي:     999 ms
 *     DOMContentLoaded: 1051 ms
 *
 * والسلايدر لا وجود له في HTML: يبنيه products-catalog.js — الرابع عشر
 * في الطابور. فيبقى مكانه فارغاً أكثر من ثانية بعد ظهور الصفحة.
 *
 * ── المساران ───────────────────────────────────────────────
 *
 * **مبنيّ**: وسم واحد. و**غير مبنيّ**: الملفات مفردة كما كانت — وهو
 * وضع التطوير المفضَّل، إذ يظهر كل ملف منفصلاً في DevTools ويظهر
 * تعديله فوراً بلا إعادة بناء.
 *
 * ووجود البيان هو الاختيار، تماماً كما في cssBundle.
 *
 * ⚠️ الترتيب داخل الحزمة هو ترتيب هذه القوائم في build/build-js.mjs،
 * وهو ترتيب الـfooter حرفاً بحرف. الملفات تتشارك النطاق العام ويعتمد
 * اللاحق على ما عرّفه السابق — فأي إعادة ترتيب تكسره بصمت.
 *
 * @param string       $bundle اسم الحزمة: store | admin | store-auth
 * @param list<string> $fallback مسارات الملفات المفردة عند غياب البناء
 * @param bool         $defer
 */
function jsBundle(string $bundle, array $fallback, bool $defer = true): string
{
    $manifest = jsManifest();

    if (isset($manifest[$bundle])) {
        $path = $manifest[$bundle];
        // البصمة في الاسم تُبطل التخزين المؤقّت، فلا حاجة لـ?v=
        return '<script src="' . URLROOT . '/' . $path . '"'
            . ($defer ? ' defer' : '') . '></script>' . "
";
    }

    $out = '';
    foreach ($fallback as $file) {
        $out .= jsTag($file, $defer);
    }

    return $out;
}

/**
 * يطبع جزيرة بيانات JSON تُنقَل إلى النطاق العام.
 *
 * ── لماذا ────────────────────────────────────────────────────
 *
 * أربع عشرة صفحة كانت تمرّر بياناتها إلى JS بكتلة <script> مضمّنة:
 *
 *     <script>window.dbProducts = <?= json_encode(...) ?>;</script>
 *
 * وهي **كتلة قابلة للتنفيذ**، فأي سياسة CSP جادّة تحجبها. ولهذا بقيت
 * سياسة المشروع بوضع الإبلاغ فقط: تفعيلها كان يكسر الصفحات الأربع عشرة.
 *
 * ── الحلّ ───────────────────────────────────────────────────
 *
 * `<script type="application/json">` **ليست كتلة تنفيذ**. المتصفح لا
 * ينفّذها، وCSP لا يعنيها `script-src` أصلاً. فتصير البيانات عنصراً
 * في الصفحة يقرأه js/core/page-data.js وينسخه إلى window.
 *
 * ── ما لم يتغيّر ────────────────────────────────────────────
 *
 * **عقد `window.X` كما هو حرفياً.** الأسماء نفسها والقيم نفسها، فلا
 * يتغيّر سطر واحد في ملفات JS الأربعة والثلاثين. البيانات تصل بطريق
 * آخر، ومن يقرأها لا يعلم بالفرق.
 *
 * ⚠️ ثلاثة قيود على المحتوى:
 *
 *   1. JSON_HEX_TAG لازم: قيمة تحوي `</script>` كانت ستُنهي الوسم
 *      وتفتح باب حقن. الترميز يحوّل < و > إلى < و>.
 *   2. البيانات تُقرأ عند DOMContentLoaded، لذا يجب أن يُطبع هذا
 *      الوسم **قبل** أي سكربت يقرأ window، وهو كذلك: الـviews تُطبع
 *      قبل الـfooter.
 *   3. القيم تمرّ كما هي إلى JSON — لا تضع فيها ما لا يجوز أن يراه
 *      الزائر. هذا لم يتغيّر عن السلوك السابق.
 *
 * @param array<string, mixed> $data أزواج اسم/قيمة تُنسخ إلى window
 */
function pageData(array $data): string
{
    if ($data === []) {
        return '';
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
    );

    if ($json === false) {
        error_log('[Cairo Store] pageData: json_encode فشل — ' . json_last_error_msg());
        return '';
    }

    return '<script type="application/json" data-page-data>' . $json . '</script>' . "
";
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

/**
 * publicFileToDelete(string $relPath): ?string
 *
 * يحوّل مساراً نسبياً مخزَّناً في قاعدة البيانات (مثل images/x.jpg) إلى
 * مسار مطلق على القرص **بشرط أن يبقى داخل public/**، ويرجع null إن خرج
 * عنه أو لم يوجد.
 *
 * لماذا وُجدت: مواضع حذف صور المنتجات كانت تبني المسار بـ
 * `ROOTPATH . '/public/' . ltrim($p, '/')`. و`ltrim` تزيل الشرطات
 * البادئة **ولا تمنع `..`** — فقيمة مثل `../../.env` كانت تخرج من
 * المجلد. مصادر القيم اليوم آمنة (`uploadVariantImage` يولّد الاسم
 * كاملاً على الخادم: product_<time>_<hex>.<ext>)، فلا ثغرة قائمة —
 * لكن الحارس يجب أن يكون في الدالة لا في عادة المستدعي، لأن أي مسار
 * كتابة جديد إلى العمود يصير ثغرة صامتة.
 *
 * الاحتواء بـrealpath لا بفحص نصّي: realpath يفكّ `..` والوصلات الرمزية
 * معاً، والمقارنة على الناتج المُفكَّك هي وحدها التي لا تُخدع.
 *
 * @param  string $relPath المسار كما هو مخزَّن (نسبي لـpublic/)
 * @return string|null المسار المطلق الصالح للحذف، أو null إن رُفض
 */
function publicFileToDelete(string $relPath): ?string
{
    $relPath = trim($relPath);
    if ($relPath === '') {
        return null;
    }

    $publicRoot = realpath(ROOTPATH . '/public');
    if ($publicRoot === false) {
        return null;
    }

    $candidate = realpath($publicRoot . DIRECTORY_SEPARATOR . ltrim($relPath, '/\\'));
    if ($candidate === false || !is_file($candidate)) {
        return null;   // غير موجود، أو مجلد
    }

    // الفاصل في النهاية مقصود: بدونه يمرّ مجلد شقيق اسمه بادئة
    // (public_backup مثلاً) على فحص str_starts_with.
    if (!str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)) {
        error_log('[Cairo Store] رُفض حذف ملف خارج public/: ' . $relPath);
        return null;
    }

    return $candidate;
}
