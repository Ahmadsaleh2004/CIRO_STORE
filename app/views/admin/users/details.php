<?php

/**
 * app/views/admin/users/details.php — fragment فقط
 * المتغيرات من AdminUsersController::details():
 *   $target, $addresses, $orders, $strikes, $auditLog, $messages
 *   + تلقائية: $adminRole, $adminId, $csrf
 * JS: دوائر الـ strikes (.strike-btn) يتعامل معها users.js
 *     openNotifyModal من admins.js
 */

// إحصائيات الطلبات محسوبة بالـ view من $orders الممررة (بدون استعلام إضافي)
$completedOrders = array_filter($orders, fn($o) => ($o['status'] ?? '') === 'completed');
$completedTotal  = array_sum(array_map(fn($o) => (float)$o['total_amount'], $completedOrders));
$strikesCount    = (int)$target['strikes_count'];
$strikesBadge    = $strikesCount >= 3 ? 'bg-danger' : ($strikesCount > 0 ? 'bg-warning text-dark' : 'bg-success');
$strikesLabel    = $strikesCount >= 3 ? 'Blocked' : ($strikesCount > 0 ? 'Warnings' : 'Clean');
?>

<!-- ── Page Header ────────────────────────────────────────── -->
<div class="admin-page-header">
    <h1>🔍 User Details</h1>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-info btn-sm"
                onclick="openNotifyModal('user', <?= (int)$target['id'] ?>,
                         '<?= htmlspecialchars(addslashes($target['full_name']), ENT_QUOTES) ?>')">
            🔔 Send Message
        </button>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     Section 1+2 — Basic Info | Strikes
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
                            <td><?= !empty($target['phone_number']) ? htmlspecialchars($target['phone_number']) : '<span style="color:var(--muted-text)">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Country/City</td>
                            <td>
                                <?php $location = trim(($target['city'] ?? '') . ', ' . ($target['country'] ?? ''), ', '); ?>
                                <?= $location !== '' ? htmlspecialchars($location) : '<span style="color:var(--muted-text)">—</span>' ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Gender</td>
                            <td><?= !empty($target['gender']) ? htmlspecialchars(ucfirst($target['gender'])) : '<span style="color:var(--muted-text)">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Joined</td>
                            <td><?= htmlspecialchars(date('M j, Y', strtotime($target['created_at']))) ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Last Activity</td>
                            <td><?= !empty($target['last_activity']) ? htmlspecialchars(date('M j, Y H:i', strtotime($target['last_activity']))) : '<span style="color:var(--muted-text)">—</span>' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-3">⚠️ Account Strikes
                <span class="badge <?= $strikesBadge ?>"><?= $strikesCount ?>/3 — <?= htmlspecialchars($strikesLabel) ?></span>
            </h5>

            <div id="strikesContainer">
            <?php
            // الأقدم أولًا — خاص بعرض الدوائر فقط عشان رقم الدائرة يطابق ترتيب الصدور 1→2→3
            // (لا تغيّر getStrikes() نفسها — مرتبة DESC لمكان تاني)
            $strikesForCircles = array_reverse($strikes);
            for ($i = 1; $i <= 3; $i++):
                $strike = $strikesForCircles[$i - 1] ?? null;
                $active = !empty($strike);
            ?>
            <div class="strike-row" id="strike-row-<?= $i ?>">
                <button type="button"
                        class="strike-btn <?= $active ? 'active' : '' ?>"
                        data-index="<?= $i ?>"
                        data-strike-id="<?= $active ? (int)$strike['id'] : 0 ?>"
                        data-active="<?= $active ? '1' : '0' ?>"
                        data-user-id="<?= (int)$target['id'] ?>"
                        title="<?= $active ? 'Click to remove this strike' : 'Click to add a strike' ?>">
                    <?= $active ? '❌' : $i ?>
                </button>
                <div class="strike-reason">
                    <?php if ($active): ?>
                        <div class="reason-label">Strike #<?= $i ?></div>
                        <div><?= htmlspecialchars($strike['reason']) ?></div>
                        <div class="reason-date"><?= htmlspecialchars(date('M j, Y H:i', strtotime($strike['created_at']))) ?></div>
                    <?php else: ?>
                        <span style="color:var(--muted-text);">Strike #<?= $i ?> — No warning issued</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endfor; ?>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     Section 3 — Saved Addresses
     ════════════════════════════════════════════════════════ -->
