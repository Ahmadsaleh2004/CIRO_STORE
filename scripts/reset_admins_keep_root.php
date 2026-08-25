<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

$db = Database::connect();

try {
    $root = $db->query("SELECT id FROM admins WHERE role='A' LIMIT 1")->fetchColumn();
    echo "Root admin ID: {$root}\n";
    if ((int)$root !== 1) {
        throw new Exception("Root admin ID is {$root}, not 1 — aborting for safety.");
    }

    $db->beginTransaction();

    $stmt = $db->prepare("DELETE FROM admins WHERE id != 1");
    $stmt->execute();
    echo "Deleted rows: " . $stmt->rowCount() . "\n";

    $db->exec("ALTER TABLE admins AUTO_INCREMENT = 2");

    $db->commit();
    echo "Done. Only admin ID=1 (root) remains. Next new admin will get ID=2.\n";
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Aborted: " . $e->getMessage() . "\n";
}