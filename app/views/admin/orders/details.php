<?php
/**
 * app/views/admin/orders/details.php — fragment فقط
 * المتغيرات من AdminOrdersController::details():
 *   $order, $items, $productNames, $userStrikes, $remSeconds
 *   + تلقائية: $adminRole, $adminId, $csrf
 * JS المسؤول: orders.js (takeBtn/countdown/reportBtn/deliverBtn/cancelDelBtn)
 */

// بادج الحالة + سطر المناول
// بادج الحالة يُبنى من shared/order-status-badge.php — تُلتقط مخرجاته
// في متغيّر لأنه يُطبع داخل <h1> بعد سطور من هنا.
$orderStatus = $order['status'];
$badgeExtraClass = 'fs-6';
$badgeLabel  = match($order['status']) {
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'taken'     => 'Taken',
    default     => 'Not Taken',
};
ob_start();
require APPROOT . '/views/shared/order-status-badge.php';
$statusBadge = trim(ob_get_clean());

$handlerLine = '';
if ($order['status'] === 'completed' && !empty($order['handler_admin_name'])) {
    $handlerLine = '✅ Delivered by ' . htmlspecialchars($order['handler_admin_name']);
} elseif ($order['status'] === 'cancelled' && !empty($order['handler_admin_name'])) {
    $handlerLine = '❌ Cancelled by ' . htmlspecialchars($order['handler_admin_name']);
}
?>

<!-- ── Page Header + Take Button ──────────────────────────── -->
<div class="admin-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h1>
        🧾 Order #<?= (int)$order['order_id'] ?>
        <?= $statusBadge ?>
    </h1>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <?php if ($order['status'] === 'not_taken'): ?>
            <button type="button" id="takeBtn" class="btn btn-success" onclick="handleTakeIt()">💚 Take It</button>
        <?php elseif ($order['status'] === 'taken'): ?>
            <?php $isHolder = ((int)($order['taken_by_admin_id'] ?? 0) === (int)$adminId); ?>
            <button type="button" id="takeBtn" class="btn btn-danger"
                    <?= $isHolder ? 'onclick="handleReleaseOrder()"' : 'disabled' ?>>🔴 Taken</button>
            <div id="countdown" class="badge bg-dark text-light fs-6">--:--:--</div>
        <?php endif; ?>
    </div>
</div>
<?php if ($handlerLine): ?>
<p class="text-muted small mb-4"><?= $handlerLine ?></p>
<?php endif; ?>

