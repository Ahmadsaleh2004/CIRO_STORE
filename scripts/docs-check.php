<?php

/**
 * scripts/docs-check.php
 *
 * Proves public/docs/openapi.yaml still matches the OA attributes in the code: regenerate
 * it, compare, restore. Exit 0 when it is current, 1 when it is behind.
 *
 * ── Why it is a script and not eight lines of shell in ci.yml ─
 *
 * The check used to live only inside the workflow, which put it out of reach of the one
 * person able to act on it before pushing. `composer check` — the script whose whole job is
 * "run the gates locally" — did not include it, so the specification could only be found
 * stale by CI, after the push.
 *
 * Written here once, it is the same code in both places: `composer docs:check` locally and
 * the same command in the workflow.
 *
 * ── The annotation ───────────────────────────────────────────
 *
 * Under GitHub Actions the difference is emitted as `::error::` lines rather than printed
 * to the log alone. That is not decoration: Actions logs require a signed-in session (the
 * REST endpoint answers 403), while annotations are readable through the public API. A
 * failure that can only be read by someone logged in is a failure that gets guessed at.
 */

$root     = dirname(__DIR__);
$spec     = $root . '/public/docs/openapi.yaml';
$onCi     = getenv('GITHUB_ACTIONS') === 'true';
$binary   = $root . '/vendor/bin/openapi' . (DIRECTORY_SEPARATOR === '\\' ? '.bat' : '');

if (!is_file($spec)) {
    fwrite(STDERR, "docs:check — the specification is missing: $spec\n");
    exit(1);
}

// Two forms of the same file, deliberately. $onDisk is what gets written back so the
// working tree is left exactly as it was found; $committed is what the comparison uses.
//
// They differ on Windows and nowhere else: git stores this file with LF and checks it out
// with CRLF, so comparing the checkout against freshly generated output would report every
// one of its 3,635 lines as changed — 123,617 bytes against 119,982, with not one
// character of the API different. Normalising both sides asks the only question worth
// asking: has the specification drifted from the attributes?
$onDisk    = file_get_contents($spec);
$committed = str_replace("\r\n", "\n", $onDisk);

// Regenerate over the top of the committed copy, then restore it whatever happens. The
// file is tracked, so leaving a regenerated version behind would turn a passing check into
// a dirty working tree.
$restore = static function () use ($spec, $onDisk): void {
    file_put_contents($spec, $onDisk);
};

$command = escapeshellarg($binary)
    . ' ' . escapeshellarg($root . '/app/Controllers')
    . ' ' . escapeshellarg($root . '/app/config')
    . ' -o ' . escapeshellarg($spec)
    . ' --format yaml';

exec($command . ' 2>&1', $output, $status);

if ($status !== 0) {
    $restore();
    fwrite(STDERR, "docs:check — the generator failed:\n" . implode("\n", $output) . "\n");
    exit(1);
}

// The same normalisation docs:generate applies, so the comparison is not decided by which
// operating system ran it — see scripts/normalize-openapi.php for the measurement behind
// this.
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/normalize-openapi.php')
    . ' ' . escapeshellarg($spec) . ' 2>&1',
    $normOutput,
    $normStatus
);

if ($normStatus !== 0) {
    $restore();
    fwrite(STDERR, "docs:check — normalisation failed:\n" . implode("\n", $normOutput) . "\n");
    exit(1);
}

$regenerated = file_get_contents($spec);
$restore();

if ($regenerated === $committed) {
    echo "The specification matches the code.\n";
    exit(0);
}

// ── It is behind. Say exactly how. ───────────────────────────
$a = explode("\n", $committed);
$b = explode("\n", $regenerated);
$diff = [];

for ($i = 0, $n = max(count($a), count($b)); $i < $n && count($diff) < 24; $i++) {
    $left  = $a[$i] ?? null;
    $right = $b[$i] ?? null;
    if ($left === $right) {
        continue;
    }
    $line = $i + 1;
    $diff[] = "line {$line}";
    $diff[] = "  committed:   " . mb_strimwidth((string) $left, 0, 220, '…');
    $diff[] = "  regenerated: " . mb_strimwidth((string) $right, 0, 220, '…');
}

$headline = sprintf(
    'openapi.yaml is behind the OA attributes (committed %d bytes, regenerated %d) — run "composer docs:generate" and commit the result.',
    strlen($committed),
    strlen($regenerated)
);

if ($onCi) {
    // One annotation for the headline, then one per diff line. Newlines inside an
    // annotation are escaped as %0A by the Actions runner, so each line is sent separately
    // to stay readable in the checks UI and in the API.
    echo "::error::{$headline}\n";
    foreach ($diff as $line) {
        echo '::error::' . str_replace(["\r", "\n"], ' ', $line) . "\n";
    }
} else {
    fwrite(STDERR, $headline . "\n\n" . implode("\n", $diff) . "\n");
}

exit(1);