<div class="card p-4 mb-4">
    <h5 class="mb-3">🏠 Saved Addresses (<?= count($addresses) ?>)</h5>
    <?php if (empty($addresses)): ?>
    <p class="text-center py-3" style="color:var(--muted-text);">No saved addresses.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table admin-table mb-0">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Country/City</th>
                    <th>Full Address</th>
                    <th>Phone</th>
                    <th>Default</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($addresses as $ad): ?>
                <tr>
                    <td><?= htmlspecialchars($ad['label'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(trim(($ad['country'] ?? '') . ', ' . ($ad['city'] ?? ''), ', ') ?: '—') ?></td>
                    <td><?= htmlspecialchars($ad['full_address'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($ad['phone_number'] ?? '—') ?></td>
                    <td>
                        <?php if (!empty($ad['is_default'])): ?>
                        <span class="badge bg-primary">Default</span>
                        <?php else: ?>
                        <span style="color:var(--muted-text)">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════
     Section 4 — Order History
     ════════════════════════════════════════════════════════ -->
<div class="card p-4 mb-4">
    <h5 class="mb-3">📦 Order History</h5>
    <?php if (empty($orders)): ?>
    <p class="text-center py-3" style="color:var(--muted-text);">No orders yet.</p>
    <?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-4">
            <div class="border rounded p-2 text-center">
                <div class="fs-5 fw-bold"><?= count($orders) ?></div>
                <small style="color:var(--muted-text);">Total Orders</small>
            </div>
        </div>
        <div class="col-4">
            <div class="border rounded p-2 text-center">
                <div class="fs-5 fw-bold"><?= count($completedOrders) ?></div>
                <small style="color:var(--muted-text);">Completed</small>
            </div>
        </div>
        <div class="col-4">
            <div class="border rounded p-2 text-center">
                <div class="fs-5 fw-bold">$<?= number_format($completedTotal, 2) ?></div>
                <small style="color:var(--muted-text);">Completed Total</small>
            </div>
        </div>
    </div>
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
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="fw-semibold">
                        <a href="<?= URLROOT ?>/admin/orders/details?id=<?= (int)$o['order_id'] ?>"
                           class="fw-semibold">#<?= (int)$o['order_id'] ?></a>
                    </td>
                    <td><?php
                        $orderStatus = $o['status'];
                        $badgeSize   = '';
                        $badgeLabel  = '';
                        require APPROOT . '/views/shared/order-status-badge.php';
                    ?></td>
                    <td>$<?= number_format($o['total_amount'], 2) ?></td>
                    <td style="font-size:.8rem;color:var(--muted-text);white-space:nowrap;"><?= htmlspecialchars(date('M j, Y H:i', strtotime($o['created_at']))) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════
     Section — Support Messages
     ════════════════════════════════════════════════════════ -->
<div class="card p-4 mb-4">
    <h5 class="mb-3">💬 Support Messages (<?= count($messages) ?>)</h5>
    <?php if (empty($messages)): ?>
    <p class="text-center py-3" style="color:var(--muted-text);">No messages sent by this user.</p>
    <?php else: ?>
        <?php foreach ($messages as $m): ?>
        <div class="support-msg-card">
            <div class="d-flex justify-content-between mb-1">
                <strong class="small">Message #<?= (int)$m['id'] ?></strong>
                <small style="color:var(--muted-text);"><?= htmlspecialchars(date('M j, Y H:i', strtotime($m['sent_at']))) ?></small>
            </div>
            <p class="mb-0 msg-content"><?= htmlspecialchars($m['message']) ?></p>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════
     Section 5 — Audit Log (آخر قسم)
     ════════════════════════════════════════════════════════ -->
<div class="card p-4 mb-4">
    <h5 class="mb-3">📜 Admin Actions Log (<?= count($auditLog) ?>)</h5>
    <?php if (empty($auditLog)): ?>
    <p class="text-center py-3" style="color:var(--muted-text);">No admin actions recorded for this user.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table admin-table mb-0">
            <thead>
                <tr>
                    <th>Admin</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($auditLog as $log): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= htmlspecialchars($log['admin_name'] ?? '—') ?></td>
                    <td><code style="font-size:.78rem;"><?= htmlspecialchars($log['action']) ?></code></td>
                    <td style="font-size:.8rem;"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
                    <td style="font-size:.78rem;color:var(--muted-text);white-space:nowrap;"><?= htmlspecialchars(date('M j, Y H:i', strtotime($log['created_at']))) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Modals ─────────────────────────────────────────────── -->
<?php include __DIR__ . '/../notify-modal.php'; ?>
