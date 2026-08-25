<?php
/**
 * app/views/auth/reset-password.php
 * صفحة إعادة تعيين كلمة المرور — تخدم المستخدم والأدمن معاً حسب $type.
 *
 * layout: 'bare' — لا navbar ولا footer المتجر. كانت تكتب <!DOCTYPE html>
 * و<head> كاملين بيدها؛ صارا في inc/head-bare.php.
 *
 * المتغيرات المتاحة: $valid (bool), $token, $email, $type
 */

// CSRF يحتاج جلسة فعّالة — resetForm() لا يبدأها، فنبدأها هنا
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$csrfToken = generateCsrfToken();
$isAdmin   = ($type ?? '') === 'admin';

$bareTitle     = 'Reset Password — ' . SITENAME;
$bareThemeBoot = true;
$bareCss       = ['css/store.css'];

// ⚠️ هذه الكتلة مرشّحة للمرحلة 5 (إخراج كل <style> المضمّن من الـviews).
// نُقلت كما هي حرفياً كي تبقى المرحلة 4 بصفر تغيير بصري.
$bareHead = <<<'HTML'
<style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg, #f5f6fa);
            padding: 1rem;
        }
        .reset-card { width: 100%; max-width: 440px; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,.12); }
        .reset-header {
            background: linear-gradient(135deg, #7c2d12, #9a3412);
            padding: 28px 28px 20px;
            color: #fff; text-align: center;
        }
        .reset-header .reset-icon { font-size: 2.5rem; display: block; margin-bottom: 6px; }
        .reset-header h1 { font-size: 1.3rem; font-weight: 700; color: #fff; margin-bottom: 4px; }
        .reset-header p { margin: 0; font-size: .8rem; color: rgba(255,255,255,.7); }
        .reset-body { padding: 28px; background: #fff; }
        body.dark-mode .reset-body { background: #1a1d24; }
        .reset-msg { margin-bottom: 1rem; }
        .reset-footer { text-align: center; padding: 16px 28px 24px; background: #fff; border-top: 1px solid rgba(0,0,0,.05); }
        body.dark-mode .reset-footer { background: #1a1d24; border-top-color: rgba(255,255,255,.08); }
        .reset-footer a { font-weight: 600; text-decoration: none; }
    </style>
HTML;

require APPROOT . '/views/inc/head-bare.php';
?>

<div class="reset-card">
    <div class="reset-header">
        <span class="reset-icon"><?= $isAdmin ? '🔐' : '🔑' ?></span>
        <h1>Reset Password</h1>
        <p><?= $isAdmin ? SITENAME . ' Admin Panel' : SITENAME ?></p>
    </div>

    <?php if (empty($valid)): ?>
        <div class="reset-body">
            <div class="alert alert-danger reset-msg" role="alert">
                الرابط منتهي أو غير صحيح.
            </div>
            <p class="small text-muted mb-0">اطلب رابطًا جديدًا من صفحة تسجيل الدخول وسيصلك على بريدك الإلكتروني.</p>
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

                <div id="resetMsg" class="alert py-2 small mb-3" style="display:none;"></div>

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="<?= URLROOT ?>/js/core/csrf.js" defer></script>
<script>
window.BASE_URL = <?= json_encode(URLROOT) ?>;
window.URLROOT  = <?= json_encode(URLROOT) ?>;

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('resetForm');
    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        var btn      = document.getElementById('resetBtn');
        var msgEl    = document.getElementById('resetMsg');
        var pass     = document.getElementById('newPassword').value;
        var confirm  = document.getElementById('confirmPassword').value;

        if (pass.length < 8) {
            showResetMsg(msgEl, 'Password must be at least 8 characters.', 'danger');
            return;
        }
        if (pass !== confirm) {
            showResetMsg(msgEl, 'Passwords do not match.', 'danger');
            return;
        }

        if (btn) btn.disabled = true;

        try {
            var doFetch = (typeof window.fetchWithCsrfRetry === 'function')
                ? window.fetchWithCsrfRetry
                : fetch;
            var data = await doFetch(window.BASE_URL + '/auth/reset', {
                method: 'POST',
                body: new FormData(form)
            });

            showResetMsg(msgEl, data.message, data.success ? 'success' : 'danger');

            if (data.success) {
                setTimeout(function () {
                    window.location.href = window.BASE_URL;
                }, 1200);
            }
        } catch (err) {
            showResetMsg(msgEl, 'Something went wrong. Please try again.', 'danger');
        } finally {
            if (btn) btn.disabled = false;
        }
    });
});

function showResetMsg(el, text, type) {
    if (!el) return;
    el.textContent  = text;
    el.className    = 'alert py-2 small mb-3 alert-' + type;
    el.style.display = 'block';
}
</script>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>