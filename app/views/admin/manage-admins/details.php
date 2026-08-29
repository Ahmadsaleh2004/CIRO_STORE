<?php

use App\Models\AdminModel;

/**
 * app/views/admin/manage-admins/details.php — fragment فقط
 * المتغيرات من AdminManageAdminsController::details():
 *   $target             — بيانات الأدمن + صلاحياته
 *   $orderRows          — طلبات تولّاها هذا الأدمن (OrderModel::getOrdersHandledByAdmin)
 *   $orderAuditRows     — بلاغات مشاكل وتصدير CSV لطلبات (target_type='orders' غير المشمولة بـ orderRows)
 *   $profitTotal        — مجموع total_amount للطلبات المكتملة فقط (غير auto-released)
 *   $userActionRows     — target_type='user'
 *   $productActionRows  — target_type IN ('product','category')
 *   $brandingActionRows — target_type='branding'
 *   $supportActionRows  — target_type='support'
 *   $siteConfigRows     — target_type='website_settings'
 *   $auditLog           — كل شيء آخر (target_type='admin' + NULL) — لا يُفلتر حسب صلاحية
 *   $csrf
 */

/**
 * جدول سجل تدقيق عام مُعاد استخدامه لكل الأقسام المتخصصة (User/Product/Branding/Support/Site Config).
 */
function renderAuditRowsTable(array $rows): void
{
    if (empty($rows)) {
        echo '<p class="text-center py-3 u-muted">No actions recorded yet.</p>';
        return;
    }
    ?>
    <div class="table-responsive">
        <table class="table admin-table mb-0">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Details</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $log): ?>
                <tr>
                    <td><code class="u-fs-78"><?= htmlspecialchars($log['action']) ?></code></td>
                    <td class="u-fs-80 u-muted">
                        <?= htmlspecialchars($log['target_type'] ?? '—') ?>
                        <?= $log['target_id'] ? '#' . (int)$log['target_id'] : '' ?>
                    </td>
                    <td class="u-fs-80"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
                    <td class="u-meta-78">
                        <?= htmlspecialchars(date('M j, Y H:i', strtotime($log['created_at']))) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>

<?php // ── Page Header ────────────────────────────────────────── ?>
<div class="admin-page-header">
    <h1>🔍 Admin Details</h1>
    <div class="d-flex gap-2">
        <?php if (AdminModel::canManageTarget($adminRole, $target['role'])): ?>
        <a href="<?= URLROOT ?>/admin/admins/edit?id=<?= (int)$target['id'] ?>"
           class="btn btn-outline-primary btn-sm">✏️ Edit Permissions</a>
        <?php endif; ?>
    </div>
</div>

