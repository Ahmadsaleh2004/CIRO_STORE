<?php
/**
 * app/views/admin/login.php — صفحة تسجيل دخول الأدمن.
 *
 * layout: 'bare' — لا navbar المتجر ولا navbar الأدمن. كانت هذه الصفحة
 * تكتب <!DOCTYPE html> و<head> كاملين بيدها؛ صارا في inc/head-bare.php.
 *
 * صفحة مستقلة عن أنظمة الأصول: ملف CSS واحد يحمل ألوانه ومتغيراته
 * بنفسه — لا store.css ولا admin.css هنا.
 */

$bareTitle = 'Admin Login — ' . SITENAME;
$bareCss   = ['css/admin/pages/login.css'];

// js/admin/admin-auth.js يقرأ جذر التطبيق من هذا الوسم تحديداً
$bareHead  = '<meta name="urlroot" content="' . URLROOT . '">';

require APPROOT . '/views/inc/head-bare.php';
?>

<div class="login-wrapper">
    <div class="login-card">

        <!-- Logo / Header -->
        <div class="login-logo">
            <div class="lock-icon">🔐</div>
            <h1>Cairo Store</h1>
            <p>Admin Control Panel</p>
        </div>

        <!-- رسالة الخطأ / النجاح -->
        <div class="alert-msg" id="alertMsg" role="alert" aria-live="polite"></div>

        <!-- فورم تسجيل الدخول — إيميل + باسورد (+ حقل كود 2FA يظهر فقط عند الحاجة) -->
        <form id="adminLoginForm" novalidate autocomplete="off">

            <!-- CSRF -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">

            <!-- الإيميل -->
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

            <!-- كلمة المرور -->
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

            <!-- حقل كود 2FA (TOTP) — يظهر فقط عندما يطلب السيرفر requires_2fa -->
            <div class="form-group" id="twofaGroup" style="display:none;">
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

            <!-- hCaptcha — تظهر فقط بعد أول محاولة فاشلة (يتحكم فيها JS) -->
            <!-- data-sitekey يُقرأ من admin-auth.js عبر dataset -->
            <div
                id="captcha-container"
                aria-hidden="true"
                data-sitekey="<?= htmlspecialchars($_ENV['HCAPTCHA_SITE_KEY'] ?? '') ?>"
            >
                <!-- Widget hCaptcha سيُحقن هنا بواسطة admin-auth.js -->
            </div>

            <!-- زر الدخول -->
            <button type="submit" id="loginBtn" class="btn-login">
                Sign In
            </button>

            <!-- مؤقت الحظر -->
            <p class="lockout-timer" id="lockoutTimer">
                Access locked — try again in <span id="lockoutCountdown">30:00</span>
            </p>
        </form>

        <!-- رابط العودة للمتجر — مُخفى بصرياً لأمان SEO، موجود للـ accessibility -->
        <a href="<?= URLROOT ?>/" class="back-link" tabindex="-1" aria-hidden="true">← Back to Store</a>

    </div>
</div>

<!-- Bootstrap JS فقط — لا يُحمَّل أي JS خاص بالمتجر العام -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    defer
></script>

<!-- SweetAlert2 — مطلوبة لتنبيه الترحيب بعد نجاح تسجيل الدخول -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>

<!-- ملف JS المخصص لـ Admin Auth — لا يُحمَّل أي ملف JS آخر من المتجر -->
<script src="<?= URLROOT ?>/js/admin/admin-auth.js" defer></script>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>
