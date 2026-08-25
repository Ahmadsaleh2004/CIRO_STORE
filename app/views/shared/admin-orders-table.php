<?php
/**
 * app/views/shared/admin-orders-table.php
 * جدول طلبات مصغّر داخل صفحة تفاصيل (مستخدم أو أدمن).
 *
 * كان الجدول مكتوباً مرتين بنفس البنية: admin/users/details.php
 * (طلبات المستخدم) و admin/manage-admins/details.php (الطلبات التي
 * ناولها هذا الأدمن). ثلاثة وثلاثون سطراً في كلٍّ منهما — نفس الوسوم
 * ونفس الأعمدة الأربعة — والفرق الوحيد حالة «مُحرَّر تلقائياً» التي
 * تخصّ صفحة الأدمن.
 *
 * المتغيرات:
 *   $tableOrders       array  صفوف الطلبات (order_id · status ·
 *                             total_amount · created_at)
 *   $showAutoReleased  bool   اعرض بادج ⏱ Auto-Released للصفوف التي
 *                             تحمل was_auto_released — صفحة الأدمن فقط
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
                    $badgeSize   = '';
                    $badgeLabel  = '';
                    require APPROOT . '/views/shared/order-status-badge.php';
                    ?>
                    <?php endif; ?>
                </td>
                <td>$<?= number_format($o['total_amount'], 2) ?></td>
                <td style="font-size:.8rem;color:var(--muted-text);white-space:nowrap;"><?= htmlspecialchars(date('M j, Y H:i', strtotime($o['created_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