<?php /*
════════════════════════════════════════════════════════
     Section 1 — Basic Info
     ════════════════════════════════════════════════════════
*/ ?>
<div class="row g-4 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-3">👤 Basic Info</h5>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <tbody>
                        <tr>
                            <td class="u-label-cell">ID</td>
                            <td><strong><?= (int)$target['id'] ?></strong></td>
                        </tr>
                        <tr>
                            <td class="u-muted">Full Name</td>
                            <td><?= htmlspecialchars($target['full_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="u-muted">Email</td>
                            <td><?= htmlspecialchars($target['email']) ?></td>
                        </tr>
                        <tr>
                            <td class="u-muted">Phone</td>
                            <td><?= htmlspecialchars($target['phone_number'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td class="u-muted">Role</td>
                            <td>
                                <span class="badge <?= match($target['role']) {
                                    'A' => 'bg-danger',
                                    'B' => 'bg-primary',
                                    'C' => 'bg-info text-dark',
                                    default => 'bg-secondary'
                                } ?>">
                                    <?= htmlspecialchars($target['role']) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="u-muted">Joined</td>
                            <td><?= htmlspecialchars(date('M j, Y', strtotime($target['created_at']))) ?></td>
                        </tr>
                        <?php if (!empty($target['last_modified_at'])): ?>
                        <tr>
                            <td class="u-muted">Last Modified</td>
                            <td><?= htmlspecialchars(date('M j, Y H:i', strtotime($target['last_modified_at']))) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php // Permissions Summary ?>
    <div class="col-12 col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-3">🔐 Permissions</h5>
            <?php
            $permMap = [
                'can_manage_admins'            => ['👑', 'Manage Admins'],
                'can_manage_products'          => ['🛍️', 'Manage Products'],
                'can_manage_users'             => ['👥', 'Manage Users'],
                'can_view_dashboard'           => ['📊', 'View Dashboard'],
                'can_manage_support'           => ['💬', 'Manage Support'],
                'can_edit_site_content'        => ['⚙️', 'Edit Site Content'],
                'can_manage_checkout_settings' => ['💳', 'Checkout Settings'],
                'can_manage_orders'            => ['📦', 'Manage Orders'],
                'can_manage_branding'          => ['🎬', 'Manage Branding (Slider)'],
            ];
            ?>
            <div class="perm-grid">
                <?php foreach ($permMap as $key => [$icon, $label]): ?>
                <div class="perm-item <?= empty($target[$key]) ? 'u-dimmed' : '' ?>">
                    <input type="checkbox" <?= !empty($target[$key]) ? 'checked' : '' ?> disabled>
                    <?php // @escaping-safe: $icon و$label من خريطة حرفية في هذا الملف ?>
                    <span><?= $icon ?> <?= $label ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($target['can_manage_orders'])): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">📦 Orders Activity</h5>
    <?php if (!empty($orderRows)): ?>
    <p class="text-muted small mb-3">
        Total completed profit: <strong>$<?= number_format($profitTotal, 2) ?></strong>
        (<?= count($orderRows) ?> orders handled)
    </p>
    <?php
    $tableOrders      = $orderRows;
    $showAutoReleased = true;
    require APPROOT . '/views/shared/admin-orders-table.php';
    ?>
    <?php else: ?>
    <p class="text-center py-3 u-muted">
        No orders handled by this admin yet.
    </p>
    <?php endif; ?>

    <?php if (!empty($orderAuditRows)): ?>
    <hr>
    <h6 class="mb-2 mt-3">Other Order Actions</h6>
    <div class="table-responsive">
        <table class="table admin-table mb-0">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Order</th>
                    <th>Details</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orderAuditRows as $log): ?>
                <tr>
                    <td><code class="u-fs-78"><?= htmlspecialchars($log['action']) ?></code></td>
                    <td class="u-fs-80"><?= $log['target_id'] ? '#' . (int)$log['target_id'] : '—' ?></td>
                    <td class="u-fs-80"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
                    <td class="u-meta-78">
                        <?= htmlspecialchars(date('M j, Y H:i', strtotime($log['created_at']))) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($target['can_manage_users'])): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">👥 User-Related Actions</h5>
    <?php renderAuditRowsTable($userActionRows); ?>
</div>
<?php endif; ?>

<?php if (!empty($target['can_manage_products'])): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">🛍️ Product-Related Actions</h5>
    <?php renderAuditRowsTable($productActionRows); ?>
</div>
<?php endif; ?>

<?php if (!empty($target['can_manage_products'])): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">🛍️ Product-Related Actions</h5>
    <?php renderAuditRowsTable($productActionRows); ?>
</div>
<?php endif; ?>

<?php if (!empty($target['can_manage_branding'])): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">🎬 Branding Actions</h5>
    <?php renderAuditRowsTable($brandingActionRows); ?>
</div>
<?php endif; ?>

<?php if (!empty($target['can_manage_support'])): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">💬 Support Actions</h5>
    <?php renderAuditRowsTable($supportActionRows); ?>
</div>
<?php endif; ?>

<?php if (!empty($target['can_edit_site_content'])): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">⚙️ Site Configuration</h5>
    <?php renderAuditRowsTable($siteConfigRows); ?>
</div>
<?php endif; ?>

<?php /*
════════════════════════════════════════════════════════
     Section 5 — Admin Actions Log (admin_audit_log)
     هذا متاح الآن — الجدول موجود ومسجّل بالعمليات الحالية
     ═══════════════════════════════════════════════════════
*/ ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">🗂 Admin Actions Log</h5>
    <?php renderAuditRowsTable($auditLog); ?>
</div>
