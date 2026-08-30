<?php
/**
 * app/views/admin/backup.php — a fragment only.
 * The variables from BackupController::index():
 *   $backups, $pageTitle, $csrf, $adminName, $adminRole, $adminId
 * The JavaScript responsible: backup.js (the create button and the delete buttons).
 */
?>
<?php // ── Page Header ────────────────────────────────────────── ?>
<div class="admin-page-header">
    <h1>💾 Backup DB</h1>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" id="createBackupBtn" class="btn btn-success btn-sm">
            ➕ Create Backup Now
        </button>
    </div>
</div>

<?php
/*
 * No flash block here, deliberately. The page used to include
 * shared/flash-messages.php, and **the block never once executed**: BackupController
 * reads no $_SESSION['flash_*'] and passes no $flashMsg or $flashErr at all (unlike five
 * other controllers, which do). Nor is there any redirect to /admin/backup anywhere in
 * the project that sets a flash message — the page is only reached by navigation, its
 * three endpoints return JSON or a file download, and their results reach the user
 * through a toast in backup.js.
 */
?>

<input type="hidden" name="csrf_token" id="backupCsrfToken" value="<?= htmlspecialchars($csrf) ?>">

<?php // ── Backups Table ──────────────────────────────────────── ?>
<div class="card p-0">
    <div class="table-responsive">
        <table class="table admin-table mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Created</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="backupsTableBody">
            <?php if (empty($backups)): ?>
                <?php
                $emptyColspan = 5;
                $emptyMessage = 'No backups yet. Click "Create Backup Now" to start.';
                require APPROOT . '/views/shared/table-empty-row.php';
                ?>
            <?php else: ?>
                <?php foreach ($backups as $i => $b): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($b['filename']) ?></td>
                    <td><?= htmlspecialchars($b['size_human']) ?></td>
                    <td class="u-muted u-fs-80"><?= htmlspecialchars($b['date']) ?></td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            <a class="btn btn-sm btn-outline-primary"
                               href="<?= URLROOT ?>/admin/backup/download?file=<?= urlencode($b['filename']) ?>"
                               title="Download">⬇️</a>
                            <button type="button" class="btn btn-sm btn-outline-danger backup-delete-btn"
                                    data-file="<?= htmlspecialchars($b['filename'], ENT_QUOTES) ?>"
                                    title="Delete backup">🗑</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>