<?php

/**
 * scripts/smoke-test.php
 * فحص دخان (smoke test) لكل راوتات GET في المشروع.
 *
 * الاستخدام:
 *     php scripts/smoke-test.php               ← الفحص المعتاد بعد أي تعديل
 *     php scripts/smoke-test.php --lint-all    ← مسح شامل (أبطأ، للتحقق النهائي)
 *     php scripts/smoke-test.php --verbose     ← اطبع كل راوت لا الفاشل فقط
 *     php scripts/smoke-test.php --skip-http   ← الصياغة فقط (بلا حاجة لـApache)
 *     php scripts/smoke-test.php --base=http://localhost/STORE/public
 *
 * ماذا يفعل؟
 *   1. يقرأ راوتات GET من public/index.php مباشرة، فلا يصير قديماً
 *      عند إضافة راوت جديد.
 *   2. يضرب كل راوت ويتحقق من:
 *        - كود HTTP ضمن المتوقّع (200 أو 302 لصفحات تتطلب جلسة)
 *        - لا يوجد Fatal error / Warning / Notice / Deprecated في المخرجات
 *        - لا يوجد "View file ... not found" أو "Model class ... not found"
 *        - الصفحة ليست فارغة عند كود 200، والـHTML مكتمل
 *   3. يفحص صياغة ملفات PHP بـ php -l — هذا يغطي مسارات POST التي لا
 *      يستطيع الفحص عبر HTTP الوصول إليها. افتراضياً يفحص المعدَّل منذ
 *      آخر commit فقط (سريع)؛ --lint-all يفحص المشروع كاملاً.
 *
 * يُرجِع exit code 1 عند أي فشل، فيصلح للربط بـ git hook أو CI.
 *
 * لماذا لا phpunit؟ المشروع بلا أي بنية اختبار، والهدف هنا شبكة أمان
 * تعمل بأمر واحد بدون إضافة تبعيات — لا بديل عن اختبارات حقيقية لاحقاً.
 */

declare(strict_types=1);

// ── إعدادات ────────────────────────────────────────────────────────
$ROOT = dirname(__DIR__);
$opts = getopt('', ['base::', 'verbose', 'skip-lint', 'skip-http', 'lint-all']);

$BASE     = rtrim($opts['base'] ?? 'http://localhost/STORE/public', '/');
$VERBOSE  = isset($opts['verbose']);
$SKIPLINT = isset($opts['skip-lint']);
$SKIPHTTP = isset($opts['skip-http']);
$LINTALL  = isset($opts['lint-all']);
$TIMEOUT  = 15;


/**
 * راوتات تعتمد على خدمة خارجية (OAuth) أو على توكن في الرابط.
 * التحويل (302) أو الخطأ المتوقّع منها ليس عطلاً.
 */
const EXTERNAL_ROUTES = [
    '/auth/google',
    '/auth/google/callback',
    '/auth/verify',
    '/auth/reset',
];

/** بارامترات نموذجية لراوتات تحتاجها كي تعرض شيئاً حقيقياً. */
const ROUTE_PARAMS = [
    '/product'               => '?id=20',
    '/admin/users/details'   => '?id=1',
    '/admin/orders/details'  => '?id=1',
    '/admin/admins/details'  => '?id=1',
    '/admin/products/edit'   => '?id=20',
];

// ── ألوان الطرفية ──────────────────────────────────────────────────
$isTty = function_exists('stream_isatty') && @stream_isatty(STDOUT);
function paint(string $s, string $color): string
{
    global $isTty;
    if (!$isTty) {
        return $s;
    }
    $codes = ['red' => 31, 'green' => 32, 'yellow' => 33, 'grey' => 90, 'bold' => 1];
    return "\033[" . ($codes[$color] ?? 0) . "m{$s}\033[0m";
}

// ── 1. استخراج راوتات GET من index.php ─────────────────────────────
function extractGetRoutes(string $indexFile): array
{
    $src = file_get_contents($indexFile);
    if ($src === false) {
        fwrite(STDERR, "تعذّرت قراءة {$indexFile}\n");
        exit(1);
    }
    preg_match_all("/\\\$r->get\(\s*'([^']+)'/", $src, $m);
    // preg_match_all تملأ $m[1] دائماً (ولو مصفوفة فارغة)، فـ?? هنا
    // كانت تعد بحماية لا تحتاجها.
    return array_values(array_unique($m[1]));
}

// ── 2. طلب HTTP واحد ───────────────────────────────────────────────
function fetch(string $url, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // نريد رؤية الـ302 نفسه
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'STORE-smoke-test/1.0',
        CURLOPT_HEADER         => false,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'body'  => $body === false ? '' : $body,
        'code'  => $code,
        'type'  => $type,
        'error' => $err,
    ];
}

