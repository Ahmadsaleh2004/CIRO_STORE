<?php

use App\Models\AdminModel;

/**
 * app/views/admin/manage-admins/index.php — fragment فقط
 * المتغيرات من AdminManageAdminsController::index():
 *   $admins, $flashMsg, $flashErr, $csrf, $adminRole, $adminId
 */
?>
<!-- ── Page Header ────────────────────────────────────────── -->
<div class="admin-page-header">
    <h1>👑 Manage Admins <span class="badge bg-secondary fw-normal" style="font-size:.9rem;vertical-align:middle;"><?= (int)$totalAdmins ?></span></h1>
    <div class="d-flex gap-2 flex-wrap">
        <?php
            $exportCsvUrl = URLROOT . '/admin/admins/export-csv';
            include __DIR__ . '/../inc/export-csv-button.php';
        ?>
        <a href="<?= URLROOT ?>/admin/admins/add" class="btn btn-success btn-sm">+ Add Admin</a>
        <button class="btn btn-outline-info btn-sm"
                data-bs-toggle="modal"
                data-bs-target="#broadcastModal">📢 Broadcast to Admins</button>
    </div>
</div>

<!-- ── Flash Messages ─────────────────────────────────────── -->
<?php require APPROOT . '/views/shared/flash-messages.php'; ?>

<!-- ── Admins Table ───────────────────────────────────────── -->
<div class="card p-0">
    <div class="table-responsive">
        <table class="table admin-table mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Perms</th>
                    <th>Joined</th>
                    <th>Last Modified</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($admins)): ?>
                <tr>
                    <td colspan="9" class="text-center py-4"
                        style="color:var(--muted-text);">No admins found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($admins as $adm): ?>
                <tr class="clickable-row"
                    data-href="<?= URLROOT ?>/admin/admins/details?id=<?= (int)$adm['id'] ?>">
                    <td><?= (int)$adm['id'] ?></td>
                    <td>
                        <strong><?= htmlspecialchars($adm['full_name']) ?></strong>
                        <?php if ((int)$adm['id'] === (int)$adminId): ?>
                        <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">You</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($adm['email']) ?></td>
                    <td><?= htmlspecialchars($adm['phone_number'] ?? '—') ?></td>
                    <td>
                        <span class="badge <?= match($adm['role']) {
                            'A' => 'bg-danger',
                            'B' => 'bg-primary',
                            'C' => 'bg-info text-dark',
                            default => 'bg-secondary'
                        } ?>">
                            <?= htmlspecialchars($adm['role']) ?>
                        </span>
                    </td>
                    <td style="font-size:.75rem;">
                        <?php
                        $permMap = [
                            'can_manage_admins'            => 'Admins',
                            'can_manage_products'          => 'Products',
                            'can_manage_users'             => 'Users',
                            'can_view_dashboard'           => 'Dashboard',
                            'can_manage_support'           => 'Support',
                            'can_edit_site_content'        => 'Content',
                            'can_manage_checkout_settings' => 'Checkout',
                            'can_manage_orders'            => 'Orders',
                            'can_manage_branding'          => 'Branding',
                        ];
                        $active = [];
                        foreach ($permMap as $key => $label) {
                            if (!empty($adm[$key])) $active[] = $label;
                        }
                        echo $active
                            ? '<span style="color:var(--accent);">' . implode(', ', $active) . '</span>'
                            : '<span style="color:var(--muted-text);">—</span>';
                        ?>
                    </td>
                    <td style="color:var(--muted-text);font-size:.8rem;">
                        <?= htmlspecialchars(date('M j, Y', strtotime($adm['created_at']))) ?>
                    </td>
                    <td style="color:var(--muted-text);font-size:.8rem;">
                        <?php if (!empty($adm['updated_at']) && $adm['updated_at'] !== $adm['created_at']): ?>
                            <?= htmlspecialchars(date('M j, Y', strtotime($adm['updated_at']))) ?>
                            <?php if (!empty($adm['last_modified_by_name'])): ?>
                                <br><span style="font-size:.7rem;">by <?= htmlspecialchars($adm['last_modified_by_name']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--muted-text);">—</span>
                        <?php endif; ?>
                    </td>
                    <!-- onclick="event.stopPropagation()" إلزامي — يمنع تفعيل clickable-row -->
                    <td onclick="event.stopPropagation()">
                        <?php if (AdminModel::canManageTarget($adminRole, $adm['role'])): ?>
                        <button class="btn btn-sm btn-outline-primary me-1"
                                onclick="openPermModal(
                                    <?= (int)$adm['id'] ?>,
                                    '<?= htmlspecialchars(addslashes($adm['full_name'])) ?>',
                                    '<?= htmlspecialchars($adm['role']) ?>',
                                    <?= (int)($adm['can_manage_admins']            ?? 0) ?>,
                                    <?= (int)($adm['can_manage_products']          ?? 0) ?>,
                                    <?= (int)($adm['can_manage_users']             ?? 0) ?>,
                                    <?= (int)($adm['can_view_dashboard']           ?? 0) ?>,
                                    <?= (int)($adm['can_manage_support']           ?? 0) ?>,
                                    <?= (int)($adm['can_edit_site_content']        ?? 0) ?>,
                                    <?= (int)($adm['can_manage_checkout_settings'] ?? 0) ?>,
                                    <?= (int)($adm['can_manage_orders']            ?? 0) ?>,
                                    <?= (int)($adm['can_manage_branding']          ?? 0) ?>
                                )"
                                title="Edit permissions">✏️</button>                        <button class="btn btn-sm btn-outline-info me-1"
                                onclick="openNotifyModal('admin', <?= (int)$adm['id'] ?>, '<?= htmlspecialchars(addslashes($adm['full_name'])) ?>')"
                                title="Send message">🔔</button>
                        <button class="btn btn-sm btn-outline-danger del-admin-btn"
                                data-id="<?= (int)$adm['id'] ?>"
                                data-name="<?= htmlspecialchars($adm['full_name'], ENT_QUOTES) ?>"
                                title="Delete admin">🗑️ Delete</button>
                        <?php else: ?>
                        <span style="color:var(--muted-text);font-size:.75rem;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Modals ─────────────────────────────────────────────── -->
<?php include __DIR__ . '/../notify-modal.php'; ?>
<?php include __DIR__ . '/../broadcast-form.php'; ?>
<?php include __DIR__ . '/_perm-modal.php'; ?>
