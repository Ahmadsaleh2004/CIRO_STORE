<?php

/**
 * scripts/sync-readme-stats.php
 * يقيس أرقام المشروع من الكود ويكتبها في README — أو يفشل إن انحرفت.
 *
 * الاستخدام:
 *     php scripts/sync-readme-stats.php          ← يكتب الأرقام
 *     php scripts/sync-readme-stats.php --check  ← يفشل إن اختلفت
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا
 * ══════════════════════════════════════════════════════════════
 *
 * README كان يعلن أرقاماً مكتوبة بيدها، وكلّها انحرفت بهدوء:
 *
 *     77 اختباراً      ← الحقيقة 250
 *     104 مسار         ← 105
 *     24,199 سطر PHP   ← 24,514 في app/ وحدها
 *     28 جدولاً         ← 31
 *
 * وأخطر من الأرقام كانت جملة «CSP اليوم بوضع الإبلاغ فقط» بينما
 * السياسة مفروضة بالكامل منذ فرع csp/style-src. أي أن الواجهة الأولى
 * للمشروع صارت تصف مشروعاً أقدم من الموجود، وتقلّل من شأنه أحياناً.
 *
 * والمشروع يفرض أصلاً أن تكون مواصفة OpenAPI مولَّدة من الكود ويُفشل
 * CI إن تخلّفت. وأرقامُ README أولى بالمعاملة نفسها: ما يُكتب بيد
 * يشيخ، وما يُولَّد لا يشيخ.
 *
 * ══════════════════════════════════════════════════════════════
 * كيف تُعلَّم المواضع في README
 * ══════════════════════════════════════════════════════════════
 *
 * بتعليقات HTML — لأنها لا تظهر عند العرض:
 *
 *     <!--stats:tests-->250 اختباراً<!--/stats:tests-->
 *
 * والتعليم يبقى القيمة **مقروءة في المصدر أيضاً**، فمن يفتح README
 * في محرّر يرى رقماً لا رمزاً بديلاً. وهذا يجعل الملف صالحاً بذاته لو
 * لم يُشغَّل هذا السكربت أبداً.
 */

declare(strict_types=1);

$root   = dirname(__DIR__);
$check  = in_array('--check', $argv, true);
$readme = $root . '/README.md';

/** يعدّ أسطر مجموعة ملفات. */
function countLines(array $files): int
{
    $total = 0;
    foreach ($files as $file) {
        $total += count(file($file, FILE_IGNORE_NEW_LINES));
    }
    return $total;
}

/**
 * ملفات متتبَّعة في git بامتداد معيّن تحت مسار.
 *
 * من git لا من نظام الملفات: vendor/ وnode_modules/ وdist/ ليست
 * جزءاً من حجم المشروع، وعدّها يعطي رقماً بلا معنى. والقائمة المتتبَّعة
 * هي بالضبط ما يراه من يستنسخ المستودع.
 *
 * @return list<string>
 */
function trackedFiles(string $root, string $pattern): array
{
    $cmd = 'git -C ' . escapeshellarg($root) . ' ls-files ' . escapeshellarg($pattern);
    exec($cmd, $out, $code);

    if ($code !== 0) {
        fwrite(STDERR, "  ✗ تعذّر قراءة ملفات git — هل هذا مستودع؟\n");
        exit(1);
    }

    return array_values(array_filter(
        array_map(static fn (string $f): string => $root . '/' . $f, $out),
        'is_file'
    ));
}

// ── القياس ─────────────────────────────────────────────────────

$phpFiles = trackedFiles($root, 'app/*.php');
$jsFiles  = array_filter(
    trackedFiles($root, 'public/js/*.js'),
    static fn (string $f): bool => !str_contains(str_replace('\\', '/', $f), '/dist/')
);
$cssFiles = array_filter(
    trackedFiles($root, 'public/css/*.css'),
    static fn (string $f): bool => !str_contains(str_replace('\\', '/', $f), '/dist/')
);