// ── 3. فحص جسم الاستجابة ───────────────────────────────────────────
/** @return string[] قائمة المشاكل المكتشفة (فارغة = سليم) */
function inspectBody(string $body, string $route, int $code, string $contentType = ''): array
{
    $problems = [];

    $phpErrors = [
        'Fatal error'        => 'Fatal error',
        'Parse error'        => 'Parse error',
        'Warning:'           => 'Warning',
        'Notice:'            => 'Notice',
        'Deprecated:'        => 'Deprecated',
        'Uncaught'           => 'استثناء غير مُلتقَط',
        'View file ['        => 'view مفقود',
        'Model class ['      => 'موديل مفقود',
        'SQLSTATE'           => 'خطأ SQL ظاهر للمستخدم',
    ];

    foreach ($phpErrors as $needle => $label) {
        if (str_contains($body, $needle)) {
            $problems[] = $label;
        }
    }

    if ($code === 200 && trim($body) === '') {
        $problems[] = 'صفحة فارغة';
    }

    // فحص اكتمال HTML يُطبَّق على استجابات HTML وحدها.
    //
    // ⚠️ التمييز من ترويسة Content-Type لا من قائمة يدوية.
    //
    // كانت هنا NON_HTML_ROUTES: قائمة مكتوبة بأسماء ثمانية مسارات
    // تُرجع JSON أو ملفاً. وهي تتقادم بالضرورة — أول نقطة JSON تُضاف
    // بعدها تفشل بـ«HTML غير مكتمل»، وهي رسالة تصف الفاحص لا المفحوص.
    // وقع ذلك فعلاً عند إضافة /health.
    //
    // والاشتقاق من الترويسة يجعل القائمة غير لازمة: النقطة تعلن نوعها
    // بنفسها، والفاحص يقرأ ما أعلنته.
    $looksHtml = $contentType === ''
        || str_contains(strtolower($contentType), 'text/html');

    if ($code === 200 && $looksHtml && !str_contains($body, '</html>')) {
        $problems[] = 'HTML غير مكتمل (لا </html>)';
    }

    return $problems;
}

// ── 4. تشغيل فحص HTTP ──────────────────────────────────────────────
function runHttpChecks(array $routes, string $base, int $timeout, bool $verbose): array
{
    $results = [];

    foreach ($routes as $route) {
        $url = $base . $route . (ROUTE_PARAMS[$route] ?? '');
        $r   = fetch($url, $timeout);

        $problems = [];

        if ($r['error'] !== '') {
            $problems[] = 'curl: ' . $r['error'];
        } elseif (!in_array($r['code'], [200, 302, 301], true)) {
            // 302 مقبول: صفحات الأدمن تحوّل لتسجيل الدخول بدون جلسة
            $problems[] = 'HTTP ' . $r['code'];
        } else {
            $problems = inspectBody($r['body'], $route, $r['code'], $r['type'] ?? '');
        }

        // الراوتات الخارجية: التحويل متوقّع، لا نعدّه عطلاً
        if (in_array($route, EXTERNAL_ROUTES, true) && $r['code'] === 302) {
            $problems = [];
        }

        $results[] = [
            'route'    => $route,
            'code'     => $r['code'],
            'bytes'    => strlen($r['body']),
            'problems' => $problems,
        ];
    }

    return $results;
}

// ── 5. فحص الصياغة (يغطي مسارات POST) ──────────────────────────────
/**
 * كل ملفات PHP في المشروع، باستثناء التبعيات والنسخ الاحتياطية.
 *
 * @return string[]
 */
function allPhpFiles(string $root): array
{
    $skipDirs = ['vendor', 'node_modules', '.git', 'css-backup-2026-08-24'];
    $files    = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $f) use ($skipDirs) {
                if ($f->isDir()) {
                    return !in_array($f->getFilename(), $skipDirs, true);
                }
                return $f->getExtension() === 'php';
            }
        )
    );

    foreach ($it as $file) {
        $files[] = $file->getPathname();
    }

    return $files;
}

/**
 * ملفات PHP المعدَّلة منذ آخر commit (بما فيها غير المتتبَّعة).
 * هذا هو الوضع الافتراضي: `php -l` عملية منفصلة لكل ملف، وعلى Windows
 * فحص المشروع كاملاً يستغرق دقائق — بينما ما عدّلته للتو يُفحص فوراً.
 * استخدم --lint-all للمسح الشامل.
 *
 * @return string[]|null null إذا لم يكن المجلد مستودع git
 */
