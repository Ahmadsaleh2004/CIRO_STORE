<?php

/**
 * scripts/audit.php
 * تقرير قياس لحالة الكود — شغّله قبل وبعد كل مرحلة تنظيف لترى التقدّم
 * برقم، لا بانطباع.
 *
 * الاستخدام:
 *     php scripts/audit.php
 *     php scripts/audit.php --json     ← مخرجات آلية للمقارنة
 *
 * يقيس:
 *   - حجم كل طبقة (ملفات/أسطر)
 *   - كم من الكنترولرز هو توثيق OpenAPI وليس كوداً
 *   - استعلامات SQL مكتوبة داخل الكنترولرز (يجب أن تكون صفراً)
 *   - أسطر <script>/<style> المضمّنة داخل الـviews
 *   - وصول قاعدة البيانات من الـviews (يجب أن يكون صفراً)
 *   - أطول الدوال
 *   - مؤشرات كود ميت معروفة
 */

declare(strict_types=1);

$ROOT   = dirname(__DIR__);
$asJson = in_array('--json', $argv, true);

// ── أدوات مساعدة ───────────────────────────────────────────────────
function filesIn(string $dir, string $ext = 'php'): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === $ext) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

function lineCount(string $file): int
{
    $c = file($file, FILE_IGNORE_NEW_LINES);
    return $c === false ? 0 : count($c);
}

/** كم سطراً في الملف هو كتلة سمة OpenAPI `#[OA\...]`؟ */
function openApiLines(string $file): int
{
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $depth = 0;
    $count = 0;

    foreach ($lines as $line) {
        $inBlock = $depth > 0;
        if (!$inBlock && !preg_match('/#\[OA\\\\/', $line)) {
            continue;
        }

        $count++;
        $depth += substr_count($line, '(') + substr_count($line, '[');
        $depth -= substr_count($line, ')') + substr_count($line, ']');
        if ($depth < 0) {
            $depth = 0;
        }
    }

    return $count;
}

/**
 * يُفرِّغ محتوى تعليقات PHP من المصدر مع الحفاظ على عدد الأسطر وترقيمها.
 *
 * لماذا؟ عدّاد الأصول المضمّنة أدناه مسحٌ نصي، فكان يعدّ وسم <style> أو
 * <script> المذكور **داخل تعليق توثيقي** وسماً حقيقياً: يفتح العدّ ولا
 * يغلقه (لا وسم إغلاق في نفس التعليق)، فيُحسب باقي الملف كله أصلاً
 * مضمّناً. حدث ذلك فعلاً في المرحلة 4 — قفز عدّاد <style> من 55 إلى 96
 * بسبب ثلاثة تعليقات لا أكثر.
 *
 * التفريغ يقتصر على T_COMMENT و T_DOC_COMMENT. محتوى الـheredoc يبقى
 * محسوباً عن قصد: كتلة <style> داخل heredoc تُطبع فعلاً في الصفحة،
 * فهي أصل مضمّن حقيقي (راجع views/auth/reset-password.php).
 */
function blankPhpComments(string $src): string
{
    // الـviews مزيج HTML وPHP؛ token_get_all يتعامل معها كما يتعامل معها
    // المفسّر نفسه، فأجزاء الـHTML تصل كـT_INLINE_HTML بلا تغيير.
    // token_get_all تُرجع مصفوفة دائماً (ترمي عند الخطأ ولا تُرجع
    // false)، فالمقارنة بـfalse كانت شرطاً لا يتحقّق.
    $tokens = @token_get_all($src);
    if ($tokens === []) {
        return $src; // ملف غير قابل للتحليل — أرجعه كما هو بدل إسقاطه
    }

    $out = '';
    foreach ($tokens as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            // استبدل النص بأسطر فارغة بنفس العدد كي لا تنزاح الأرقام
            $out .= str_repeat("\n", substr_count($token[1], "\n"));
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/**
 * تقسيم إلى أسطر.
 *
 * ⚠️ لا تستعمل preg_split('/\R/') هنا. المشروع عربي، و`\R` بلا معدِّل /u
 * يعمل على البايتات ويطابق `\x85` — وهو **بايت استمرار شرعي داخل الحروف
 * العربية** بترميز UTF-8. النتيجة: النص العربي يُقطع في منتصف الحرف
 * وتُختلق أسطر غير موجودة. أعطى ذلك فرقاً قدره 5 أسطر في home.php وحده.
 */
function splitLines(string $src): array
{
    return preg_split('/\r\n|\n|\r/', $src) ?: [];
}

/**
 * يُفرِّغ تعليقات HTML مع الحفاظ على عدد الأسطر.
 *
 * وسم مذكور داخل <!-- ... --> ليس أصلاً مضمّناً: المتصفح لا ينفّذه،
 * وكتلة CSS معلَّقة ليست كتلة CSS عاملة. بلا هذا التفريغ يفتح ذكرٌ
 * عابرٌ للوسم داخل تعليق عدَّ باقي الملف كله — قفز عدّاد <style> من 55
 * إلى 337 بسبب تعليق واحد يشرح أين انتقلت الكتلة.
 */
function blankHtmlComments(string $src): string
{
    return preg_replace_callback(
        '/<!--.*?-->/s',
        static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    ) ?? $src;
}

/** أسطر داخل <script>...</script> أو <style>...</style> في ملف view. */
function inlineAssetLines(string $file): array
{
    $src   = blankHtmlComments(blankPhpComments((string)file_get_contents($file)));
    $lines = splitLines($src);
    $inJs  = false;
    $inCss = false;
    $js = $css = 0;

    foreach ($lines as $line) {
        if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $line)) {
            $inJs  = true;
        }
        if (preg_match('/<style/i', $line)) {
            $inCss = true;
        }
        if ($inJs) {
            $js++;
        }
        if ($inCss) {
            $css++;
        }
        if (stripos($line, '</script>') !== false) {
            $inJs  = false;
        }
        if (stripos($line, '</style>')  !== false) {
            $inCss = false;
        }
    }

    return ['js' => $js, 'css' => $css];
}

