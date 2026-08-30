<?php

/**
 * scripts/smoke-test.php
 * A smoke test over every GET route in the project.
 *
 * Usage:
 *     php scripts/smoke-test.php               ← the usual check after any edit
 *     php scripts/smoke-test.php --lint-all    ← a full sweep (slower, for a final check)
 *     php scripts/smoke-test.php --verbose     ← print every route, not only the failures
 *     php scripts/smoke-test.php --skip-http   ← syntax only (no Apache needed)
 *     php scripts/smoke-test.php --base=http://localhost/STORE/public
 *
 * What does it do?
 *   1. It reads the GET routes straight out of public/index.php, so it does not go stale
 *      when a new route is added.
 *   2. It hits every route and checks that:
 *        - the HTTP code is among those expected (200, or 302 for pages needing a session)
 *        - there is no Fatal error / Warning / Notice / Deprecated in the output
 *        - there is no "View file ... not found" or "Model class ... not found"
 *        - the page is not empty on a 200, and the HTML is complete
 *   3. It checks the PHP files' syntax with php -l — which covers the POST paths the HTTP
 *      check cannot reach. By default it checks only what changed since the last commit
 *      (fast); --lint-all checks the whole project.
 *
 * It returns exit code 1 on any failure, so it suits a git hook or CI.
 *
 * Why not phpunit? The project had no test infrastructure at all when this was written, and
 * the goal here was a safety net that runs with one command and adds no dependencies — not
 * a substitute for real tests later.
 */

declare(strict_types=1);

// ── Settings ───────────────────────────────────────────────────────
$ROOT = dirname(__DIR__);
$opts = getopt('', ['base::', 'verbose', 'skip-lint', 'skip-http', 'lint-all']);

$BASE     = rtrim($opts['base'] ?? 'http://localhost/STORE/public', '/');
$VERBOSE  = isset($opts['verbose']);
$SKIPLINT = isset($opts['skip-lint']);
$SKIPHTTP = isset($opts['skip-http']);
$LINTALL  = isset($opts['lint-all']);
$TIMEOUT  = 15;


/**
 * Routes that depend on an external service (OAuth) or on a token in the link.
 * A redirect (302) or the error expected from them is not a fault.
 */
const EXTERNAL_ROUTES = [
    '/auth/google',
    '/auth/google/callback',
    '/auth/verify',
    '/auth/reset',
];

/** Sample parameters for the routes that need them to display anything real. */
const ROUTE_PARAMS = [
    '/product'               => '?id=20',
    '/admin/users/details'   => '?id=1',
    '/admin/orders/details'  => '?id=1',
    '/admin/admins/details'  => '?id=1',
    '/admin/products/edit'   => '?id=20',
];

// ── Terminal colours ───────────────────────────────────────────────
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

// ── 1. Extracting the GET routes from index.php ────────────────────
/**
 * @return list<string>
 */
function extractGetRoutes(string $indexFile): array
{
    $src = file_get_contents($indexFile);
    if ($src === false) {
        fwrite(STDERR, "{$indexFile} could not be read\n");
        exit(1);
    }
    preg_match_all("/\\\$r->get\(\s*'([^']+)'/", $src, $m);
    // preg_match_all always fills $m[1] (with an empty array at worst), so a ?? here was
    // promising a protection nothing needed.
    return array_values(array_unique($m[1]));
}

// ── 2. A single HTTP request ───────────────────────────────────────
/**
 * @return array{body: string, code: int, type: string, error: string}
 */