function changedPhpFiles(string $root): ?array
{
    $out = [];
    $rc  = 0;
    // سكربت تطوير لا يُخدَّم عبر الويب. $root ثابت يمرّ بـescapeshellarg،
    // وبقية الأمر حرفية في هذا الملف — لا مدخل مستخدم في أي موضع.
    // nosemgrep: php.lang.security.exec-use.exec-use
    exec('git -C ' . escapeshellarg($root) . ' rev-parse --is-inside-work-tree 2>&1', $out, $rc);
    if ($rc !== 0) {
        return null;
    }

    $files = [];
    foreach (['diff --name-only HEAD', 'ls-files --others --exclude-standard'] as $cmd) {
        $lines = [];
        // $cmd يأتي من مصفوفة حرفية في السطر أعلاه، لا من أي مدخل.
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec('git -C ' . escapeshellarg($root) . ' ' . $cmd . ' 2>&1', $lines);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_ends_with($line, '.php')) {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $line);
            if (is_file($path)) {
                $files[$path] = true;
            }
        }
    }

    return array_keys($files);
}

function runLintChecks(array $files): array
{
    $failures = [];

    foreach ($files as $path) {
        $out = [];
        $rc  = 0;
        // $path من مخرَج git داخل هذا السكربت، ويمرّ بـescapeshellarg.
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            $failures[] = ['file' => $path, 'msg' => implode(' ', $out)];
        }
    }

    return ['checked' => count($files), 'failures' => $failures];
}

// ── تشغيل ──────────────────────────────────────────────────────────
$exitCode = 0;

echo paint("\n  فحص الدخان — Cairo Store\n", 'bold');
echo paint("  الأساس: {$BASE}\n\n", 'grey');

if (!$SKIPHTTP) {
    $routes = extractGetRoutes($ROOT . '/public/index.php');
    echo paint(sprintf("  ── HTTP: %d راوت GET ──\n", count($routes)), 'bold');

    $results = runHttpChecks($routes, $BASE, $TIMEOUT, $VERBOSE);
    $failed  = 0;

    foreach ($results as $r) {
        $ok = empty($r['problems']);
        if (!$ok) {
            $failed++;
        }

        if (!$ok || $VERBOSE) {
            printf(
                "  %s  %-40s %s %s%s\n",
                $ok ? paint('✓', 'green') : paint('✗', 'red'),
                $r['route'],
                paint(str_pad((string)$r['code'], 3), $ok ? 'grey' : 'red'),
                paint(str_pad(number_format($r['bytes']) . 'B', 10, ' ', STR_PAD_LEFT), 'grey'),
                $ok ? '' : '  ' . paint(implode(' · ', $r['problems']), 'red')
            );
        }
    }

    $passed = count($results) - $failed;
    echo $failed === 0
        ? paint("  ✓ نجح {$passed}/" . count($results) . "\n\n", 'green')
        : paint("  ✗ فشل {$failed} من " . count($results) . "\n\n", 'red');

    if ($failed > 0) {
        $exitCode = 1;
    }
}

if (!$SKIPLINT) {
    if ($LINTALL) {
        $files = allPhpFiles($ROOT);
        $scope = 'المشروع كاملاً';
    } else {
        $files = changedPhpFiles($ROOT);
        if ($files === null) {
            $files = allPhpFiles($ROOT);
            $scope = 'المشروع كاملاً (لا يوجد git)';
        } else {
            $scope = 'المعدَّل منذ آخر commit';
        }
    }

    echo paint("  ── صياغة PHP ({$scope}) ──\n", 'bold');

    if ($files === []) {
        echo paint("  ✓ لا ملفات معدَّلة\n\n", 'green');
    } else {
        $lint = runLintChecks($files);

        foreach ($lint['failures'] as $f) {
            echo '  ' . paint('✗', 'red') . '  ' . str_replace($ROOT . DIRECTORY_SEPARATOR, '', $f['file'])
                . "\n     " . paint($f['msg'], 'red') . "\n";
        }

        echo empty($lint['failures'])
            ? paint("  ✓ {$lint['checked']} ملف سليم\n\n", 'green')
            : paint('  ✗ ' . count($lint['failures']) . " ملف فيه خطأ صياغة\n\n", 'red');

        if (!empty($lint['failures'])) {
            $exitCode = 1;
        }
    }
}

echo $exitCode === 0
    ? paint("  النتيجة: أخضر\n\n", 'green')
    : paint("  النتيجة: فشل\n\n", 'red');

exit($exitCode);
