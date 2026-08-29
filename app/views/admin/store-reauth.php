<?php
/**
 * app/views/admin/store-reauth.php — إعادة التحقق بكلمة السر قبل الرجوع
 * من وضع المتجر إلى لوحة التحكم.
 *
 * layout: 'bare' — كانت هذه الصفحة تكتب <!DOCTYPE html> و<head> كاملين
 * بيدها؛ صارا في inc/head-bare.php. تشارك login.php نفس ملف الـCSS
 * المستقل (لا store.css ولا admin.css).
 *
 * المتغيرات: $return — الرابط الذي يعود إليه الأدمن بعد نجاح التحقق.
 */

$bareTitle = 'Admin Re-authentication — ' . SITENAME;
$bareCss   = ['css/admin/pages/login.css'];

require APPROOT . '/views/inc/head-bare.php';
?>

<!-- URLROOT يُعرَّف هنا لأن هذه الصفحة مستقلة عن layout الأدمن (لا head.php) -->
<?= pageData(['URLROOT' => URLROOT]) ?>

<div class="login-wrapper">
    <div class="login-card">

        <!-- Logo / Header -->
        <div class="login-logo">
            <div class="lock-icon">👑</div>
            <h1>Cairo Store</h1>
            <p>Return to Admin Panel</p>
        </div>

        <!-- رسالة الخطأ / النجاح -->
        <div class="alert-msg" id="alertMsg" role="alert" aria-live="polite"></div>

        <p class="store-mode-hint">
            You are browsing the store as a guest.
            Enter your password to return to the admin panel.
        </p>

        <!-- فورم إعادة التحقق بكلمة السر قبل الرجوع للوحة -->
        <form id="storeReauthForm" novalidate autocomplete="off">

            <!-- CSRF -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">

            <!-- وجهة العودة — مُحمّاة ضد Open Redirect server-side -->
            <input type="hidden" name="return" value="<?= htmlspecialchars($return ?? URLROOT . '/admin/home') ?>">

            <!-- كلمة المرور -->
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

            <!-- زر التحقق -->
            <button type="submit" id="reauthBtn" class="btn-login">
                Verify &amp; Return
            </button>
        </form>

        <!-- رابط التراجع عن وضع المتجر — يحذف العلم ويبقى بالمتجر -->
        <a href="<?= URLROOT ?>/" class="back-link" tabindex="-1">← Continue browsing the store</a>

    </div>
</div>

<!-- Bootstrap JS — يُحمَّل فعلياً عبر CDN -->
<?= vendorJs('bootstrap-js') ?>

<!-- csrf.js + theme.js — تُحمَّل بمسارات مطلقة عبر URLROOT (لا مسارات نسبية تتكسر) -->
<script src="<?= URLROOT ?>/js/core/theme.js" defer></script>
<script src="<?= URLROOT ?>/js/core/csrf.js" defer></script>
<script src="<?= URLROOT ?>/js/admin/admin-auth.js" defer></script>

<script src="<?= URLROOT ?>/js/admin/store-reauth.js" defer></script>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>