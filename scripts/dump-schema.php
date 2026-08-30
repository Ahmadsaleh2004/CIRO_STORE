<?php

/**
 * scripts/dump-schema.php
 * Regenerates tests/fixtures/schema.sql — the database's structure with no data.
 *
 * ── Why a script rather than a one-liner ────────────────────
 *
 * The command used to be defined in composer.json like this:
 *
 *     mysqldump --no-data ... > tests/fixtures/schema.sql
 *
 * And it carried two faults, both of which actually happened:
 *
 *   1. `mysqldump` is not on the PATH in a default XAMPP install (it lives in
 *      C:\xampp\mysql\bin), so the command fails.
 *
 *   2. **And the `>` redirection empties the file before the command even starts.** So when
 *      it fails, nothing has been written — but the original file has already been
 *      destroyed. Which is to say the command designed to update the reference schema was
 *      **erasing** it on the first failure.
 *
 * Which is why this script writes to a temporary file, checks that the output actually
 * looks like a schema, and then — and only then — moves it over the real file. A failure
 * leaves the existing schema as it was.
 *
 * Usage:  composer test:schema
 * An unusual mysqldump path:  MYSQLDUMP=/path/to/mysqldump composer test:schema
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Locates mysqldump: the environment variable first, then the PATH, then the usual places.
 */
function locateMysqldump(): ?string
{
    $fromEnv = getenv('MYSQLDUMP');
    if (is_string($fromEnv) && $fromEnv !== '' && is_executable($fromEnv)) {
        return $fromEnv;
    }

    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $probe     = $isWindows ? 'where mysqldump' : 'command -v mysqldump';

    $output = [];
    $status = 0;
    @exec($probe . ' 2>' . ($isWindows ? 'NUL' : '/dev/null'), $output, $status);
    if ($status === 0 && isset($output[0]) && trim($output[0]) !== '') {
        return trim($output[0]);
    }

    $candidates = $isWindows
        ? ['C:\\xampp\\mysql\\bin\\mysqldump.exe', 'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe']
        : ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/homebrew/bin/mysqldump'];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

$binary = locateMysqldump();

if ($binary === null) {
    fwrite(STDERR, PHP_EOL . "  ✗ mysqldump was not found." . PHP_EOL);
    fwrite(STDERR, "    Pass its path explicitly: MYSQLDUMP=/path/to/mysqldump composer test:schema" . PHP_EOL . PHP_EOL);
    exit(1);
}

$target = ROOTPATH . '/tests/fixtures/schema.sql';
$temp   = $target . '.tmp';

// The password goes through an options file rather than the command line: the command
// line is readable by any user on the system through the process list. (The same decision
// taken in BackupModel.)
$optionsFile = tempnam(sys_get_temp_dir(), 'cairo-dump-');
if ($optionsFile === false) {
    fwrite(STDERR, "  ✗ A temporary options file could not be created." . PHP_EOL);
    exit(1);
}

$quote = static fn (string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';

file_put_contents($optionsFile, implode("\n", [
    '[client]',
    'host=' . $quote(DB_HOST),
    'port=' . $quote((string) DB_PORT),
    'user=' . $quote(DB_USER),
    'password=' . $quote(DB_PASS),
]) . "\n");
@chmod($optionsFile, 0600);

$command = escapeshellarg($binary)
    . ' --defaults-extra-file=' . escapeshellarg($optionsFile)
    . ' --no-data --skip-comments --skip-add-locks --skip-set-charset'
    . ' --routines=false --triggers=false'
    . ' ' . escapeshellarg(DB_NAME)
    . ' > ' . escapeshellarg($temp);

$status = 0;
// ⚠️ A documented false positive. The command is built entirely from configuration
// constants, and every variable part of it passes through escapeshellarg — including the
// binary's path, the database's name and both temporary files (see the construction above,
// line by line). And no input from the network reaches here at all: this is a terminal
// script that refuses to run outside the CLI.
// nosemgrep: php.lang.security.exec-use.exec-use
@exec($command, $ignored, $status);

// The options file holds the database password, so deleting it immediately after use is
// deliberate — and it is the reason the file exists at all (rather than passing the
// password on the command line where any `ps` can see it). The path comes from tempnam(),
// not from input, so nothing can steer it.
// nosemgrep: php.lang.security.unlink-use.unlink-use
unlink($optionsFile);

// ── Verification before writing over the real file ──────────
$dump = is_file($temp) ? (string) file_get_contents($temp) : '';

if ($status !== 0 || $dump === '' || !str_contains($dump, 'CREATE TABLE')) {
    // $temp comes from tempnam(), not from input — as above.
    // nosemgrep: php.lang.security.unlink-use.unlink-use
    @unlink($temp);
    fwrite(STDERR, PHP_EOL . "  ✗ Generation failed (code {$status}) — the existing schema was left untouched." . PHP_EOL . PHP_EOL);
    exit(1);
}

// Line endings are normalised to LF: .gitattributes stores them that way too, so leaving
// CRLF here would make every run on Windows look like a wholesale change in the diff.
$dump = str_replace("\r\n", "\n", $dump);
file_put_contents($target, $dump);
// nosemgrep: php.lang.security.unlink-use.unlink-use
@unlink($temp);

$tables = substr_count($dump, 'CREATE TABLE');
echo PHP_EOL . '  ✓ ' . $tables . ' tables → tests/fixtures/schema.sql' . PHP_EOL . PHP_EOL;
exit(0);