function fetch(string $url, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // We want to see the 302 itself
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

// ── 3. Inspecting the response body ────────────────────────────────
/** @return string[] the problems found (empty = sound) */
function inspectBody(string $body, string $route, int $code, string $contentType = ''): array
{
    $problems = [];

    $phpErrors = [
        'Fatal error'        => 'Fatal error',
        'Parse error'        => 'Parse error',
        'Warning:'           => 'Warning',
        'Notice:'            => 'Notice',
        'Deprecated:'        => 'Deprecated',
        'Uncaught'           => 'an uncaught exception',
        'View file ['        => 'a missing view',
        'Model class ['      => 'a missing model',
        'SQLSTATE'           => 'an SQL error visible to the user',
    ];

    foreach ($phpErrors as $needle => $label) {
        if (str_contains($body, $needle)) {
            $problems[] = $label;
        }
    }

    if ($code === 200 && trim($body) === '') {
        $problems[] = 'an empty page';
    }

    // The HTML-completeness check applies to HTML responses alone.
    //
    // ⚠️ The distinction comes from the Content-Type header, not from a hand-written list.
    //
    // There used to be a NON_HTML_ROUTES here: a list naming eight paths that return JSON
    // or a file. And such a list necessarily goes stale — the first JSON endpoint added
    // after it fails with "incomplete HTML", a message describing the checker rather than
    // the thing checked. That actually happened when /health was added.
    //
    // Deriving it from the header makes the list unnecessary: the endpoint declares its own
    // type, and the checker reads what it declared.
    $looksHtml = $contentType === ''
        || str_contains(strtolower($contentType), 'text/html');

    if ($code === 200 && $looksHtml && !str_contains($body, '</html>')) {
        $problems[] = 'incomplete HTML (no </html>)';
    }

    return $problems;
}

// ── 3b. The security headers on a live response ────────────────────
/**
 * The headers that **every** response must carry.
 *
 * Matching the name is not enough: `Content-Security-Policy: default-src *` is a header
 * that exists and is worth nothing. So each one carries a string that must appear inside it.
 *
 * HSTS is deliberately absent: `.htaccess` conditions it on `env=HTTPS`, and the smoke test
 * runs over http locally. Sending it over http is meaningless, and its absence there is
 * correctness rather than a fault.
 */
const REQUIRED_HEADERS = [
    'x-content-type-options'    => 'nosniff',
    'x-frame-options'           => 'SAMEORIGIN',
    'referrer-policy'           => 'strict-origin',
    'permissions-policy'        => 'camera=()',
    'content-security-policy'   => "default-src 'self'",
];

/**
 * Verifies that the live server really does send the security headers.
 *
 * ── Why a live check rather than reading a file ──────────────
 *
 * Because the headers all hang off `.htaccess`, and that is a file that **may never be
 * read at all**: on nginx or Caddy it does not exist, on Apache without `AllowOverride All`
 * it is ignored, and without `mod_headers` its block is ignored. And in all three cases the
 * site works perfectly — with no CSP, no nosniff and no framing protection, and without a
 * single error message to say so.
 *
 * This class of fault is not caught by reading the code: the code is fine. It is caught by
 * asking the server itself.
 *
 * (And there is a companion test — tests/Unit/SecurityHeadersTest.php — that guards the
 * content of `.htaccess` itself, catching an accidental deletion with no server needed. The
 * two cover different faults: this one checks what reaches the visitor, that one checks
 * what we promise.)
 *
 * @return string[] the problems (empty = sound)
 */
function checkSecurityHeaders(string $base, int $timeout): array
{
    $ch = curl_init($base . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'STORE-smoke-test/1.0',
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => false,
    ]);
    $raw        = curl_exec($ch);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err        = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        return ['curl: ' . ($err !== '' ? $err : 'no response')];
    }

    $headerBlob = strtolower(substr((string) $raw, 0, $headerSize));

    $problems = [];
    foreach (REQUIRED_HEADERS as $name => $needle) {
        if (!str_contains($headerBlob, $name . ':')) {
            $problems[] = "missing header: {$name}";
            continue;
        }
        if (!str_contains($headerBlob, strtolower($needle))) {
            $problems[] = "the {$name} header is present but without \"{$needle}\"";
        }
    }

    return $problems;
}

// ── 4. Running the HTTP check ──────────────────────────────────────
/**
 * @param list<string> $routes
 * @return list<array<string, mixed>>
 */
