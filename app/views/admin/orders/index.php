<?php
/**
 * app/views/admin/orders/index.php — fragment فقط
 * المتغيرات من AdminOrdersController::index():
 *   $orders, $totalOrders, $totalPages, $currentPage, $filter, $search,
 *   $flashMsg, $flashErr, $csrf, $adminRole, $adminId
 * JS المسؤول: orders.js (goToOrderDetails / filterStatus / deleteOrder)
 * ملاحظة: زر حذف نهائي (🗑) يظهر فقط على الطلبات بحالة completed أو cancelled.
 */
?>

<!-- ── Page Header ────────────────────────────────────────── -->
<!-- الترويسة تحمل العنوان وأزرار الإجراءات فقط — نفس نمط
     users/index.php و manage-admins/index.php و product/index.php.
     البحث والفلترة انتقلا لصف مستقل تحت الرسائل (انظر أدناه).
     ملاحظة: .admin-page-header معرّف أصلاً كـ flex + space-between +
     wrap + gap في admin.css، فلا حاجة لتكرار كلاسات Bootstrap هنا. -->
<div class="admin-page-header">
    <h1>📦 Manage Orders <span class="badge bg-secondary fw-normal" style="font-size:.9rem;vertical-align:middle;"><?= (int)$totalOrders ?></span></h1>
    <div class="d-flex gap-2 flex-wrap">
        <?php
        $csvParams = http_build_query(array_filter(['q' => $search, 'status' => $filter]));
        $csvUrl    = URLROOT . '/admin/orders/export-csv' . ($csvParams ? '?' . $csvParams : '');
        ?>
        <a href="<?= htmlspecialchars($csvUrl) ?>" download class="btn btn-sm btn-export-csv">📄 Export CSV</a>
    </div>
</div>

<!-- ── Flash Messages ─────────────────────────────────────── -->
<?php require APPROOT . '/views/shared/flash-messages.php'; ?>

<!-- ── Search + Status Filter ─────────────────────────────── -->
<!-- صف واحد مسطّح بنفس بنية users/index.php بالضبط. تجنّبنا وضع فورم
     داخل div خارجي لأن التداخل يجعل الفورم عنصر flex ضيّقًا فيلتف الصف
     إلى سطرين (قيس فعليًا: 77px بدل 38px).
     الـ <select> بلا خاصية name عمدًا: التنقّل يتم فورًا عبر
     filterStatus() في orders.js، والحقل المخفي status هو الذي يحفظ
     الفلتر عند البحث النصي — فلو حمل name لتكرّرت القيمة وتعارضت. -->
<form method="GET" action="<?= URLROOT ?>/admin/orders" class="d-flex gap-2 flex-wrap mb-3">
    <?php if ($filter): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>">
    <?php endif; ?>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
           class="form-control" style="max-width:280px;" placeholder="Order ID or customer...">
    <select class="form-select" style="max-width:180px;" onchange="filterStatus(this.value)">
        <option value="">All Orders</option>
        <option value="not_taken" <?= $filter==='not_taken' ?'selected':'' ?>>Not Taken</option>
        <option value="taken"     <?= $filter==='taken'     ?'selected':'' ?>>Taken</option>
        <option value="cancelled" <?= $filter==='cancelled' ?'selected':'' ?>>Cancelled</option>
        <option value="completed" <?= $filter==='completed' ?'selected':'' ?>>Completed</option>
    </select>
    <button class="btn btn-outline-primary btn-sm">Filter</button>
    <?php if ($search !== '' || $filter): ?>
    <a href="<?= URLROOT ?>/admin/orders" class="btn btn-sm btn-outline-secondary">✕ Clear</a>
    <?php endif; ?>
</form>

<!-- ── Orders Table ───────────────────────────────────────── -->
<div class="card p-0">
    <div class="table-responsive">
        <table class="table admin-table mb-0">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Handled By</th>
                    <th>Date</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($orders)): ?>
                <?php
                $emptyColspan = 8;
                $emptyMessage = 'No orders found.';
                require APPROOT . '/views/shared/table-empty-row.php';
                ?>
            <?php else: ?>
                <?php foreach ($orders as $o): ?>
                <tr onclick="goToOrderDetails(<?= (int)$o['order_id'] ?>)" style="cursor:pointer;">
                    <td class="fw-semibold">#<?= (int)$o['order_id'] ?></td>
                    <td>
                        <span class="fw-semibold"><?= htmlspecialchars($o['full_name']) ?></span>
                        <br><small style="color:var(--muted-text);"><?= htmlspecialchars($o['email']) ?></small>
                    </td>
                    <td>$<?= number_format($o['total_amount'], 2) ?></td>
                    <td><?= htmlspecialchars($o['payment_method']) ?></td>
                    <td><?php
                        $orderStatus = $o['status'];
                        $badgeExtraClass = '';
                        // هذه الصفحة وحدها تكتب "Not Taken" بتاء كبيرة
                        $badgeLabel  = match($o['status']) {
                            'not_taken' => 'Not Taken',
                            'taken'     => 'Taken',
                            'cancelled' => 'Cancelled',
                            'completed' => 'Completed',
                            default     => ucfirst($o['status']),
                        };
                        require APPROOT . '/views/shared/order-status-badge.php';
                    ?></td>
                    <td>
                        <?= !empty($o['handled_by_name'])
                            ? htmlspecialchars($o['handled_by_name'])
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td style="color:var(--muted-text);font-size:.8rem;white-space:nowrap;">
                        <?= htmlspecialchars(date('M j, Y H:i', strtotime($o['created_at']))) ?>
                    </td>
                    <td class="text-center" onclick="event.stopPropagation()">
                        <?php if (in_array($o['status'], ['completed', 'cancelled'], true)): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger delete-order-btn"
                                data-oid="<?= (int)$o['order_id'] ?>"
                                title="Delete order">🗑️ Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Pagination ─────────────────────────────────────────── -->
<?php if ($totalPages > 1):
    // بناء query string الفلاتر للـ pagination — يحافظ على q/status عند التنقل
    $paginationBase = http_build_query(array_filter([
        'q'      => $search,
        'status' => $filter,
    ]));
    $pageUrl = fn(int $p) => URLROOT . '/admin/orders?' . $paginationBase . ($paginationBase ? '&' : '') . 'page=' . $p;
?>
<nav aria-label="Orders pagination" class="mt-4">
    <ul class="pagination justify-content-center flex-wrap">

        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $pageUrl($currentPage - 1) ?>">&laquo; Prev</a>
        </li>

        <?php
        $window = 2;
        $start  = max(1, $currentPage - $window);
        $end    = min($totalPages, $currentPage + $window);
        ?>

        <?php if ($start > 1): ?>
        <li class="page-item">
            <a class="page-link" href="<?= $pageUrl(1) ?>">1</a>
        </li>
        <?php if ($start > 2): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $start; $p <= $end; $p++): ?>
        <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
            <a class="page-link" href="<?= $pageUrl($p) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
        <li class="page-item">
            <a class="page-link" href="<?= $pageUrl($totalPages) ?>"><?= $totalPages ?></a>
        </li>
        <?php endif; ?>

        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $pageUrl($currentPage + 1) ?>">Next &raquo;</a>
        </li>

    </ul>
</nav>
<?php endif; ?>