<?php

/**
 * scripts/audit.php
 * A measurement report on the state of the code — run it before and after each cleanup
 * phase to see the progress as a number rather than an impression.
 *
 * Usage:
 *     php scripts/audit.php
 *     php scripts/audit.php --json     ← machine output for comparison
 *
 * It measures:
 *   - the size of each layer (files/lines)
 *   - how much of the controllers is OpenAPI documentation rather than code
 *   - SQL queries written inside the controllers (which must be zero)
 *   - the <script>/<style> lines embedded inside the views
 *   - database access from the views (which must be zero)
 *   - the longest functions
 *   - known dead-code indicators
 */

declare(strict_types=1);

$ROOT   = dirname(__DIR__);
$asJson = in_array('--json', $argv, true);

// ── Helpers ────────────────────────────────────────────────────────
/**
 * @return list<string>
 */
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

/** How many of the file's lines are an OpenAPI `#[OA\...]` attribute block? */
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
 * Blanks the content of PHP comments in the source while preserving the line count and
 * the numbering.
 *
 * Why? The embedded-asset counter below is a textual scan, so it counted a <style> or
 * <script> tag mentioned **inside a documentation comment** as a real tag: it opened the
 * count and never closed it (no closing tag in the same comment), so the whole rest of the
 * file was counted as an embedded asset. That actually happened in phase 4 — the <style>
 * counter jumped from 55 to 96 because of three comments, no more.
 *
 * The blanking is limited to T_COMMENT and T_DOC_COMMENT. A heredoc's content stays counted
 * on purpose: a <style> block inside a heredoc really is printed on the page, so it is a
 * genuine embedded asset (see views/auth/reset-password.php).
 */
function blankPhpComments(string $src): string
{
    // The views are a mix of HTML and PHP; token_get_all handles them exactly as the
    // interpreter itself does, so the HTML parts arrive as T_INLINE_HTML unchanged.
    // token_get_all always returns an array (it throws on error rather than returning
    // false), so comparing against false was a condition that never held.
    $tokens = @token_get_all($src);
    if ($tokens === []) {
        return $src; // An unparseable file — return it as it is rather than dropping it
    }

    $out = '';
    foreach ($tokens as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            // Replace the text with the same number of blank lines so the numbering holds
            $out .= str_repeat("\n", substr_count($token[1], "\n"));
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/**
 * Splitting into lines.
 *
 * ⚠️ Do not use preg_split('/\R/') here. The source carried Arabic text, and `\R` without
 * the /u modifier works on bytes and matches `\x85` — which is a **legitimate continuation
 * byte inside Arabic letters** in UTF-8. The result: the text is cut in the middle of a
 * character and lines that do not exist are invented. That produced a difference of 5 lines
 * in home.php alone.
 *
 * @return list<string>
 */
function splitLines(string $src): array
{
    return preg_split('/\r\n|\n|\r/', $src) ?: [];
}

/**
 * Blanks HTML comments while preserving the line count.
 *
 * A tag mentioned inside <!-- ... --> is not an embedded asset: the browser does not run
 * it, and a commented-out CSS block is not a working CSS block. Without this blanking, a
 * passing mention of the tag inside a comment opened a count over the whole rest of the
 * file — the <style> counter jumped from 55 to 337 because of one comment explaining where
 * the block had moved to.
 */
function blankHtmlComments(string $src): string
{
    return preg_replace_callback(
        '/<!--.*?-->/s',
        static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    ) ?? $src;
}

/** The lines inside <script>...</script> or <style>...</style> in a view file. */
/**
 * @return array{js: int, css: int}
 */
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
 * The longest functions in a file — by counting braces from the declaration line to the
 * matching closing brace, not by the distance from one declaration to the next.
 *
 * Measuring by distance gives false numbers: deleting a short function that follows a long
 * one makes the long one look longer, because the measurement swallows everything between
 * them.
 *
 * @param list<string> $files
 * @return list<array<string, mixed>>
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
                // Ignore braces inside simple strings on the line
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

            // An abstract function or an interface declaration (with no body)
            if ($end === null) {
                continue;
            }

            $found[] = ['file' => $file, 'name' => $m[1], 'lines' => $end - $i + 1];
        }
    }

    usort($found, fn($a, $b) => $b['lines'] <=> $a['lines']);
    return array_slice($found, 0, $limit);
}

/**
 * @param list<string> $files
 */
function grepCount(array $files, string $pattern): int
{
    $n = 0;
    foreach ($files as $f) {
        $src = file_get_contents($f) ?: '';
        $n += preg_match_all($pattern, $src);
    }
    return $n;
}

/**
 * @param list<string> $files
 * @return array<string, int> keyed by path ← the number of matches
 */
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

// ── Gathering the data ─────────────────────────────────────────────
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

// OpenAPI inside the controllers
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

