<?php
/**
 * app/views/admin/support.php — a fragment only (no DOCTYPE/html/head/body).
 * Loaded by AdminController::adminView() after inc/head.php and inc/navbar.php
 * The variables arriving ready from AdminSupportController::index():
 *   $messages, $search, $totalMessages, $currentPage, $totalPages, $csrf
 */
?>

<div class="admin-page-header">
    <h1>💬 Support Messages</h1>
    <span class="u-muted u-fs-85">
        Total: <?= (int) $totalMessages ?> message<?= $totalMessages !== 1 ? 's' : '' ?>
    </span>
</div>

<?php // ── Search Bar ─────────────────────────────────────────── ?>
<div class="float-group mb-4">
    <form method="GET" action="<?= URLROOT ?>/admin/support" class="d-flex gap-2 flex-wrap align-items-center">
        <input
            type="text"
            name="q"
            class="form-control u-mw-320"
            placeholder="Search by name, email or message..."
            value="<?= htmlspecialchars($search) ?>"
        >
        <button type="submit" class="btn btn-success">🔍 Search</button>
        <?php if ($search !== ''): ?>
        <a href="<?= URLROOT ?>/admin/support" class="btn btn-outline-secondary">✕ Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php // ── Messages List ──────────────────────────────────────── ?>
<?php if (empty($messages)): ?>
<div class="text-center py-5 u-muted">
    <p class="u-fs-110">No messages found<?= $search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</p>
</div>
<?php else: ?>

<div class="row g-3" id="supportMessagesList">
    <?php foreach ($messages as $m): ?>
    <div class="col-12 col-md-6 col-lg-4" id="msg-card-<?= (int) $m['id'] ?>">
        <div
            class="support-msg-card card p-3 h-100 u-clickable"
            data-msg-id="<?= (int) $m['id'] ?>"
            data-user-id="<?= (int) ($m['user_id'] ?? 0) ?>"
            data-user-name="<?= htmlspecialchars($m['user_name'] ?? $m['full_name']) ?>"
        >
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <strong><?= htmlspecialchars($m['full_name']) ?></strong>
                    <?php if ($m['user_id']): ?>
                    <span class="badge bg-primary ms-1 u-fs-65">Registered</span>
                    <?php else: ?>
                    <span class="badge bg-secondary ms-1 u-fs-65">Guest</span>
                    <?php endif; ?>
                </div>
                <small class="u-meta">
                    <?= htmlspecialchars(date('M j, Y', strtotime($m['sent_at']))) ?>
                </small>
            </div>

            <p class="small mb-2 u-muted">
                ✉️ <?= htmlspecialchars($m['email']) ?>
            </p>

            <p class="mb-3 u-message-body">
                <?= nl2br(htmlspecialchars($m['message'])) ?>
            </p>

            <div class="d-flex gap-2 mt-auto flex-wrap">
                <?php if ($m['user_id']): ?>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary btn-reply-support"
                    data-msg-id="<?= (int) $m['id'] ?>"
                    data-user-id="<?= (int) $m['user_id'] ?>"
                    data-user-name="<?= htmlspecialchars($m['user_name'] ?? $m['full_name']) ?>"
                >💬 Reply</button>
                <?php endif; ?>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-danger btn-delete-support"
                    data-msg-id="<?= (int) $m['id'] ?>"
                >🗑️ Delete</button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php /*
A CSRF field that support.js reads on every submission.
    The name attribute is required rather than redundant: updateCsrfToken() in
    js/core/csrf.js targets input[name="csrf_token"], so without the name the field kept
    the stale token after any recovery — and the next manual submission then needed
    another recovery round for no reason.
    The field sits outside any <form> (the search form at the top of the page closes
    before it), and support.js builds its FormData by hand, so the name enters no
    submission unintentionally.
*/ ?>
<input type="hidden" name="csrf_token" id="csrfTokenSupport" value="<?= htmlspecialchars($csrf) ?>">

<?php // ── Pagination ─────────────────────────────────────────── ?>
<?php if ($totalPages > 1): ?>
<nav class="mt-4" aria-label="Support messages pagination">
    <ul class="pagination justify-content-center flex-wrap">

        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
            <a class="page-link"
               href="?page=<?= $currentPage - 1 ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
               &laquo; Prev
            </a>
        </li>

        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
            <a class="page-link"
               href="?page=<?= $p ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
               <?= $p ?>
            </a>
        </li>
        <?php endfor; ?>

        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link"
               href="?page=<?= $currentPage + 1 ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
               Next &raquo;
            </a>
        </li>

    </ul>
</nav>
<?php endif; ?>
