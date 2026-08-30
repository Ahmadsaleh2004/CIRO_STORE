<?php
/**
 * app/views/admin/login.php — the admin sign-in page.
 *
 * layout: 'bare' — neither the store navbar nor the admin one. This page used to write
 * a full <!DOCTYPE html> and <head> by hand; those now live in inc/head-bare.php.
 *
 * A page independent of the asset bundles: a single CSS file carrying its own colours
 * and variables — neither store.css nor admin.css here.
 */

$bareTitle = 'Admin Login — ' . SITENAME;
$bareCss   = ['css/admin/pages/login.css'];

// This page calls vendorJs('sweetalert2') at its end, and its styles are a separate
// external stylesheet — so the flag is an acknowledgement of that dependency, not decoration.
$bareSwal  = true;

// js/admin/admin-auth.js reads the application root from this tag specifically
$bareHead  = '<meta name="urlroot" content="' . URLROOT . '">';

require APPROOT . '/views/inc/head-bare.php';
?>

<div class="login-wrapper">
    <div class="login-card">

        <?php // Logo / Header ?>
        <div class="login-logo">
            <div class="lock-icon">🔐</div>
            <h1>Cairo Store</h1>
            <p>Admin Control Panel</p>
        </div>

        <?php // The error or success message ?>
        <div class="alert-msg" id="alertMsg" role="alert" aria-live="polite"></div>

        <?php // The sign-in form — email and password (plus a 2FA code field, shown only when needed) ?>
        <form id="adminLoginForm" novalidate autocomplete="off">

            <?php // CSRF ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">

            <?php // The email ?>
            <div class="form-group">
                <label class="form-label" for="adminEmail">Email Address</label>
                <input
                    type="email"
                    id="adminEmail"
                    name="email"
                    class="form-control"
                    placeholder="admin@example.com"
                    required
                    autocomplete="username"
                    maxlength="255"
                    aria-describedby="alertMsg"
                >
            </div>

            <?php // The password ?>
            <div class="form-group">
                <label class="form-label" for="adminPassword">Password</label>
                <input
                    type="password"
                    id="adminPassword"
                    name="password"
                    class="form-control"
                    placeholder="••••••••"
                    required
                    autocomplete="current-password"
                    maxlength="128"
                >
            </div>

            <?php // The 2FA (TOTP) code field — shown only when the server asks for requires_2fa ?>
            <div class="form-group d-none" id="twofaGroup">
                <label class="form-label" for="adminTOTP">Authenticator Code</label>
                <input
                    type="text"
                    id="adminTOTP"
                    name="code"
                    class="form-control"
                    placeholder="000000"
                    inputmode="numeric"
                    maxlength="6"
                    pattern="[0-9]*"
                    autocomplete="one-time-code"
                    aria-describedby="alertMsg"
                >
            </div>

            <?php // hCaptcha — shown only after the first failed attempt (JavaScript controls it) ?>
            <?php // data-sitekey is read by admin-auth.js through dataset ?>
            <div
                id="captcha-container"
                aria-hidden="true"
                data-sitekey="<?= htmlspecialchars($_ENV['HCAPTCHA_SITE_KEY'] ?? '') ?>"
            >
                <?php // The hCaptcha widget is injected here by admin-auth.js ?>
            </div>

            <?php // The sign-in button ?>
            <button type="submit" id="loginBtn" class="btn-login">
                Sign In
            </button>

            <?php // The lockout timer ?>
            <p class="lockout-timer" id="lockoutTimer">
                Access locked — try again in <span id="lockoutCountdown">30:00</span>
            </p>
        </form>

        <?php // The link back to the store — visually hidden for SEO safety, present for accessibility ?>
        <a href="<?= URLROOT ?>/" class="back-link" tabindex="-1" aria-hidden="true">← Back to Store</a>

    </div>
</div>

<?php // Bootstrap JS alone — none of the public store's JavaScript is loaded ?>
<?= vendorJs('bootstrap-js') ?>

<?php // SweetAlert2 — needed for the welcome alert after a successful sign-in ?>
<?= vendorJs('sweetalert2') ?>

<?php // The JavaScript file for admin auth — no other store JavaScript file is loaded ?>
<script src="<?= URLROOT ?>/js/admin/admin-auth.js" defer></script>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>
