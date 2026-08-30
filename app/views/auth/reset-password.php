<?php
/**
 * app/views/auth/reset-password.php
 * The password reset page — it serves both users and admins, according to $type.
 *
 * layout: 'bare' — no store navbar and no store footer. It used to write a full
 * <!DOCTYPE html> and <head> by hand; those now live in inc/head-bare.php.
 *
 * The available variables: $valid (bool), $token, $email, $type
 */

// CSRF needs an active session — resetForm() does not start one, so it is started here
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$csrfToken = generateCsrfToken();
$isAdmin   = ($type ?? '') === 'admin';

$bareTitle     = 'Reset Password — ' . SITENAME;
$bareThemeBoot = true;
$bareCss       = ['css/store.css', 'css/store/pages/reset-password.css'];


require APPROOT . '/views/inc/head-bare.php';
?>

<div class="reset-card">
    <div class="reset-header">
        <span class="reset-icon"><?= $isAdmin ? '🔐' : '🔑' ?></span>
        <h1>Reset Password</h1>
        <?php // @escaping-safe: SITENAME is a project constant ?>
        <p><?= $isAdmin ? SITENAME . ' Admin Panel' : SITENAME ?></p>
    </div>

    <?php if (empty($valid)): ?>
        <div class="reset-body">
            <div class="alert alert-danger reset-msg" role="alert">
                This link has expired or is not valid.
            </div>
            <p class="small text-muted mb-0">Request a new link from the sign-in page and we will email it to you.</p>
        </div>
        <div class="reset-footer">
            <a href="<?= $isAdmin ? URLROOT . '/admin/login' : URLROOT ?>">← <?= $isAdmin ? 'Admin Login' : 'Back to Store' ?></a>
        </div>
    <?php else: ?>
        <div class="reset-body">
            <form id="resetForm" novalidate autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                <input type="hidden" name="type"  value="<?= htmlspecialchars($type) ?>">

                <div id="resetMsg" class="alert py-2 small mb-3 d-none"></div>

                <div class="float-group">
                    <input type="password" name="password" id="newPassword"
                           class="form-control" placeholder=" " required
                           minlength="8" autocomplete="new-password">
                    <label>New Password</label>
                </div>

                <div class="float-group">
                    <input type="password" name="confirm_password" id="confirmPassword"
                           class="form-control" placeholder=" " required
                           minlength="8" autocomplete="new-password">
                    <label>Confirm Password</label>
                </div>

                <button type="submit" id="resetBtn" class="btn btn-warning w-100 mb-3 py-2">
                    Update Password
                </button>
            </form>
        </div>
        <div class="reset-footer">
            <a href="<?= $isAdmin ? URLROOT . '/admin/login' : URLROOT ?>">← <?= $isAdmin ? 'Back to Admin Login' : 'Back to Store' ?></a>
        </div>
    <?php endif; ?>
</div>

<?= vendorJs("bootstrap-js") ?>
<script src="<?= URLROOT ?>/js/core/csrf.js" defer></script>
<?php // Data only — the logic lives in js/features/reset-password.js ?>
<?= pageData(['BASE_URL' => URLROOT, 'URLROOT' => URLROOT]) ?>
<script src="<?= URLROOT ?>/js/features/reset-password.js" defer></script>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>