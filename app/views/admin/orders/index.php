<?php
/**
 * app/views/admin/orders/index.php — a fragment only.
 * The variables from AdminOrdersController::index():
 *   $orders, $totalOrders, $totalPages, $currentPage, $filter, $search,
 *   $flashMsg, $flashErr, $csrf, $adminRole, $adminId
 * The JavaScript responsible: orders.js (goToOrderDetails / filterStatus / deleteOrder).
 * Note: the hard-delete button (🗑) appears only on orders in the completed or cancelled
 * state.
 */
?>

<?php // ── Page Header ────────────────────────────────────────── ?>
<?php /*
The header carries the title and the action buttons alone — the same pattern as
     users/index.php, manage-admins/index.php and product/index.php.
     Search and filtering moved to a row of their own beneath the messages (see below).
     Note: .admin-page-header is already defined as flex + space-between + wrap + gap in
     admin.css, so there is no need to repeat Bootstrap's classes here.
*/ ?>
<div class="admin-page-header">
    <h1>📦 Manage Orders <span class="badge bg-secondary fw-normal u-fs-90 align-middle"><?= (int)$totalOrders ?></span></h1>
    <div class="d-flex gap-2 flex-wrap">
        <?php
        $csvParams = http_build_query(array_filter(['q' => $search, 'status' => $filter]));
        $csvUrl    = URLROOT . '/admin/orders/export-csv' . ($csvParams ? '?' . $csvParams : '');
        ?>
        <a href="<?= htmlspecialchars($csvUrl) ?>" download class="btn btn-sm btn-export-csv">📄 Export CSV</a>
    </div>
</div>

<?php // ── Flash Messages ─────────────────────────────────────── ?>
<?php require APPROOT . '/views/shared/flash-messages.php'; ?>

<?php // ── Search + Status Filter ─────────────────────────────── ?>
<?php /*
A single flat row, with exactly the same structure as users/index.php. Putting the
     form inside an outer div was avoided because the nesting makes the form a narrow flex
     item, wrapping the row onto two lines (measured: 77px instead of 38px).
     The <select> carries no name attribute deliberately: navigation happens immediately
     through filterStatus() in orders.js, and the hidden status field is what preserves the
     filter during a text search — so a name here would duplicate the value and conflict.
*/ ?>
<form method="GET" action="<?= URLROOT ?>/admin/orders" class="d-flex gap-2 flex-wrap mb-3">
    <?php if ($filter): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>">
    <?php endif; ?>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
           class="form-control u-mw-280" placeholder="Order ID or customer...">
    <select class="form-select u-mw-180" data-action="filter-status">
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

<?php // ── Orders Table ───────────────────────────────────────── ?>
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
                <tr data-action="order-details" data-order-id="<?= (int)$o['order_id'] ?>" class="u-clickable">
                    <td class="fw-semibold">#<?= (int)$o['order_id'] ?></td>
                    <td>
                        <span class="fw-semibold"><?= htmlspecialchars($o['full_name']) ?></span>
                        <br><small class="u-muted"><?= htmlspecialchars($o['email']) ?></small>
                    </td>
                    <td>$<?= number_format($o['total_amount'], 2) ?></td>
                    <td><?= htmlspecialchars($o['payment_method']) ?></td>
                    <td><?php
                        $orderStatus = $o['status'];
                        $badgeExtraClass = '';
                        // This page alone writes "Not Taken" with a capital T
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
                    <td class="u-meta-80">
                        <?= htmlspecialchars(date('M j, Y H:i', strtotime($o['created_at']))) ?>
                    </td>
                    <td class="text-center" data-action="stop-propagation">
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

<?php // ── Pagination ─────────────────────────────────────────── ?>
<?php if ($totalPages > 1):
    // Build the filters' query string for the pagination — it preserves q and status while navigating
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