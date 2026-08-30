<?php
/**
 * app/views/admin/orders/details.php — a fragment only.
 * The variables from AdminOrdersController::details():
 *   $order, $items, $productNames, $userStrikes, $remSeconds
 *   + injected automatically: $adminRole, $adminId, $csrf
 * The JavaScript responsible: orders.js (takeBtn/countdown/reportBtn/deliverBtn/cancelDelBtn)
 */

// The status badge and the handler line.
// The status badge is built by shared/order-status-badge.php — its output is captured
// into a variable because it is printed inside an <h1> some lines below here.
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

<?php // ── Page Header + Take Button ──────────────────────────── ?>
<div class="admin-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h1>
        🧾 Order #<?= (int)$order['order_id'] ?>
        <?php // @escaping-safe: HTML built by this file — the variable values are escaped as it is built ?>
        <?= $statusBadge ?>
    </h1>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <?php if ($order['status'] === 'not_taken'): ?>
            <button type="button" id="takeBtn" class="btn btn-success" data-action="take-order">💚 Take It</button>
        <?php elseif ($order['status'] === 'taken'): ?>
            <?php $isHolder = ((int)($order['taken_by_admin_id'] ?? 0) === (int)$adminId); ?>
            <button type="button" id="takeBtn" class="btn btn-danger"
                    <?= $isHolder ? 'data-action="release-order"' : 'disabled' ?>>🔴 Taken</button>
            <div id="countdown" class="badge bg-dark text-light fs-6">--:--:--</div>
        <?php endif; ?>
    </div>
</div>
<?php if ($handlerLine): ?>
<?php // @escaping-safe: HTML built by this file — the variable values are escaped as it is built ?>
<p class="text-muted small mb-4"><?= $handlerLine ?></p>
<?php endif; ?>

<div class="row g-4">
    <?php /*
════════════════════════════════════════════════════════
         The left (main) column — col-lg-8
         ════════════════════════════════════════════════════════
*/ ?>
    <div class="col-12 col-lg-8">

        <?php // 📋 Order Information ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">📋 Order Information</h5>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <tbody>
                        <tr>
                            <td class="u-label-cell">Order ID</td>
                            <td><strong>#<?= (int)$order['order_id'] ?></strong></td>
                        </tr>
                        <tr>
                            <td class="u-muted">Payment Method</td>
                            <td><?= htmlspecialchars($order['payment_method']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php // 🛍️ Items Ordered ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">🛍️ Items Ordered (<?= count($items) ?>)</h5>
            <?php if (empty($items)): ?>
            <p class="text-center py-3 u-muted">No items found for this order.</p>
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
                                         alt="" class="u-thumb-42">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></div>
                                        <?php if (!empty($item['color_name'])): ?>
                                        <small class="u-muted">Color: <?= htmlspecialchars($item['color_name']) ?></small>
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

        <?php // 📍 Shipping Details ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">📍 Shipping Details</h5>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <tbody>
                        <tr>
                            <td class="u-label-cell">Label</td>
                            <td><?= !empty($order['address_label']) ? htmlspecialchars($order['address_label']) : '<span class="u-muted">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td class="u-muted">Phone</td>
                            <td><?= !empty($order['shipping_phone']) ? htmlspecialchars($order['shipping_phone']) : '<span class="u-muted">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td class="u-muted">Address</td>
                            <td><?= !empty($order['full_address']) ? htmlspecialchars($order['full_address']) : '<span class="u-muted">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td class="u-muted">City / Country</td>
                            <td>
                                <?php $location = trim(($order['city'] ?? '') . ', ' . ($order['country'] ?? ''), ', '); ?>
                                <?= $location !== '' ? htmlspecialchars($location) : '<span class="u-muted">—</span>' ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php // 🚨 Report an Issue ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">🚨 Report an Issue</h5>
            <textarea id="reportReason" class="form-control mb-2" rows="3"
                      placeholder="Describe the issue with this order..."></textarea>
            <button type="button" id="reportBtn" class="btn btn-outline-danger" data-action="submit-report" disabled>🚨 Report Issue</button>
        </div>

    </div>

    <?php /*
════════════════════════════════════════════════════════
         The right (side) column — col-lg-4
         ════════════════════════════════════════════════════════
*/ ?>
    <div class="col-12 col-lg-4">

        <?php // 👤 Client ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">👤 Client</h5>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <tbody>
                        <tr>
                            <td class="u-label-cell">Name</td>
                            <td class="fw-semibold"><?= htmlspecialchars($order['user_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="u-muted">Email</td>
                            <td><?= htmlspecialchars($order['user_email']) ?></td>
                        </tr>
                        <tr>
                            <td class="u-muted">Phone</td>
                            <td><?= !empty($order['user_phone']) ? htmlspecialchars($order['user_phone']) : '<span class="u-muted">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td class="u-muted">Strikes</td>
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

        <?php // ⚙️ Delivery Actions ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">⚙️ Delivery Actions</h5>
            <?php if ($order['status'] === 'taken'): ?>
                <div class="d-grid gap-2">
                    <button type="button" id="deliverBtn" class="btn btn-success" data-action="update-delivery" data-delivery="mark_delivered">✅ Mark as Delivered</button>
                    <button type="button" id="cancelDelBtn" class="btn btn-outline-danger" data-action="update-delivery" data-delivery="cancel_delivery">❌ Cancel Delivery</button>
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
// The page's data for JavaScript.
//
// ⚠️ It used to be assembled by string concatenation inside a <script>:
//     'orderId: ' . (int)$order['order_id'] . ','
// which is building JavaScript out of strings — it works here because every value is
// cast or encoded, but it is a shape where one oversight (a string value concatenated
// without json_encode) becomes script injection. Now the data is data, and json_encode
// inside pageData handles all the encoding — including </script>, through JSON_HEX_TAG.
//
// ⚠️⚠️ And it is printed **here in the view's body**, not through $extraScripts.
//
// It used to be assigned to $extraScripts, which makes the island print in
// admin/inc/footer.php **after** the js/core/page-data.js tag. And that file is
// synchronous rather than deferred — it executes the moment the parser reaches it — so
// it scanned the document at that point and found only the head and navbar islands. This
// page's island had not been created yet, so it never reached window at all.
//
// The result was a complete, silent outage: orders.js calls initOrderDetails on the
// condition `typeof window.ADMIN_ORDER_DETAILS !== 'undefined'` — a condition that never
// once held. And window.handleTakeIt is defined inside that function. So the "Take It"
// button was clicked, inline-actions.js delegated the action, found no such function,
// wrote a line to the console and stopped. No dialog, no request, no message — it looked
// as though taking orders was broken on the server, while the server was perfectly sound.
//
// Here the island precedes the footer, so page-data.js picks it up like all the others.
echo pageData([
    'ADMIN_ORDER_DETAILS' => [
        'orderId'      => (int) $order['order_id'],
        'productNames' => $productNames,
        'orderDate'    => date('d M Y', strtotime($order['created_at'])),
        'userId'       => (int) $order['user_id'],
        'remSeconds'   => (int) $remSeconds,
    ],
]);
?>