// The problem indicators
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
    // HealthController is excluded by name, not by loosening the pattern.
    //
    // /health must test **the connection itself** with `SELECT 1`; going through a model
    // measures the model rather than the connection, and depends on a schema that may be
    // halfway through a migration. So the query here is not a leak out of the data layer —
    // it is the point.
    //
    // Excluding by name is deliberate: loosening the pattern would have hidden a real query
    // in another controller tomorrow. This way "target 0" is a real zero rather than "one we
    // look past" — and a number that gets looked past is the first step towards a counter
    // nobody reads.
    'sql_in_controllers'   => grepCount(
        array_filter($ctrl, fn(string $f): bool => basename($f) !== 'HealthController.php'),
        '/->prepare\(|->query\(/'
    ),
    'db_access_in_views'   => grepCount($views, '/Database::|->prepare\(|->query\(/'),
    'function_exists'      => grepCount(array_merge($ctrl, $layers['app/Core'], $views), '/function_exists\(/'),
    'inline_script_lines'  => $inlineJs,
    'inline_style_lines'   => $inlineCss,
    // ⚠️ The allow-list was widened after a complete manual sweep of all 225 sites.
    //
    // It knew five forms only, so it reported 225 sites — and tracing every one of them
    // back to its source produced **zero** holes. That was not a lenient reading:
    //
    //   · 89 constants (URLROOT and its siblings) — not input at all
    //   · 47 numbers or counters — functions declaring `: int`, or (int) over $_GET
    //   · 9 links from http_build_query() — which encodes every character
    //   · the rest are literal CSS strings from a match()/ternary, symbols from arrays
    //     written in the code, and partials deliberately rendered with ob_get_clean()
    //
    // A counter that says 225 while all of them are sound is not read after the second time
    // — and that is exactly what lets dangerous site number 226 through. Widening here is
    // not leniency but a correction of what it measures.
    //
    // And the result is 123 rather than zero, deliberately: the pattern is textual, so it
    // cannot see that `$stock` came from a function declaring `: int`, nor that
    // `$statusClass` came from a match() over literals. Driving it to zero needs flow
    // analysis, not a regular expression — and attempting it by widening the list would
    // mean listing variable names, that is, turning the measure into an ignore list. It
    // stays "for review" because that is what it is.
    //
    // ⚠️ And it is not widened further without a sweep like that one. Adding a function's
    // name to the list is a claim that it escapes — and if it does not, the counter has
    // begun to lie quietly.
    'unescaped_echo'       => grepCount($views, '/<\?=(?![^?]*(?:'
        . 'htmlspecialchars|json_encode|urlencode|http_build_query|number_format'
        . '|\(int\)|\(float\)|\bcount\(|\bceil\(|categoryEmoji\('
        . '|[A-Z_]{4,}'          // The project's constants: URLROOT · SITENAME · APPROOT
        . '))[^?]*\?>/'),
    'openapi_lines_total'  => $oaTotal,
    'controllers_no_docs'  => count($undocumented),
    // ⚠️ This measure used to be `is_file(app/Core/Model.php) ? 1 : 0` — that is, it
    // measured **the file's existence** as a proxy for its being dead. It was right when
    // the class was deleted in phase 1 because nothing inherited it, and it lied the moment
    // the class came back alive with sixteen models inheriting it: the counter was
    // reporting "dead code" while pointing at the most used file in the layer.
    //
    // What it measures now is the original question in its correct form: how many models do
    // **not** inherit the shared base — that is, how many still open their own connection.
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
    ['HealthController.php']   // Excluded by name — see sql_in_controllers above
));
$report['no_openapi']  = $undocumented;
$report['inline_top']  = array_slice($inlinePerFile, 0, 8, true);

// ── The output ─────────────────────────────────────────────────────
if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$bar = str_repeat('─', 64);
printf("\n  Code audit — Cairo Store   %s\n  %s\n\n", date('Y-m-d H:i'), $bar);

printf("  %-20s %8s %10s\n  %s\n", 'Layer', 'Files', 'Lines', $bar);
foreach ($report['layers'] as $name => $d) {
    printf("  %-20s %8d %10s\n", $name, $d['files'], number_format($d['lines']));
}
printf(
    "  %s\n  %-20s %8d %10s\n\n",
    $bar,
    'Total',
    array_sum(array_column($report['layers'], 'files')),
    number_format(array_sum(array_column($report['layers'], 'lines')))
);

printf("  OpenAPI documentation inside the controllers\n  %s\n", $bar);
printf("  %-42s %6s %8s %6s\n", 'File', 'Total', 'Docs', 'Code');
foreach ($report['openapi'] as $f => $d) {
    printf("  %-42s %6d %8d %6d\n", $f, $d['total'], $d['openapi'], $d['code']);
}
printf(
    "  %s\n  Total documentation lines: %d · undocumented controllers: %d\n",
    $bar,
    $report['issues']['openapi_lines_total'],
    $report['issues']['controllers_no_docs']
);
if ($report['no_openapi']) {
    echo '    ' . implode(', ', $report['no_openapi']) . "\n";
}
echo "\n";

printf("  Indicators\n  %s\n", $bar);
$labels = [
    'sql_in_controllers'  => 'SQL queries in the controllers         (target 0)',
    'db_access_in_views'  => 'Database access from the views         (target 0)',
    'function_exists'     => 'function_exists() guards in ctrl/core/views (0)',
    'inline_script_lines' => 'Embedded <script> lines in the views',
    'inline_style_lines'  => 'Embedded <style> lines in the views    (target 0)',
    'unescaped_echo'      => 'Unescaped <?= ?> sites                 (for review)',
    'controllers_no_docs' => 'Controllers without OpenAPI docs',
    'models_off_base'     => 'Models off the shared base             (target 0)',
    'dead_model_helper'   => 'Controller::model() dead code          (target 0)',
    'shared_partials'     => 'Partials in views/shared               (target >5)',
];
foreach ($labels as $key => $label) {
    printf("  %-52s %6d\n", $label, $report['issues'][$key]);
}
if ($report['sql_hits']) {
    echo "    SQL in: " . implode(', ', $report['sql_hits']) . "\n";
}
echo "\n";

printf("  The 10 longest functions in the controllers\n  %s\n", $bar);
foreach ($report['longest_controller_functions'] as $x) {
    printf("  %-52s %6d lines\n", $x['fn'], $x['lines']);
}
echo "\n";

printf("  The views carrying the most embedded assets\n  %s\n", $bar);
foreach ($report['inline_top'] as $f => $a) {
    printf("  %-44s js=%4d css=%4d\n", $f, $a['js'], $a['css']);
}
echo "\n";
