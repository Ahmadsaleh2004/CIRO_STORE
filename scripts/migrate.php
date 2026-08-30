<?php

/**
 * scripts/migrate.php
 * The migrator's terminal interface.
 *
 *   php scripts/migrate.php status              the status
 *   php scripts/migrate.php up [--pretend]      apply what is pending
 *   php scripts/migrate.php down [n] [--pretend] roll back the last n
 *   php scripts/migrate.php baseline            record what exists as applied
 *   php scripts/migrate.php make <name>         a new migration file
 *
 * ⚠️ `baseline` is called once, on a database built from
 * tests/fixtures/schema.sql: the baseline already carries the effect of the seven
 * migrations, and running them over it fails with "table already exists".
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

use App\Core\Database;
use App\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$directory = ROOTPATH . '/database/migrations';
$argvList  = $argv ?? [];
$command   = $argvList[1] ?? 'status';
$pretend   = in_array('--pretend', $argvList, true);

/** Prints one line, its symbol carrying the status. */
function line(string $symbol, string $text): void
{
    echo '  ' . $symbol . ' ' . $text . PHP_EOL;
}

// ── make needs no database connection ────────────────────────
if ($command === 'make') {
    $name = $argvList[2] ?? '';
    if ($name === '') {
        fwrite(STDERR, "Usage: php scripts/migrate.php make <name>\n");
        exit(1);
    }

    $name = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($name)) ?? 'migration';

    $existing = glob($directory . '/*.sql') ?: [];
    $next = 1;
    foreach ($existing as $file) {
        if (preg_match('/^(\d{4})_/', basename($file), $m)) {
            $next = max($next, (int) $m[1] + 1);
        }
    }

    $version = str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    $path    = $directory . '/' . $version . '_' . $name . '.sql';

    file_put_contents($path, implode("\n", [
        '-- ══════════════════════════════════════════════════════════════',
        '-- ' . $version . '_' . $name,
        '-- ══════════════════════════════════════════════════════════════',
        '--',
        '-- Explain **why** this change exists here, not what it does — the SQL below',
        '-- says what. And once it is applied, run `composer test:schema` so the',
        '-- reference schema catches up with the change.',
        '',
        '-- @UP',
        '',
        '',
        '-- @DOWN',
        '-- If rolling back is impossible, write the reason here plainly rather than',
        '-- leaving the section empty — emptiness reads as an oversight, a reason reads',
        '-- as a decision.',
        '',
    ]) . "\n");

    line('✓', 'Created: ' . str_replace(ROOTPATH . DIRECTORY_SEPARATOR, '', $path));
    exit(0);
}

$migrator = new Migrator(Database::connect(), $directory);

echo PHP_EOL . '  Migrations — ' . DB_NAME . PHP_EOL . PHP_EOL;

try {
    switch ($command) {
        case 'status':
            $applied = $migrator->applied();
            $pending = $migrator->pending();
            $drift   = $migrator->drifted();

            foreach ($migrator->available() as $migration) {
                $key = $migration['version'];
                if (isset($applied[$key])) {
                    line('✓', $key . '_' . $migration['name'] . '  — applied ' . $applied[$key]['applied_at']);
                } else {
                    line('·', $key . '_' . $migration['name'] . '  — pending');
                }
            }

            echo PHP_EOL;
            line('', 'Applied: ' . count($applied) . '   Pending: ' . count($pending));

            if ($drift !== []) {
                echo PHP_EOL;
                line('⚠', 'Drift — files changed after they were applied:');
                foreach ($drift as $item) {
                    line(' ', $item);
                }
                exit(1);
            }
            break;

        case 'up':
            $done = $migrator->up($pretend);
            if ($done === []) {
                line('✓', 'Nothing pending.');
                break;
            }
            foreach ($done as $name) {
                line($pretend ? '·' : '✓', ($pretend ? 'Would apply: ' : 'Applied: ') . $name);
            }
            break;

        case 'down':
            $steps = (int) ($argvList[2] ?? 1);
            $steps = $steps > 0 ? $steps : 1;
            $done  = $migrator->down($steps, $pretend);

            if ($done === []) {
                line('·', 'Nothing to roll back.');
                break;
            }
            foreach ($done as $name) {
                line($pretend ? '·' : '✓', ($pretend ? 'Would roll back: ' : 'Rolled back: ') . $name);
            }
            break;

        case 'baseline':
            $count = $migrator->baseline();
            line('✓', $count . ' migrations recorded as applied (without running them).');
            if ($count > 0) {
                line('', 'The baseline already carries them — see the comment on Migrator::baseline.');
            }
            break;

        default:
            fwrite(STDERR, "Unknown command: {$command}\n");
            fwrite(STDERR, "Available: status | up | down [n] | baseline | make <name>\n");
            exit(1);
    }
} catch (Throwable $e) {
    echo PHP_EOL;
    fwrite(STDERR, '  ✗ ' . $e->getMessage() . PHP_EOL . PHP_EOL);
    exit(1);
}

echo PHP_EOL;
exit(0);
