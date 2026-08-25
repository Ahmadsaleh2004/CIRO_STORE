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
        echo '<p class="text-center py-3" style="color:var(--muted-text);">No actions recorded yet.</p>';
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
                    <td><code style="font-size:.78rem;"><?= htmlspecialchars($log['action']) ?></code></td>
                    <td style="font-size:.8rem;color:var(--muted-text);">
                        <?= htmlspecialchars($log['target_type'] ?? '—') ?>
                        <?= $log['target_id'] ? '#' . (int)$log['target_id'] : '' ?>
                    </td>
                    <td style="font-size:.8rem;"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
                    <td style="font-size:.78rem;color:var(--muted-text);white-space:nowrap;">
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

<!-- ── Page Header ────────────────────────────────────────── -->
<div class="admin-page-header">
    <h1>🔍 Admin Details</h1>
    <div class="d-flex gap-2">
        <?php if (AdminModel::canManageTarget($adminRole, $target['role'])): ?>
        <a href="<?= URLROOT ?>/admin/admins/edit?id=<?= (int)$target['id'] ?>"
           class="btn btn-outline-primary btn-sm">✏️ Edit Permissions</a>
        <?php endif; ?>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     Section 1 — Basic Info
     ════════════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-3">👤 Basic Info</h5>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <tbody>
                        <tr>
                            <td style="width:40%;color:var(--muted-text);">ID</td>
                            <td><strong><?= (int)$target['id'] ?></strong></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Full Name</td>
                            <td><?= htmlspecialchars($target['full_name']) ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Email</td>
                            <td><?= htmlspecialchars($target['email']) ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Phone</td>
                            <td><?= htmlspecialchars($target['phone_number'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Role</td>
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
                            <td style="color:var(--muted-text);">Joined</td>
                            <td><?= htmlspecialchars(date('M j, Y', strtotime($target['created_at']))) ?></td>
                        </tr>
                        <?php if (!empty($target['last_modified_at'])): ?>
                        <tr>
                            <td style="color:var(--muted-text);">Last Modified</td>
                            <td><?= htmlspecialchars(date('M j, Y H:i', strtotime($target['last_modified_at']))) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Permissions Summary -->
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
                <div class="perm-item" style="<?= empty($target[$key]) ? 'opacity:.4;' : '' ?>">
                    <input type="checkbox" <?= !empty($target[$key]) ? 'checked' : '' ?> disabled>
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
    <div class="table-responsive">
        <table class="table admin-table mb-0">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orderRows as $o):
                    $wasAutoReleased = !empty($o['was_auto_released']);
                    $sc = match($o['status']) {
                        'completed' => 'bg-success',
                        'cancelled' => 'bg-danger',
                        'taken'     => 'bg-primary',
                        default     => 'bg-warning text-dark',
                    };
                ?>
                <tr>
                    <td class="fw-semibold">
                        <a href="<?= URLROOT ?>/admin/orders/details?id=<?= (int)$o['order_id'] ?>"
                           class="fw-semibold">#<?= (int)$o['order_id'] ?></a>
                    </td>
                    <td>
                        <?php if ($wasAutoReleased): ?>
                        <span class="badge bg-secondary" title="This order timed out while held by this admin and was returned to Not Taken.">⏱ Auto-Released</span>
                        <?php else: ?>
                        <span class="badge <?= $sc ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $o['status']))) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>$<?= number_format($o['total_amount'], 2) ?></td>
                    <td style="font-size:.8rem;color:var(--muted-text);white-space:nowrap;"><?= htmlspecialchars(date('M j, Y H:i', strtotime($o['created_at']))) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="text-center py-3" style="color:var(--muted-text);">
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
                    <td><code style="font-size:.78rem;"><?= htmlspecialchars($log['action']) ?></code></td>
                    <td style="font-size:.8rem;"><?= $log['target_id'] ? '#' . (int)$log['target_id'] : '—' ?></td>
                    <td style="font-size:.8rem;"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
                    <td style="font-size:.78rem;color:var(--muted-text);white-space:nowrap;">
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

<!-- ════════════════════════════════════════════════════════
     Section 5 — Admin Actions Log (admin_audit_log)
     هذا متاح الآن — الجدول موجود ومسجّل بالعمليات الحالية
     ═══════════════════════════════════════════════════════ -->
<div class="card p-4 mb-4">
    <h5 class="mb-3">🗂 Admin Actions Log</h5>
    <?php renderAuditRowsTable($auditLog); ?>
</div>