// المسارات من جدول المسارات نفسه — نفس مصدر OpenApiCoverageTest.
$routes = preg_match_all(
    '/^\$r->(get|post|put|patch|delete)\(/m',
    (string) file_get_contents($root . '/public/index.php')
);

// الجداول من خطّ الأساس لا من قاعدة حيّة: قاعدة المطوّر قد تحمل
// جداول تجريبية، والمخطّط المتتبَّع هو ما ينشره المستودع.
$tables = preg_match_all(
    '/^CREATE TABLE /m',
    (string) file_get_contents($root . '/tests/fixtures/schema.sql')
);

// الاختبارات: تُعدّ دوال الاختبار لا الحالات — حالات dataProvider
// تتغيّر بتغيّر بياناتها، والعدّ الثابت أصدق لوصفٍ عامّ.
$testMethods = 0;
foreach (trackedFiles($root, 'tests/*.php') as $file) {
    $testMethods += preg_match_all('/^\s*public function test/m', (string) file_get_contents($file));
}

$operations = preg_match_all(
    '/^\s{4}(get|post|put|patch|delete):/m',
    (string) file_get_contents($root . '/public/docs/openapi.yaml')
);

$controllers = count(trackedFiles($root, 'app/Controllers/*.php'));
$models      = count(trackedFiles($root, 'app/Models/*.php'));

$stats = [
    'controllers' => number_format($controllers) . ' كنترولراً',
    'models'      => number_format($models) . ' مودلاً',
    'routes'      => number_format($routes) . ' مسار',
    'tables'      => number_format($tables) . ' جدولاً',
    'php'         => number_format(countLines($phpFiles)) . ' سطر PHP',
    'js'          => number_format(countLines($jsFiles)) . ' JS',
    'css'         => number_format(countLines($cssFiles)) . ' CSS',
    'tests'       => number_format($testMethods) . ' اختباراً',
    'operations'  => number_format($operations) . ' عملية',
];

// ── الكتابة أو الفحص ───────────────────────────────────────────

$source  = (string) file_get_contents($readme);
$updated = $source;
$drift   = [];
$missing = [];

foreach ($stats as $key => $value) {
    $pattern = '/<!--stats:' . preg_quote($key, '/') . '-->(.*?)<!--\/stats:' . preg_quote($key, '/') . '-->/su';

    if (preg_match($pattern, $updated, $m) !== 1) {
        $missing[] = $key;
        continue;
    }

    if (trim($m[1]) !== $value) {
        $drift[] = sprintf('%-12s README: %-22s الواقع: %s', $key, trim($m[1]), $value);
    }

    $updated = (string) preg_replace(
        $pattern,
        '<!--stats:' . $key . '-->' . $value . '<!--/stats:' . $key . '-->',
        $updated
    );
}

if ($missing !== []) {
    fwrite(STDERR, "  ✗ علامات غائبة في README: " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "    أضف <!--stats:<اسم>-->القيمة<!--/stats:<اسم>--> حول كل رقم.\n");
    exit(1);
}

if ($check) {
    if ($drift !== []) {
        fwrite(STDERR, "  ✗ أرقام README لا تطابق الكود:\n\n");
        foreach ($drift as $line) {
            fwrite(STDERR, '    ' . $line . "\n");
        }
        fwrite(STDERR, "\n  شغّل: composer readme:sync ثم التزم الناتج.\n\n");
        exit(1);
    }

    echo "  ✓ أرقام README تطابق الكود\n";
    exit(0);
}

if ($updated === $source) {
    echo "  ✓ لا تغيير — الأرقام محدَّثة أصلاً\n";
    exit(0);
}

file_put_contents($readme, $updated);

echo "  ✓ حُدِّثت أرقام README\n";
foreach ($drift as $line) {
    echo '    ' . $line . "\n";
}
