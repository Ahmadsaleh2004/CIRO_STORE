<?php
/**
 * app/views/admin/users/index.php — fragment فقط
 * المتغيرات من AdminUsersController::index():
 *   $users, $total, $page, $perPage, $search, $status,
 *   $flashMsg, $flashErr, $csrf, $adminRole, $adminId
 * JS المسؤول: users.js (.user-row/.delete-user-btn) + admins.js (openNotifyModal)
 */
$totalPages = max(1, (int)ceil($total / $perPage));
$startNum   = (($page - 1) * $perPage) + 1;
?>
<!-- ── Page Header ────────────────────────────────────────── -->
<div class="admin-page-header">
    <h1>👥 Manage Users <span class="badge bg-secondary fw-normal" style="font-size:.9rem;vertical-align:middle;"><?= (int)$totalUsers ?></span></h1>
    <div class="d-flex gap-2 flex-wrap">
        <?php
            $exportCsvUrl       = URLROOT . '/admin/users/export-csv';
            $exportCsvOnlyRoleA = false;   // خلافًا للأدمنية — أي أدمن عنده can_manage_users يصدّر
            include __DIR__ . '/../inc/export-csv-button.php';
        ?>
        <button class="btn btn-outline-info btn-sm"
                data-bs-toggle="modal"
                data-bs-target="#broadcastModal">📢 Broadcast to Users</button>
    </div>
</div>

<!-- ── Flash Messages ─────────────────────────────────────── -->
<?php require APPROOT . '/views/shared/flash-messages.php'; ?>

<!-- ── Search + Status Filter ─────────────────────────────── -->
<form method="GET" class="d-flex gap-2 flex-wrap mb-3" action="<?= URLROOT ?>/admin/users">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
           class="form-control" style="max-width:280px;" placeholder="Search name or email...">
    <select name="status" class="form-select" style="max-width:180px;">
        <option value="all"        <?= $status==='all'?'selected':'' ?>>All</option>
        <option value="active"     <?= $status==='active'?'selected':'' ?>>Active</option>
        <option value="not_active" <?= $status==='not_active'?'selected':'' ?>>Not Active</option>
        <option value="blocked"    <?= $status==='blocked'?'selected':'' ?>>Blocked</option>
    </select>
    <button class="btn btn-outline-primary btn-sm">Filter</button>
    <?php if ($search || $status !== 'all'): ?>
    <a href="<?= URLROOT ?>/admin/users" class="btn btn-sm btn-outline-secondary">✕ Clear</a>
    <?php endif; ?>
</form>

<!-- ── Users Table ────────────────────────────────────────── -->
<div class="card p-0">
    <div class="table-responsive">
        <table class="table admin-table mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Strikes</th>
                    <th>Last Activity</th>
                    <th>Joined</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <?php
                $emptyColspan = 8;
                $emptyMessage = 'No users found.';
                require APPROOT . '/views/shared/table-empty-row.php';
                ?>
            <?php else: ?>
                <?php foreach ($users as $i => $u):
                    $sc          = (int)($u['strikes_count'] ?? 0);
                    $lastActTime = !empty($u['last_activity']) ? strtotime($u['last_activity']) : 0;
                    $isBlocked   = $sc >= 3;
                    $isNotActive = !$isBlocked && $lastActTime < (time() - 90 * 24 * 3600);

                    [$statusText, $statusClass] = $isBlocked
                        ? ['Blocked',    'danger']
                        : ($isNotActive ? ['Not Active', 'secondary'] : ['Active', 'success']);
                ?>
                <tr class="user-row" data-uid="<?= (int)$u['id'] ?>">
                    <td><?= $startNum + $i ?></td>
                    <td>
                        <span class="fw-semibold"><?= htmlspecialchars($u['full_name']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <span class="badge bg-<?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $sc>=3 ? 'bg-danger' : ($sc>0 ? 'bg-warning text-dark' : 'bg-success') ?>">
                            <?= $sc ?>/3
                        </span>
                    </td>
                    <td style="color:var(--muted-text);font-size:.8rem;">
                        <?= !empty($u['last_activity']) ? htmlspecialchars(date('d M Y', strtotime($u['last_activity']))) : '—' ?>
                    </td>
                    <td style="color:var(--muted-text);font-size:.8rem;">
                        <?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?>
                    </td>
                    <!-- data-action="stop-propagation" إلزامي — يمنع تفعيل user-row -->
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center" data-action="stop-propagation">
                            <button type="button" class="btn btn-sm btn-outline-info"
                                    data-action="notify-modal" data-notify-type="user"
                                    data-notify-id="<?= (int)$u['id'] ?>"
                                    data-notify-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                    title="Send message">🔔</button>
                            <button type="button" class="btn btn-sm btn-outline-danger delete-user-btn"
                                    data-uid="<?= (int)$u['id'] ?>"
                                    data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                    title="Delete user">🗑️ Delete</button>
                        </div>
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
        'status' => $status,
    ]));
    $pageUrl = fn(int $p) => URLROOT . '/admin/users?' . $paginationBase . ($paginationBase ? '&' : '') . 'page=' . $p;
?>
<nav aria-label="Users pagination" class="mt-4">
    <ul class="pagination justify-content-center flex-wrap">

        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $pageUrl($page - 1) ?>">&laquo; Prev</a>
        </li>

        <?php
        $window = 2;
        $start  = max(1, $page - $window);
        $end    = min($totalPages, $page + $window);
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
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
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

        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $pageUrl($page + 1) ?>">Next &raquo;</a>
        </li>

    </ul>
</nav>
<?php endif; ?>

<!-- ── Modals ─────────────────────────────────────────────── -->
<?php include __DIR__ . '/../notify-modal.php'; ?>
<?php $broadcastTargetType = 'user'; ?>
<?php include __DIR__ . '/../broadcast-form.php'; ?>