/**
 * أطول الدوال في ملف — بعدّ الأقواس من سطر التعريف حتى قوس الإغلاق
 * المقابل، لا بالمسافة بين تعريف والذي يليه.
 *
 * القياس بالمسافة يعطي أرقاماً كاذبة: حذف دالة قصيرة تلي دالة طويلة
 * يجعل الطويلة تبدو أطول، لأن القياس يبتلع كل ما بينهما.
 */
function longestFunctions(array $files, int $limit = 10): array
{
    $found = [];

    foreach ($files as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $count = count($lines);

        foreach ($lines as $i => $line) {
            if (!preg_match('/^\s*(?:(?:public|private|protected|static|final|abstract)\s+)*function\s+(\w+)/', $line, $m)) {
                continue;
            }

            $depth = 0;
            $seen  = false;
            $end   = null;

            for ($j = $i; $j < $count; $j++) {
                // تجاهل الأقواس داخل النصوص البسيطة على السطر
                $code   = preg_replace('/([\'"]).*?\1/', '', $lines[$j]);
                $depth += substr_count($code, '{');
                if ($depth > 0) {
                    $seen = true;
                }
                $depth -= substr_count($code, '}');
                if ($seen && $depth <= 0) {
                    $end = $j;
                    break;
                }
            }

            // دالة مجرّدة أو تعريف واجهة (بلا جسم)
            if ($end === null) {
                continue;
            }

            $found[] = ['file' => $file, 'name' => $m[1], 'lines' => $end - $i + 1];
        }
    }

    usort($found, fn($a, $b) => $b['lines'] <=> $a['lines']);
    return array_slice($found, 0, $limit);
}

function grepCount(array $files, string $pattern): int
{
    $n = 0;
    foreach ($files as $f) {
        $src = file_get_contents($f) ?: '';
        $n += preg_match_all($pattern, $src);
    }
    return $n;
}

function grepFiles(array $files, string $pattern): array
{
    $hits = [];
    foreach ($files as $f) {
        $src = file_get_contents($f) ?: '';
        $c   = preg_match_all($pattern, $src);
        if ($c > 0) {
            $hits[$f] = $c;
        }
    }
    arsort($hits);
    return $hits;
}

// ── جمع البيانات ───────────────────────────────────────────────────
$layers = [
    'app/Controllers' => filesIn("$ROOT/app/Controllers"),
    'app/Models'      => filesIn("$ROOT/app/Models"),
    'app/views'       => filesIn("$ROOT/app/views"),
    'app/Core'        => filesIn("$ROOT/app/Core"),
    'app/helpers'     => filesIn("$ROOT/app/helpers"),
    'public/js'       => filesIn("$ROOT/public/js", 'js'),
    'public/css'      => filesIn("$ROOT/public/css", 'css'),
];

$report = ['layers' => [], 'openapi' => [], 'issues' => []];

foreach ($layers as $name => $files) {
    $lines = array_sum(array_map('lineCount', $files));
    $report['layers'][$name] = ['files' => count($files), 'lines' => $lines];
}

// OpenAPI داخل الكنترولرز
$ctrl = $layers['app/Controllers'];
$oaTotal = 0;
foreach ($ctrl as $f) {
    $oa = openApiLines($f);
    $tl = lineCount($f);
    if ($oa > 0) {
        $report['openapi'][basename($f)] = ['total' => $tl, 'openapi' => $oa, 'code' => $tl - $oa];
    }
    $oaTotal += $oa;
}
$undocumented = array_values(array_map(
    'basename',
    array_filter($ctrl, fn($f) => openApiLines($f) === 0)
));

