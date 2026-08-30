<?php

/**
 * scripts/sync-readme-stats.php
 * Measures the project's numbers from the code and writes them into the README — or
 * fails if they have drifted.
 *
 * Usage:
 *     php scripts/sync-readme-stats.php          ← writes the numbers
 *     php scripts/sync-readme-stats.php --check  ← fails if they differ
 *
 * ══════════════════════════════════════════════════════════════
 * Why
 * ══════════════════════════════════════════════════════════════
 *
 * The README used to state hand-written numbers, and every one of them had drifted
 * quietly:
 *
 *     77 tests         ← in truth 250
 *     104 routes       ← 105
 *     24,199 PHP lines ← 24,514 in app/ alone
 *     28 tables        ← 31
 *
 * And more serious than the numbers was the sentence "the CSP is report-only today" while
 * the policy had been fully enforced since the csp/style-src branch. Which is to say the
 * project's front door had come to describe an older project than the one that exists, and
 * at times to sell it short.
 *
 * The project already insists that the OpenAPI specification be generated from the code
 * and fails CI when it falls behind. The README's numbers deserve the same treatment: what
 * is written by hand ages, what is generated does not.
 *
 * ══════════════════════════════════════════════════════════════
 * How the places are marked in the README
 * ══════════════════════════════════════════════════════════════
 *
 * With HTML comments — because they do not appear when rendered:
 *
 *     <!--stats:tests-->250 tests<!--/stats:tests-->
 *
 * And marking it this way leaves the value **readable in the source as well**, so anyone
 * opening the README in an editor sees a number rather than a placeholder. Which keeps the
 * file valid on its own even if this script is never run.
 */

declare(strict_types=1);

$root   = dirname(__DIR__);
$check  = in_array('--check', $argv, true);
$readme = $root . '/README.md';

/** Counts the lines across a set of files. */
/**
 * @param list<string> $files
 */
function countLines(array $files): int
{
    $total = 0;
    foreach ($files as $file) {
        $total += count(file($file, FILE_IGNORE_NEW_LINES));
    }
    return $total;
}

/**
 * The git-tracked files with a given extension under a path.
 *
 * From git rather than from the file system: vendor/, node_modules/ and dist/ are not part
 * of the project's size, and counting them yields a meaningless number. The tracked list is
 * exactly what somebody cloning the repository sees.
 *
 * @return list<string>
 */
function trackedFiles(string $root, string $pattern): array
{
    $cmd = 'git -C ' . escapeshellarg($root) . ' ls-files ' . escapeshellarg($pattern);
    exec($cmd, $out, $code);

    if ($code !== 0) {
        fwrite(STDERR, "  ✗ The git files could not be read — is this a repository?\n");
        exit(1);
    }

    return array_values(array_filter(
        array_map(static fn (string $f): string => $root . '/' . $f, $out),
        'is_file'
    ));
}

// ── The measurement ────────────────────────────────────────────

$phpFiles = trackedFiles($root, 'app/*.php');
$jsFiles  = array_filter(
    trackedFiles($root, 'public/js/*.js'),
    static fn (string $f): bool => !str_contains(str_replace('\\', '/', $f), '/dist/')
);
$cssFiles = array_filter(
    trackedFiles($root, 'public/css/*.css'),
    static fn (string $f): bool => !str_contains(str_replace('\\', '/', $f), '/dist/')
);

// The routes come from the route table itself — the same source OpenApiCoverageTest uses.
$routes = preg_match_all(
    '/^\$r->(get|post|put|patch|delete)\(/m',
    (string) file_get_contents($root . '/public/index.php')
);

// The tables come from the baseline rather than a live database: a developer's database
// may carry experimental tables, and the tracked schema is what the repository publishes.
$tables = preg_match_all(
    '/^CREATE TABLE /m',
    (string) file_get_contents($root . '/tests/fixtures/schema.sql')
);

// The tests: the test methods are counted rather than the cases — dataProvider cases move
// as their data moves, and the stable count is more honest for a general description.
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
    'controllers' => number_format($controllers) . ' controllers',
    'models'      => number_format($models) . ' models',
    'routes'      => number_format($routes) . ' routes',
    'tables'      => number_format($tables) . ' tables',
    'php'         => number_format(countLines($phpFiles)) . ' lines of PHP',
    'js'          => number_format(countLines($jsFiles)) . ' JS',
    'css'         => number_format(countLines($cssFiles)) . ' CSS',
    'tests'       => number_format($testMethods) . ' tests',
    'operations'  => number_format($operations) . ' operations',
];

// ── Writing, or checking ───────────────────────────────────────

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
        $drift[] = sprintf('%-12s README: %-22s actual: %s', $key, trim($m[1]), $value);
    }

    $updated = (string) preg_replace(
        $pattern,
        '<!--stats:' . $key . '-->' . $value . '<!--/stats:' . $key . '-->',
        $updated
    );
}

if ($missing !== []) {
    fwrite(STDERR, "  ✗ Markers missing from the README: " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "    Add <!--stats:<name>-->the value<!--/stats:<name>--> around each number.\n");
    exit(1);
}

if ($check) {
    if ($drift !== []) {
        fwrite(STDERR, "  ✗ The README's numbers do not match the code:\n\n");
        foreach ($drift as $line) {
            fwrite(STDERR, '    ' . $line . "\n");
        }
        fwrite(STDERR, "\n  Run: composer readme:sync, then commit the result.\n\n");
        exit(1);
    }

    echo "  ✓ The README's numbers match the code\n";
    exit(0);
}

if ($updated === $source) {
    echo "  ✓ No change — the numbers were already up to date\n";
    exit(0);
}

file_put_contents($readme, $updated);

echo "  ✓ The README's numbers were updated\n";
foreach ($drift as $line) {
    echo '    ' . $line . "\n";
}
