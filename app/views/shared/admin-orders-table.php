<?php
/**
 * app/views/shared/admin-orders-table.php
 * A compact orders table inside a details page (a user's or an admin's).
 *
 * The table used to be written twice with the same structure: admin/users/details.php
 * (the user's orders) and admin/manage-admins/details.php (the orders this admin
 * handled). Thirty-three lines in each — the same markup and the same four columns — with
 * the only difference being the "auto-released" state, which belongs to the admin page.
 *
 * The variables:
 *   $tableOrders       array  The order rows (order_id · status ·
 *                             total_amount · created_at)
 *   $showAutoReleased  bool   Show the ⏱ Auto-Released badge on rows carrying
 *                             was_auto_released — the admin page alone
 */

$showAutoReleased = $showAutoReleased ?? false;
?>
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
            <?php foreach ($tableOrders as $o): ?>
            <tr>
                <td class="fw-semibold">
                    <a href="<?= URLROOT ?>/admin/orders/details?id=<?= (int)$o['order_id'] ?>"
                       class="fw-semibold">#<?= (int)$o['order_id'] ?></a>
                </td>
                <td>
                    <?php if ($showAutoReleased && !empty($o['was_auto_released'])): ?>
                    <span class="badge bg-secondary" title="This order timed out while held by this admin and was returned to Not Taken.">⏱ Auto-Released</span>
                    <?php else: ?>
                    <?php
                    $orderStatus = $o['status'];
                    $badgeExtraClass = '';
                    $badgeLabel  = '';
                    require APPROOT . '/views/shared/order-status-badge.php';
                    ?>
                    <?php endif; ?>
                </td>
                <td>$<?= number_format($o['total_amount'], 2) ?></td>
                <td class="u-meta-80"><?= htmlspecialchars(date('M j, Y H:i', strtotime($o['created_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