// مؤشرات المشاكل
$views = $layers['app/views'];
$inlineJs = $inlineCss = 0;
$inlinePerFile = [];
foreach ($views as $f) {
    $a = inlineAssetLines($f);
    $inlineJs  += $a['js'];
    $inlineCss += $a['css'];
    if ($a['js'] + $a['css'] > 0) {
        $inlinePerFile[basename($f)] = $a;
    }
}
uasort($inlinePerFile, fn($x, $y) => ($y['js'] + $y['css']) <=> ($x['js'] + $x['css']));

$report['issues'] = [
    // HealthController مستثنى بالاسم، لا بتخفيف النمط.
    //
    // ‏/health يجب أن يختبر **الاتصال نفسه** بـ`SELECT 1`؛ والمرور بموديل
    // يقيس الموديل لا الاتصال، ويعتمد على مخطّط قد يكون في منتصف هجرة.
    // فالاستعلام هنا ليس تسرّباً من طبقة البيانات بل هو الغرض.
    //
    // الاستثناء بالاسم مقصود: تخفيف النمط كان سيُخفي استعلاماً حقيقياً
    // في كنترولر آخر غداً. وهكذا يصير «الهدف 0» صفراً حقيقياً بدل
    // «واحد نتغاضى عنه» — ورقمٌ يُتغاضى عنه هو أوّل خطوة نحو عدّاد
    // لا يقرؤه أحد.
    'sql_in_controllers'   => grepCount(
        array_filter($ctrl, fn(string $f): bool => basename($f) !== 'HealthController.php'),
        '/->prepare\(|->query\(/'
    ),
    'db_access_in_views'   => grepCount($views, '/Database::|->prepare\(|->query\(/'),
    'function_exists'      => grepCount(array_merge($ctrl, $layers['app/Core'], $views), '/function_exists\(/'),
    'inline_script_lines'  => $inlineJs,
    'inline_style_lines'   => $inlineCss,
    // ⚠️ القائمة البيضاء وُسِّعت بعد مسحٍ يدوي كامل للمواضع الـ225.
    //
    // كانت تعرف خمس صيغ فقط، فبلّغت عن 225 موضعاً — وتتبُّع كل واحد
    // منها إلى مصدره أعطى **صفر** ثغرة. لم يكن ذلك تساهلاً في القراءة:
    //
    //   · 89 ثابتاً (URLROOT وأخواته) — ليست مدخلاً أصلاً
    //   · 47 عدداً أو عدّاداً — دوالّ تُصرّح `: int`، أو (int) على $_GET
    //   · 9 روابط من http_build_query() — وهي تُرمّز كل محرف
    //   · الباقي سلاسل CSS حرفية من match()/ثلاثي، ورموز من مصفوفات
    //     مكتوبة في الكود، وpartials مُصيَّرة عمداً بـob_get_clean()
    //
    // عدّادٌ يقول 225 وكلها سليمة لا يُقرأ بعد المرّة الثانية — وهو
    // بالضبط ما يجعل الموضع رقم 226 الخطر يمرّ. التوسيع هنا ليس تخفيفاً
    // بل تصحيح لما يقيسه.
    //
    // والنتيجة 123 لا صفر، وهذا مقصود: النمط نصّي، فلا يرى أن `$stock`
    // جاء من دالة تُصرّح `: int`، ولا أن `$statusClass` من match() بقيم
    // حرفية. إيصاله إلى صفر يحتاج تحليلاً للتدفّق لا تعبيراً نمطياً —
    // ومحاولة ذلك بتوسيع القائمة تعني إدراج أسماء متغيّرات، أي تحويل
    // المقياس إلى قائمة تجاهل. يبقى «للمراجعة» لأنه كذلك فعلاً.
    //
    // ⚠️ ولا يُوسَّع أكثر بلا مسح مثله. إضافة اسم دالة إلى القائمة تعني
    // ادّعاء أنها تهرّب — فإن لم تكن كذلك، صار العدّاد يكذب بصمت.
    'unescaped_echo'       => grepCount($views, '/<\?=(?![^?]*(?:'
        . 'htmlspecialchars|json_encode|urlencode|http_build_query|number_format'
        . '|\(int\)|\(float\)|\bcount\(|\bceil\(|categoryEmoji\('
        . '|[A-Z_]{4,}'          // ثوابت المشروع: URLROOT · SITENAME · APPROOT
        . '))[^?]*\?>/'),
    'openapi_lines_total'  => $oaTotal,
    'controllers_no_docs'  => count($undocumented),
    // ⚠️ كان هذا المقياس `is_file(app/Core/Model.php) ? 1 : 0` — أي أنه
    // يقيس **وجود الملف** كبديل عن كونه ميتاً. صحّ حين حُذف الكلاس في
    // المرحلة 1 لأنه لم يكن يرثه أحد، وكذب لحظة عاد كلاساً حيّاً ترثه
    // الموديلات الستة عشر: صار العدّاد يبلّغ عن «كود ميت» وهو يشير إلى
    // أكثر ملف مستعمَل في الطبقة.
    //
    // ما يقيسه الآن هو السؤال الأصلي بصيغته الصحيحة: كم موديلاً **لا**
    // يرث الأساس المشترك — أي كم موديلاً ما زال يفتح اتصاله بنفسه.
    'models_off_base'      => count(array_filter(
        $layers['app/Models'],
        fn(string $f): bool => !preg_match('/^class\s+\w+\s+extends\s+Model\b/m', (string) file_get_contents($f))
    )),
    'dead_model_helper'    => grepCount($layers['app/Core'], '/function model\(/'),
    'shared_partials'      => count(filesIn("$ROOT/app/views/shared")),
];