<div class="row g-4">
    <!-- ════════════════════════════════════════════════════════
         العمود الأيسر (الرئيسي) — col-lg-8
         ════════════════════════════════════════════════════════ -->
    <div class="col-12 col-lg-8">

        <!-- 📋 Order Information -->
        <div class="card p-4 mb-4">
            <h5 class="mb-3">📋 Order Information</h5>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <tbody>
                        <tr>
                            <td style="width:40%;color:var(--muted-text);">Order ID</td>
                            <td><strong>#<?= (int)$order['order_id'] ?></strong></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Payment Method</td>
                            <td><?= htmlspecialchars($order['payment_method']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 🛍️ Items Ordered -->
        <div class="card p-4 mb-4">
            <h5 class="mb-3">🛍️ Items Ordered (<?= count($items) ?>)</h5>
            <?php if (empty($items)): ?>
            <p class="text-center py-3" style="color:var(--muted-text);">No items found for this order.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $subtotal = (float)$item['price_at_purchase'] * (int)$item['quantity'];
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="<?= htmlspecialchars(fixImagePath($item['image_path'] ?? '')) ?>"
                                         alt="" style="width:42px;height:42px;object-fit:cover;border-radius:6px;">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></div>
                                        <?php if (!empty($item['color_name'])): ?>
                                        <small style="color:var(--muted-text);">Color: <?= htmlspecialchars($item['color_name']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= (int)$item['quantity'] ?></td>
                            <td>$<?= number_format($item['price_at_purchase'], 2) ?></td>
                            <td>$<?= number_format($subtotal, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- 📍 Shipping Details -->
        <div class="card p-4 mb-4">
            <h5 class="mb-3">📍 Shipping Details</h5>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <tbody>
                        <tr>
                            <td style="width:40%;color:var(--muted-text);">Label</td>
                            <td><?= !empty($order['address_label']) ? htmlspecialchars($order['address_label']) : '<span style="color:var(--muted-text)">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Phone</td>
                            <td><?= !empty($order['shipping_phone']) ? htmlspecialchars($order['shipping_phone']) : '<span style="color:var(--muted-text)">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Address</td>
                            <td><?= !empty($order['full_address']) ? htmlspecialchars($order['full_address']) : '<span style="color:var(--muted-text)">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">City / Country</td>
                            <td>
                                <?php $location = trim(($order['city'] ?? '') . ', ' . ($order['country'] ?? ''), ', '); ?>
                                <?= $location !== '' ? htmlspecialchars($location) : '<span style="color:var(--muted-text)">—</span>' ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 🚨 Report an Issue -->
        <div class="card p-4 mb-4">
            <h5 class="mb-3">🚨 Report an Issue</h5>
            <textarea id="reportReason" class="form-control mb-2" rows="3"
                      placeholder="Describe the issue with this order..."></textarea>
            <button type="button" id="reportBtn" class="btn btn-outline-danger" onclick="submitReport()" disabled>🚨 Report Issue</button>
        </div>

    </div>

    <!-- ════════════════════════════════════════════════════════
         العمود الأيمن (الجانبي) — col-lg-4
         ════════════════════════════════════════════════════════ -->
    <div class="col-12 col-lg-4">

        <!-- 👤 Client -->
        <div class="card p-4 mb-4">
            <h5 class="mb-3">👤 Client</h5>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <tbody>
                        <tr>
                            <td style="width:40%;color:var(--muted-text);">Name</td>
                            <td class="fw-semibold"><?= htmlspecialchars($order['user_name']) ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Email</td>
                            <td><?= htmlspecialchars($order['user_email']) ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Phone</td>
                            <td><?= !empty($order['user_phone']) ? htmlspecialchars($order['user_phone']) : '<span style="color:var(--muted-text)">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--muted-text);">Strikes</td>
                            <td>
                                <span class="badge <?= $userStrikes >= 3 ? 'bg-danger' : ($userStrikes > 0 ? 'bg-warning text-dark' : 'bg-success') ?>">
                                    <?= (int)$userStrikes ?>/3
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <a href="<?= URLROOT ?>/admin/users/details?id=<?= (int)$order['user_id'] ?>"
               class="btn btn-sm btn-outline-warning mt-3">View Profile</a>
        </div>

        <!-- ⚙️ Delivery Actions -->
        <div class="card p-4 mb-4">
            <h5 class="mb-3">⚙️ Delivery Actions</h5>
            <?php if ($order['status'] === 'taken'): ?>
                <div class="d-grid gap-2">
                    <button type="button" id="deliverBtn" class="btn btn-success" onclick="updateDelivery('mark_delivered')">✅ Mark as Delivered</button>
                    <button type="button" id="cancelDelBtn" class="btn btn-outline-danger" onclick="updateDelivery('cancel_delivery')">❌ Cancel Delivery</button>
                </div>
            <?php elseif ($order['status'] === 'completed'): ?>
                <p class="mb-0 text-muted">✅ This order has been delivered and completed.</p>
            <?php elseif ($order['status'] === 'cancelled'): ?>
                <p class="mb-0 text-muted">❌ This order was cancelled — stock has been restored.</p>
                <div class="mt-3">
                    <?php $orderId = (int)$order['order_id']; $orderContext = 'admin'; ?>
                    <?php include APPROOT . '/views/shared/order-cancel-button.php'; ?>
                </div>
            <?php else: ?>
                <p class="mb-0 text-muted">🚚 Take this order to start delivery actions.</p>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php
// حقن بيانات الصفحة للـ JS (يُخرجها footer.php عبر $extraScripts)
$extraScripts = '<script>
window.ADMIN_ORDER_DETAILS = {
    orderId: ' . (int)$order['order_id'] . ',
    productNames: ' . json_encode($productNames) . ',
    orderDate: ' . json_encode(date('d M Y', strtotime($order['created_at']))) . ',
    userId: ' . (int)$order['user_id'] . ',
    remSeconds: ' . (int)$remSeconds . '
};
</script>';
?>