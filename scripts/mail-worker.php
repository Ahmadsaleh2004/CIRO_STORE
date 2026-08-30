<?php

/**
 * scripts/mail-worker.php
 * Drains the mail queue — run outside the request path.
 *
 * Usage:
 *     php scripts/mail-worker.php                 one batch (25 messages)
 *     php scripts/mail-worker.php --limit=100     a larger batch
 *     php scripts/mail-worker.php --status        the queue's state, sending nothing
 *     php scripts/mail-worker.php --retry-failed  returns the failed ones to pending
 *
 * Scheduling on Windows (Task Scheduler) every minute:
 *     schtasks /create /tn "CairoStoreMail" /tr ^
 *       "C:\xampp\php\php.exe C:\xampp\htdocs\STORE\scripts\mail-worker.php" ^
 *       /sc minute /mo 1
 *
 * And on Linux (cron):
 *     * * * * * php /var/www/STORE/scripts/mail-worker.php >/dev/null 2>&1
 *
 * Why one batch that ends rather than a permanent loop? Because a long-running process
 * needs supervision (a restart when it falls over, a memory limit, a clean shutdown) — and
 * XAMPP provides none of that. A short batch that ends by itself is scheduled by the
 * system: if it fails once it runs again the next minute, and no state leaks between runs.
 *
 * ⚠️ If this script is never scheduled, messages pile up in mail_queue unsent.
 * `--status` is what reveals that quickly.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

use App\Core\Database;
use App\Core\Mailer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$argvList = $argv ?? [];

/** Reads an option's value in the --key=value form. */
/**
 * @param list<string> $args
 */
function optionValue(array $args, string $name, ?string $default = null): ?string
{
    foreach ($args as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return $default;
}

/** A summary of the queue by status. */
/**
 * @return array<string, int>
 */
function queueSummary(): array
{
    $rows = Database::connect()
        ->query('SELECT status, COUNT(*) AS n FROM mail_queue GROUP BY status')
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'pending' => (int) ($rows['pending'] ?? 0),
        'sent'    => (int) ($rows['sent']    ?? 0),
        'failed'  => (int) ($rows['failed']  ?? 0),
    ];
}

echo PHP_EOL . '  Mail queue — ' . DB_NAME . PHP_EOL . PHP_EOL;

// ── The status alone ──────────────────────────────────────────
if (in_array('--status', $argvList, true)) {
    $s = queueSummary();
    echo '  Pending: ' . $s['pending'] . '   Sent: ' . $s['sent'] . '   Failed: ' . $s['failed'] . PHP_EOL;

    if ($s['failed'] > 0) {
        echo PHP_EOL . '  The latest errors:' . PHP_EOL;
        $failed = Database::connect()->query(
            "SELECT id, to_email, last_error FROM mail_queue
              WHERE status = 'failed' ORDER BY id DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($failed as $row) {
            echo '    #' . $row['id'] . '  ' . $row['to_email'] . '  — ' . ($row['last_error'] ?? '?') . PHP_EOL;
        }
    }

    echo PHP_EOL;
    exit(0);
}

// ── Returning the failed ones to the queue ────────────────────
if (in_array('--retry-failed', $argvList, true)) {
    // attempts is reset too, otherwise processQueue rejects them straight away on its
    // `attempts < MAX_ATTEMPTS` condition — so they would look pending and never be sent.
    $n = Database::connect()->exec(
        "UPDATE mail_queue SET status = 'pending', attempts = 0, last_error = NULL WHERE status = 'failed'"
    );
    echo '  ✓ ' . (int) $n . ' messages returned to the queue.' . PHP_EOL . PHP_EOL;
    exit(0);
}

// ── The drain ─────────────────────────────────────────────────
$limit  = max(1, (int) optionValue($argvList, 'limit', '25'));
$result = Mailer::processQueue($limit);
$after  = queueSummary();

if ($result['sent'] === 0 && $result['failed'] === 0) {
    echo '  · No pending messages.' . PHP_EOL . PHP_EOL;
    exit(0);
}

echo '  ✓ Sent:    ' . $result['sent'] . PHP_EOL;

if ($result['failed'] > 0) {
    echo '  ✗ Failed:  ' . $result['failed'] . PHP_EOL;
}
if ($result['skipped'] > 0) {
    echo '  · Skipped: ' . $result['skipped'] . ' (another worker claimed them)' . PHP_EOL;
}

echo '  Still pending: ' . $after['pending'] . PHP_EOL . PHP_EOL;

// A non-zero exit code when a final failure exists — so monitoring picks it up.
exit($after['failed'] > 0 ? 1 : 0);