function runHttpChecks(array $routes, string $base, int $timeout, bool $verbose): array
{
    $results = [];

    foreach ($routes as $route) {
        $url = $base . $route . (ROUTE_PARAMS[$route] ?? '');
        $r   = fetch($url, $timeout);

        $problems = [];

        if ($r['error'] !== '') {
            $problems[] = 'curl: ' . $r['error'];
        } elseif (!in_array($r['code'], [200, 302, 301, 401], true)) {
            // 302 is acceptable: a **page** redirecting to sign-in without a session.
            //
            // And 401 is acceptable for a different reason: a **JSON** endpoint without a
            // session does not redirect — a redirect makes fetch follow the destination and
            // try to read a whole HTML page as JSON. The correct refusal there is a status
            // code, not a destination. The need appeared when /notifications/* had its
            // reply corrected from 200 to 401; before that it answered "denied" in a body
            // on top of a "done" status.
            $problems[] = 'HTTP ' . $r['code'];
        } else {
            $problems = inspectBody($r['body'], $route, $r['code'], $r['type'] ?? '');
        }

        // The external routes: a redirect is expected, so it is not counted as a fault
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

// ── 5. The syntax check (it covers the POST paths) ─────────────────
/**
 * Every PHP file in the project, excluding the dependencies and the backups.
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
 * The PHP files changed since the last commit (untracked ones included).
 * This is the default: `php -l` is a separate process per file, and on Windows checking the
 * whole project takes minutes — while what you just edited is checked instantly.
 * Use --lint-all for the full sweep.
 *
 * @return string[]|null null if the directory is not a git repository
 */
function changedPhpFiles(string $root): ?array
{
    $out = [];
    $rc  = 0;
    // A development script, never served over the web. $root is a constant passing through
    // escapeshellarg, and the rest of the command is literal in this file — no user input
    // anywhere.
    // nosemgrep: php.lang.security.exec-use.exec-use
    exec('git -C ' . escapeshellarg($root) . ' rev-parse --is-inside-work-tree 2>&1', $out, $rc);
    if ($rc !== 0) {
        return null;
    }

    $files = [];
    foreach (['diff --name-only HEAD', 'ls-files --others --exclude-standard'] as $cmd) {
        $lines = [];
        // $cmd comes from the literal array on the line above, not from any input.
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

/**
 * @param list<string> $files
 * @return array<string, mixed>
 */
function runLintChecks(array $files): array
{
    $failures = [];

    foreach ($files as $path) {
        $out = [];
        $rc  = 0;
        // $path comes from git's output inside this script, and passes through escapeshellarg.
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            $failures[] = ['file' => $path, 'msg' => implode(' ', $out)];
        }
    }

    return ['checked' => count($files), 'failures' => $failures];
}

// ── The run ────────────────────────────────────────────────────────
$exitCode = 0;

echo paint("\n  Smoke test — Cairo Store\n", 'bold');
echo paint("  Base: {$BASE}\n\n", 'grey');

if (!$SKIPHTTP) {
    $routes = extractGetRoutes($ROOT . '/public/index.php');
    echo paint(sprintf("  ── HTTP: %d GET routes ──\n", count($routes)), 'bold');

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
        ? paint("  ✓ {$passed}/" . count($results) . " passed\n\n", 'green')
        : paint("  ✗ {$failed} of " . count($results) . " failed\n\n", 'red');

    if ($failed > 0) {
        $exitCode = 1;
    }

    // ── The security headers ───────────────────────────────────
    echo paint("  ── Security headers ──\n", 'bold');

    $headerProblems = checkSecurityHeaders($BASE, $TIMEOUT);

    foreach ($headerProblems as $problem) {
        echo '  ' . paint('✗', 'red') . '  ' . paint($problem, 'red') . "\n";
    }

    if ($headerProblems === []) {
        echo paint('  ✓ ' . count(REQUIRED_HEADERS) . " headers present on a live response\n\n", 'green');
    } else {
        echo paint("  ✗ The server is not sending the protection .htaccess promises\n\n", 'red');
        $exitCode = 1;
    }
}

if (!$SKIPLINT) {
    if ($LINTALL) {
        $files = allPhpFiles($ROOT);
        $scope = 'the whole project';
    } else {
        $files = changedPhpFiles($ROOT);
        if ($files === null) {
            $files = allPhpFiles($ROOT);
            $scope = 'the whole project (no git)';
        } else {
            $scope = 'changed since the last commit';
        }
    }

    echo paint("  ── PHP syntax ({$scope}) ──\n", 'bold');

    if ($files === []) {
        echo paint("  ✓ No changed files\n\n", 'green');
    } else {
        $lint = runLintChecks($files);

        foreach ($lint['failures'] as $f) {
            echo '  ' . paint('✗', 'red') . '  ' . str_replace($ROOT . DIRECTORY_SEPARATOR, '', $f['file'])
                . "\n     " . paint($f['msg'], 'red') . "\n";
        }

        echo empty($lint['failures'])
            ? paint("  ✓ {$lint['checked']} files sound\n\n", 'green')
            : paint('  ✗ ' . count($lint['failures']) . " files with a syntax error\n\n", 'red');

        if (!empty($lint['failures'])) {
            $exitCode = 1;
        }
    }
}

echo $exitCode === 0
    ? paint("  Result: green\n\n", 'green')
    : paint("  Result: failed\n\n", 'red');

exit($exitCode);
