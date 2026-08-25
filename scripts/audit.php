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
    if (!is_dir($dir)) return [];
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === $ext) $out[] = $f->getPathname();
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
        if (!$inBlock && !preg_match('/#\[OA\\\\/', $line)) continue;

        $count++;
        $depth += substr_count($line, '(') + substr_count($line, '[');
        $depth -= substr_count($line, ')') + substr_count($line, ']');
        if ($depth < 0) $depth = 0;
    }

    return $count;
}

/** أسطر داخل <script>...</script> أو <style>...</style> في ملف view. */
function inlineAssetLines(string $file): array
{
    $lines  = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $inJs   = false;
    $inCss  = false;
    $js = $css = 0;

    foreach ($lines as $line) {
        if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $line)) $inJs  = true;
        if (preg_match('/<style/i', $line))                       $inCss = true;
        if ($inJs)  $js++;
        if ($inCss) $css++;
        if (stripos($line, '</script>') !== false) $inJs  = false;
        if (stripos($line, '</style>')  !== false) $inCss = false;
    }

    return ['js' => $js, 'css' => $css];
}

/** أطول الدوال في ملف (تقدير بعدّ الأسطر بين تعريف ودالة التالية). */
function longestFunctions(array $files, int $limit = 10): array
{
    $found = [];

    foreach ($files as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $open  = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:public|private|protected|static|\s)*function\s+(\w+)/', $line, $m)) {
                if ($open !== null) {
                    $found[] = ['file' => $file, 'name' => $open['name'], 'lines' => $i - $open['at']];
                }
                $open = ['name' => $m[1], 'at' => $i];
            }
        }
        if ($open !== null) {
            $found[] = ['file' => $file, 'name' => $open['name'], 'lines' => count($lines) - $open['at']];
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
        if ($c > 0) $hits[$f] = $c;
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
    if ($a['js'] + $a['css'] > 0) $inlinePerFile[basename($f)] = $a;
}
uasort($inlinePerFile, fn($x, $y) => ($y['js'] + $y['css']) <=> ($x['js'] + $x['css']));

$report['issues'] = [
    'sql_in_controllers'   => grepCount($ctrl, '/->prepare\(|->query\(/'),
    'db_access_in_views'   => grepCount($views, '/Database::|->prepare\(|->query\(/'),
    'function_exists'      => grepCount(array_merge($ctrl, $layers['app/Core'], $views), '/function_exists\(/'),
    'inline_script_lines'  => $inlineJs,
    'inline_style_lines'   => $inlineCss,
    'unescaped_echo'       => grepCount($views, '/<\?=(?![^?]*(?:htmlspecialchars|json_encode|urlencode|number_format|\(int\)))[^?]*\?>/'),
    'openapi_lines_total'  => $oaTotal,
    'controllers_no_docs'  => count($undocumented),
    'dead_Model_class'     => is_file("$ROOT/app/Core/Model.php") ? 1 : 0,
    'dead_model_helper'    => grepCount($layers['app/Core'], '/function model\(/'),
    'shared_partials'      => count(filesIn("$ROOT/app/views/shared")),
];

$report['longest_controller_functions'] = array_map(
    fn($x) => ['fn' => basename($x['file']) . '::' . $x['name'], 'lines' => $x['lines']],
    longestFunctions($ctrl, 10)
);
$report['sql_hits']    = array_map('basename', array_keys(grepFiles($ctrl, '/->prepare\(|->query\(/')));
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
printf("  %s\n  %-20s %8d %10s\n\n", $bar, 'المجموع',
    array_sum(array_column($report['layers'], 'files')),
    number_format(array_sum(array_column($report['layers'], 'lines'))));

printf("  توثيق OpenAPI داخل الكنترولرز\n  %s\n", $bar);
printf("  %-42s %6s %8s %6s\n", 'الملف', 'إجمالي', 'توثيق', 'كود');
foreach ($report['openapi'] as $f => $d) {
    printf("  %-42s %6d %8d %6d\n", $f, $d['total'], $d['openapi'], $d['code']);
}
printf("  %s\n  إجمالي أسطر التوثيق: %d · كنترولرز بلا توثيق: %d\n",
    $bar, $report['issues']['openapi_lines_total'], $report['issues']['controllers_no_docs']);
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
    'dead_Model_class'    => 'app/Core/Model.php كود ميت         (الهدف 0)',
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
