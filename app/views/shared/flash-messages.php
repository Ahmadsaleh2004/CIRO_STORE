<?php
/**
 * app/views/shared/flash-messages.php
 * The transient success and error (flash) messages at the top of the page.
 *
 * This block used to be copied **verbatim** into five files: admin/backup ·
 * admin/manage-admins/index · admin/orders/index · admin/product/index ·
 * admin/users/index — thirteen lines identical byte for byte in each one.
 *
 * The variables — both optional, and nothing is printed if they are empty:
 *   $flashMsg  string  A success message (alert-success)
 *   $flashErr  string  An error message  (alert-danger)
 *
 * The escaping lives here rather than in the caller: the messages come from the session
 * and may carry text a user typed (a product name, an email, a reason for refusal).
 */
?>
<?php if (!empty($flashMsg)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flashMsg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if (!empty($flashErr)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flashErr) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