$report['longest_controller_functions'] = array_map(
    fn($x) => ['fn' => basename($x['file']) . '::' . $x['name'], 'lines' => $x['lines']],
    longestFunctions($ctrl, 10)
);
$report['sql_hits']    = array_values(array_diff(
    array_map('basename', array_keys(grepFiles($ctrl, '/->prepare\(|->query\(/'))),
    ['HealthController.php']   // مستثنى بالاسم — انظر sql_in_controllers أعلاه
));
$report['no_openapi']  = $undocumented;
$report['inline_top']  = array_slice($inlinePerFile, 0, 8, true);

// ── الإخراج ────────────────────────────────────────────────────────
if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$bar = str_repeat('─', 64);
printf("\n  تدقيق الكود — Cairo Store   %s\n  %s\n\n", date('Y-m-d H:i'), $bar);

printf("  %-20s %8s %10s\n  %s\n", 'الطبقة', 'ملفات', 'أسطر', $bar);
foreach ($report['layers'] as $name => $d) {
    printf("  %-20s %8d %10s\n", $name, $d['files'], number_format($d['lines']));
}
printf(
    "  %s\n  %-20s %8d %10s\n\n",
    $bar,
    'المجموع',
    array_sum(array_column($report['layers'], 'files')),
    number_format(array_sum(array_column($report['layers'], 'lines')))
);

printf("  توثيق OpenAPI داخل الكنترولرز\n  %s\n", $bar);
printf("  %-42s %6s %8s %6s\n", 'الملف', 'إجمالي', 'توثيق', 'كود');
foreach ($report['openapi'] as $f => $d) {
    printf("  %-42s %6d %8d %6d\n", $f, $d['total'], $d['openapi'], $d['code']);
}
printf(
    "  %s\n  إجمالي أسطر التوثيق: %d · كنترولرز بلا توثيق: %d\n",
    $bar,
    $report['issues']['openapi_lines_total'],
    $report['issues']['controllers_no_docs']
);
if ($report['no_openapi']) {
    echo '    ' . implode(', ', $report['no_openapi']) . "\n";
}
echo "\n";

printf("  مؤشرات\n  %s\n", $bar);
$labels = [
    'sql_in_controllers'  => 'استعلامات SQL في الكنترولرز        (الهدف 0)',
    'db_access_in_views'  => 'وصول قاعدة بيانات من الـviews      (الهدف 0)',
    'function_exists'     => 'حُرّاس function_exists() في ctrl/core/views (0)',
    'inline_script_lines' => 'أسطر <script> مضمّنة في الـviews',
    'inline_style_lines'  => 'أسطر <style> مضمّنة في الـviews     (الهدف 0)',
    'unescaped_echo'      => 'مواضع <?= ?> بلا هروب              (للمراجعة)',
    'controllers_no_docs' => 'كنترولرز بلا توثيق OpenAPI',
    'models_off_base'     => 'موديلات خارج الأساس المشترك        (الهدف 0)',
    'dead_model_helper'   => 'Controller::model() كود ميت        (الهدف 0)',
    'shared_partials'     => 'partials في views/shared           (الهدف >5)',
];
foreach ($labels as $key => $label) {
    printf("  %-52s %6d\n", $label, $report['issues'][$key]);
}
if ($report['sql_hits']) {
    echo "    SQL في: " . implode(', ', $report['sql_hits']) . "\n";
}
echo "\n";

printf("  أطول 10 دوال في الكنترولرز\n  %s\n", $bar);
foreach ($report['longest_controller_functions'] as $x) {
    printf("  %-52s %6d سطر\n", $x['fn'], $x['lines']);
}
echo "\n";

printf("  أكثر الـviews احتواءً على أصول مضمّنة\n  %s\n", $bar);
foreach ($report['inline_top'] as $f => $a) {
    printf("  %-44s js=%4d css=%4d\n", $f, $a['js'], $a['css']);
}
echo "\n";
