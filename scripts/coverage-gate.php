<?php

/**
 * scripts/coverage-gate.php
 *
 * Runs the test suite with coverage and applies the same floor CI applies. Exit 0 when
 * coverage holds, 1 when it has dropped.
 *
 * ── Why it exists ────────────────────────────────────────────
 *
 * The gate lived only in .github/workflows/ci.yml, and the machine the project is written
 * on had no coverage driver at all — so `phpunit --coverage` produced nothing, the
 * percentage could not be measured before a push, and the threshold in the workflow was a
 * number nobody could check. It read 25% under a comment calling it "the figure actually
 * reached today" while the real figure was 10%, and the job had been red on every branch.
 *
 * With pcov loaded (php.ini keeps it at pcov.enabled=0, and this script turns it on for
 * its own run alone), the same number is available here as there.
 *
 * ── The floor is a ratchet ───────────────────────────────────
 *
 * FLOOR is the figure actually measured, not a goal. It fails on a *regression*, which is
 * a thing a commit can be responsible for, rather than on failing to reach an aspiration,
 * which no single commit can fix and which therefore gets ignored. Raise it when new tests
 * raise the measurement; never lower it without a reason written beside the change.
 *
 * Keep it in step with the value in .github/workflows/ci.yml — the workflow is what blocks
 * a merge, and this is what tells you before you push.
 */

const FLOOR = 10.0;

$root     = dirname(__DIR__);
$clover   = $root . '/coverage.xml';
$phpunit  = $root . '/vendor/bin/phpunit' . (DIRECTORY_SEPARATOR === '\\' ? '.bat' : '');

if (!is_file($phpunit)) {
    fwrite(STDERR, "coverage gate — phpunit is not installed: $phpunit\n");
    exit(1);
}

// pcov.enabled is PHP_INI_SYSTEM, so it cannot be switched on from inside a running
// script — it has to be set as the process starts. php.ini leaves it off so that Apache
// and every ordinary CLI run pay nothing for an extension only this command needs.
$command = escapeshellarg(PHP_BINARY)
    . ' -d pcov.enabled=1'
    . ' ' . escapeshellarg($root . '/vendor/phpunit/phpunit/phpunit')
    . ' --coverage-clover=' . escapeshellarg($clover)
    . ' --no-output';

passthru($command, $status);

if ($status !== 0) {
    fwrite(STDERR, "coverage gate — the test suite failed; coverage was not measured.\n");
    exit(1);
}

if (!is_file($clover)) {
    fwrite(
        STDERR,
        "coverage gate — no coverage.xml was produced.\n"
        . "  A coverage driver is missing. Check that php.ini loads pcov:\n"
        . "      php -m | findstr pcov\n"
    );
    exit(1);
}

$xml = simplexml_load_file($clover);
if ($xml === false) {
    fwrite(STDERR, "coverage gate — coverage.xml could not be parsed.\n");
    exit(1);
}

$metrics    = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered    = (int) $metrics['coveredstatements'];
$percent    = $statements > 0 ? $covered / $statements * 100 : 0.0;

printf("Line coverage: %.1f%%  (%d of %d statements)\n", $percent, $covered, $statements);

if (round($percent, 1) < FLOOR) {
    fwrite(
        STDERR,
        sprintf(
            "Coverage of %.1f%% is below the %.1f%% floor — it may not go down.\n",
            $percent,
            FLOOR
        )
    );
    exit(1);
}

exit(0);
