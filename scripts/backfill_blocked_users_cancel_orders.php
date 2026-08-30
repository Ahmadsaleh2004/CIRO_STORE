<?php

/**
 * scripts/backfill_blocked_users_cancel_orders.php
 * A one-time script, run by hand from the CLI:
 *   php scripts/backfill_blocked_users_cancel_orders.php
 *
 * It cancels the pending orders (not_taken / taken) of every blocked user
 * (three strikes or more) whose orders were not cancelled automatically — because of old
 * data entered before auto-cancel was switched on, or orders created after the block.
 *
 * It leans on OrderModel::cancelAllPendingForUser() (a safe transaction that respects
 * stock_restored, so stock is never returned twice).
 */

// ── Bootstrapping — the exact sequence public/index.php uses ─
require_once __DIR__ . '/../app/config/env_loader.php';
loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/functions.php';

foreach (glob(__DIR__ . '/../app/helpers/*.php') as $helperFile) {
    if (basename($helperFile) !== 'functions.php') {
        require_once $helperFile;
    }
}

spl_autoload_register(function (string $class): void {
    $prefix  = 'App\\';
    $baseDir = dirname(__DIR__) . '/app/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file          = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Core\Database;
use App\Models\OrderModel;

$db = Database::connect();

$blockedUsers = $db->query("
    SELECT u.id FROM users u
    WHERE (SELECT COUNT(*) FROM user_strikes WHERE user_id = u.id) >= 3
")->fetchAll();

if (!$blockedUsers) {
    echo "No blocked users found (strikes >= 3). Nothing to do.\n";
    exit(0);
}

$totalCancelled = 0;

foreach ($blockedUsers as $row) {
    $userId = (int)$row['id'];

    $pendingBefore = (int)$db->query("
        SELECT COUNT(*) FROM orders
        WHERE user_id = {$userId} AND status IN ('not_taken', 'taken')
    ")->fetchColumn();

    if ($pendingBefore === 0) {
        echo "User #{$userId}: no pending orders — skipped.\n";
        continue;
    }

    OrderModel::cancelAllPendingForUser($userId);

    $pendingAfter = (int)$db->query("
        SELECT COUNT(*) FROM orders
        WHERE user_id = {$userId} AND status IN ('not_taken', 'taken')
    ")->fetchColumn();

    $cancelled = $pendingBefore - $pendingAfter;
    $totalCancelled += $cancelled;
    echo "User #{$userId}: cancelled {$cancelled} pending order(s).\n";
}

echo "Done. Processed " . count($blockedUsers) . " blocked user(s), " . $totalCancelled . " order(s) cancelled.\n";
exit(0);
