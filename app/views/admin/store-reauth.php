<?php
/**
 * app/views/admin/store-reauth.php — re-authenticating by password before returning
 * from store mode to the admin panel.
 *
 * layout: 'bare' — this page used to write a full <!DOCTYPE html> and <head> by hand;
 * those now live in inc/head-bare.php. It shares login.php's standalone CSS file
 * (neither store.css nor admin.css).
 *
 * The variables: $return — the URL the admin returns to once the check succeeds.
 */

$bareTitle = 'Admin Re-authentication — ' . SITENAME;
$bareCss   = ['css/admin/pages/login.css'];

require APPROOT . '/views/inc/head-bare.php';
?>

<?php // URLROOT is defined here because this page is independent of the admin layout (no head.php) ?>
<?= pageData(['URLROOT' => URLROOT]) ?>

<div class="login-wrapper">
    <div class="login-card">

        <?php // Logo / Header ?>
        <div class="login-logo">
            <div class="lock-icon">👑</div>
            <h1>Cairo Store</h1>
            <p>Return to Admin Panel</p>
        </div>

        <?php // The error or success message ?>
        <div class="alert-msg" id="alertMsg" role="alert" aria-live="polite"></div>

        <p class="store-mode-hint">
            You are browsing the store as a guest.
            Enter your password to return to the admin panel.
        </p>

        <?php // The re-authentication form, before returning to the panel ?>
        <form id="storeReauthForm" novalidate autocomplete="off">

            <?php // CSRF ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">

            <?php // The return destination — guarded against open redirects server-side ?>
            <input type="hidden" name="return" value="<?= htmlspecialchars($return ?? URLROOT . '/admin/home') ?>">

            <?php // The password ?>
            <div class="form-group">
                <label class="form-label" for="reauthPassword">Password</label>
                <input
                    type="password"
                    id="reauthPassword"
                    name="password"
                    class="form-control"
                    placeholder="••••••••"
                    required
                    autocomplete="current-password"
                    autofocus
                    maxlength="128"
                    aria-describedby="alertMsg"
                >
            </div>

            <?php // The verify button ?>
            <button type="submit" id="reauthBtn" class="btn-login">
                Verify &amp; Return
            </button>
        </form>

        <?php // The link out of store mode — it clears the flag and stays in the store ?>
        <a href="<?= URLROOT ?>/" class="back-link" tabindex="-1">← Continue browsing the store</a>

    </div>
</div>

<?php // Bootstrap JS — genuinely loaded from the CDN ?>
<?= vendorJs('bootstrap-js') ?>

<?php // csrf.js and theme.js — loaded by absolute paths through URLROOT (no relative paths to break) ?>
<script src="<?= URLROOT ?>/js/core/theme.js" defer></script>
<script src="<?= URLROOT ?>/js/core/csrf.js" defer></script>
<script src="<?= URLROOT ?>/js/admin/admin-auth.js" defer></script>

<script src="<?= URLROOT ?>/js/admin/store-reauth.js" defer></script>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>