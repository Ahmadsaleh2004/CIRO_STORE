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
<script>window.URLROOT = "<?= URLROOT ?>";</script>

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
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    defer
></script>

<!-- csrf.js + theme.js — تُحمَّل بمسارات مطلقة عبر URLROOT (لا مسارات نسبية تتكسر) -->
<script src="<?= URLROOT ?>/js/core/theme.js" defer></script>
<script src="<?= URLROOT ?>/js/core/csrf.js" defer></script>
<script src="<?= URLROOT ?>/js/admin/admin-auth.js" defer></script>

<script>
// ── منطق فورم إعادة التحقق قبل الرجوع للوحة ─────────────────
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('storeReauthForm');
        const btn  = document.getElementById('reauthBtn');
        const alertEl = document.getElementById('alertMsg');
        if (!form || !btn || !alertEl) return;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            alertEl.textContent = '';
            alertEl.className = 'alert-msg';
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Verifying…';

            try {
                const fd = new FormData(form);
                const res = await fetch(window.URLROOT + '/admin/store-mode/reauth', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                let data;
                try {
                    data = await res.json();
                } catch {
                    alertEl.className = 'alert-msg error visible';
                    alertEl.textContent = 'Unexpected server response. Please try again.';
                    return;
                }

                if (data.success) {
                    alertEl.className = 'alert-msg success visible';
                    alertEl.textContent = data.message || 'Verified. Redirecting…';
                    setTimeout(function () {
                        window.location.href = data.redirect || (window.URLROOT + '/admin/home');
                    }, 500);
                } else {
                    alertEl.className = 'alert-msg error visible';
                    alertEl.textContent = data.message || 'Verification failed.';
                    // تحديث توكن CSRF إن أعادته اللوحة بعد الفشل
                    if (data.csrf_token && typeof updateCsrfToken === 'function') {
                        updateCsrfToken(data.csrf_token);
                    }
                }
            } catch (err) {
                console.error('Store reauth error:', err);
                alertEl.className = 'alert-msg error visible';
                alertEl.textContent = 'Connection error. Please try again.';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Verify &amp; Return';
            }
        });
    });
})();
</script>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>