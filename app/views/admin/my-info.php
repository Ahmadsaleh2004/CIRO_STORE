<?php
/**
 * app/views/admin/my-info.php — fragment فقط (بدون DOCTYPE/html/head/body)
 * يُحمَّل من AdminController::adminView() بعد inc/head.php و inc/navbar.php
 * المتغيرات المتاحة: $adminName, $adminRole, $adminId, $csrf (من adminView) + $profile (من Controller)
 * لا يحتوي على أي منطق أو استيراد خاص باليوزر العادي.
 */
?>

<!-- Header -->
<div class="d-flex align-items-center gap-3 mb-4">
    <div style="font-size:3rem;">👤</div>
    <div>
        <h1 class="fw-bold mb-0"><?= htmlspecialchars($profile['full_name'] ?? '') ?></h1>
        <p class="text-muted mb-0" style="font-size:.9rem;"><?= htmlspecialchars($profile['email'] ?? '') ?></p>
    </div>
</div>

<!-- Tab Header — تبويب واحد فقط، نشط دائمًا -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <button class="nav-link info-tab-btn active" type="button" disabled
                style="cursor:default;">
            📋 Personal Info
        </button>
    </li>
</ul>

<!-- كارد الفورم -->
<div class="card p-4" style="max-width:550px;">
    <h4 class="mb-4">✏️ Personal Information</h4>

    <form id="adminProfileForm" novalidate autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <!-- رسالة النجاح / الخطأ -->
        <div id="profileMsg" class="alert py-2 small" style="display:none;"></div>

        <!-- Full Name -->
        <div class="float-group mb-3">
            <input type="text"
                   id="adminFullName"
                   name="full_name"
                   value="<?= htmlspecialchars($profile['full_name'] ?? '') ?>"
                   placeholder=" "
                   required
                   autocomplete="name">
            <label for="adminFullName">Full Name</label>
        </div>

        <!-- Email — readonly -->
        <div class="float-group mb-3">
            <input type="email"
                   value="<?= htmlspecialchars($profile['email'] ?? '') ?>"
                   placeholder=" "
                   disabled
                   style="opacity:.6;cursor:not-allowed;">
            <label>Email Address <small class="text-muted">(cannot change)</small></label>
        </div>

        <!-- Phone Number -->
        <div class="float-group mb-3">
            <?php
                $savedPhone     = $profile['phone_number'] ?? '';
                $countryPrefixes = ['+962','+966','+971','+20','+965','+974','+973','+968','+1','+44','+90','+49'];
                $detectedCode   = '';
                $localPhonePart = $savedPhone;
                foreach ($countryPrefixes as $pfx) {
                    if (str_starts_with($savedPhone, $pfx)) {
                        $detectedCode   = $pfx;
                        $localPhonePart = substr($savedPhone, strlen($pfx));
                        break;
                    }
                }
            ?>
            <div class="input-group">
                <select name="phone_country_code" class="form-select phone-code-select">
                    <?php foreach ($countryPrefixes as $pfx): ?>
                    <option value="<?= $pfx ?>" <?= $detectedCode === $pfx ? 'selected' : '' ?>><?= $pfx ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="tel"
                       id="adminPhone"
                       name="phone_local"
                       placeholder=" "
                       value="<?= htmlspecialchars($localPhonePart) ?>"
                       class="form-control"
                       autocomplete="tel">
            </div>
            <label class="phone-group-label">Phone Number</label>
        </div>

        <!-- New Password -->
        <div class="float-group mb-3">
            <input type="password"
                   id="adminNewPassword"
                   name="new_password"
                   placeholder=" "
                   autocomplete="new-password"
                   maxlength="128">
            <label for="adminNewPassword">New Password <small class="text-muted">(leave blank to keep)</small></label>
        </div>

        <!-- Current Password — إلزامي دائمًا -->
        <div class="float-group mb-4">
            <input type="password"
                   id="adminCurrentPassword"
                   name="current_password"
                   placeholder=" "
                   required
                   autocomplete="current-password"
                   maxlength="128">
            <label for="adminCurrentPassword">Current Password <span class="text-danger">*</span> <small class="text-muted">(required to save)</small></label>
        </div>

        <button type="submit" class="btn btn-success w-100">💾 Save Changes</button>
    </form>
</div>

<!-- ════════════════════ التحقق الثنائي (2FA / TOTP) ════════════════════ -->
<div class="card p-4 mt-4" style="max-width:550px;">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h4 class="mb-0">🔑 Two-Factor Authentication</h4>
        <?php if (!empty($profile['totp_enabled'])): ?>
            <span class="badge text-bg-success">ON</span>
        <?php else: ?>
            <span class="badge text-bg-secondary">OFF</span>
        <?php endif; ?>
    </div>
    <p class="text-muted small mb-3">
        Add an extra layer of security using a TOTP app (Google Authenticator, Authy, …).
    </p>

    <div id="twofaMsg" class="alert py-2 small" style="display:none;"></div>

    <?php if (!empty($profile['totp_enabled'])): ?>
        <!-- حالة: مفعّل → زر تعطيل مع طلب كلمة المرور الحالية -->
        <form id="twofaDisableForm" novalidate autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="float-group mb-3">
                <input type="password"
                       id="twofaDisablePassword"
                       name="current_password"
                       placeholder=" "
                       required
                       autocomplete="current-password"
                       maxlength="128">
                <label for="twofaDisablePassword">Current Password <span class="text-danger">*</span></label>
            </div>
            <button type="submit" class="btn btn-warning w-100">🔓 Disable 2FA</button>
        </form>
    <?php else: ?>
        <!-- حالة: غير مفعّل → زر تفعيل + عرض QR/secret + حقل تأكيد الكود -->
        <div id="twofaSetup">
            <button type="button" id="twofaEnableBtn" class="btn btn-success w-100">🔐 Enable 2FA</button>
        </div>

        <div id="twofaSetupStep" style="display:none;">
            <div class="text-center my-3">
                <img id="twofaQr" src="" alt="QR Code" width="220" height="220"
                     class="twofa-qr">
            </div>
            <div class="text-center small mb-3">
                <span class="text-muted">Manual entry key:</span>
                <code id="twofaSecret" class="d-block mt-1" style="font-size:1rem;word-break:break-all;"></code>
            </div>
            <div class="float-group mb-3">
                <input type="text"
                       id="twofaCode"
                       name="code"
                       placeholder=" "
                       inputmode="numeric"
                       maxlength="6"
                       pattern="[0-9]*"
                       required
                       autocomplete="one-time-code">
                <label for="twofaCode">Enter the 6-digit code from your app</label>
            </div>
            <button type="button" id="twofaConfirmBtn" class="btn btn-success w-100">✅ Confirm & Enable</button>
            <button type="button" id="twofaCancelBtn" class="btn btn-link w-100 text-muted mt-2 small">Cancel</button>
        </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('adminProfileForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const form  = e.target;
    const msgEl = document.getElementById('profileMsg');
    const data  = Object.fromEntries(new FormData(form));
    msgEl.style.display = 'none';

    try {
        const res = await fetch(window.URLROOT + '/admin/my-info', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(data),
        }).then(r => r.json());

        msgEl.className   = res.success
            ? 'alert alert-success py-2 small'
            : 'alert alert-danger py-2 small';
        msgEl.textContent = res.message;
        msgEl.style.display = 'block';

        if (res.success) {
            form.querySelector('[name="current_password"]').value = '';
        }
    } catch (err) {
        msgEl.className   = 'alert alert-danger py-2 small';
        msgEl.textContent = 'Network error.';
        msgEl.style.display = 'block';
    }
});
</script>

<script>
// ── 2FA (TOTP) — تفعيل / تعطيل عبر AJAX ───────────────────────────────
(function () {
    const msgEl = document.getElementById('twofaMsg');

    function showMsg(success, text) {
        msgEl.className   = success
            ? 'alert alert-success py-2 small'
            : 'alert alert-danger py-2 small';
        msgEl.textContent = text;
        msgEl.style.display = 'block';
    }

    async function postJson(url, data) {
        const res = await fetch(window.URLROOT + url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(data),
        });
        return res.json();
    }

    const csrf = () => document.querySelector('#adminProfileForm [name="csrf_token"]')?.value || '';

    // تفعيل — الخطوة 1: توليد secret وإظهار QR
    const enableBtn = document.getElementById('twofaEnableBtn');
    if (enableBtn) {
        enableBtn.addEventListener('click', async () => {
            enableBtn.disabled = true;
            msgEl.style.display = 'none';
            try {
                const d = await postJson('/admin/my-info/2fa/generate', { csrf_token: csrf() });
                if (!d.success) {
                    showMsg(false, d.message || 'Could not start 2FA setup.');
                    enableBtn.disabled = false;
                    return;
                }
                document.getElementById('twofaSetup').style.display = 'none';
                document.getElementById('twofaSetupStep').style.display = 'block';
                document.getElementById('twofaQr').src  = d.qrcode_url;
                document.getElementById('twofaSecret').textContent = d.secret;
                document.getElementById('twofaCode').focus();
            } catch {
                showMsg(false, 'Network error.');
                enableBtn.disabled = false;
            }
        });

        // إلغاء الإعداد
        document.getElementById('twofaCancelBtn').addEventListener('click', () => {
            document.getElementById('twofaSetupStep').style.display = 'none';
            document.getElementById('twofaSetup').style.display = 'block';
            enableBtn.disabled = false;
        });

        // تفعيل — الخطوة 2: تأكيد الكود الأول
        document.getElementById('twofaConfirmBtn').addEventListener('click', async () => {
            const code = document.getElementById('twofaCode').value.trim();
            if (!/^\d{6}$/.test(code)) {
                showMsg(false, 'Please enter the 6-digit code from your authenticator app.');
                return;
            }
            const btn = document.getElementById('twofaConfirmBtn');
            btn.disabled = true;
            msgEl.style.display = 'none';
            try {
                const d = await postJson('/admin/my-info/2fa/confirm', {
                    csrf_token: csrf(),
                    code:       code,
                });
                if (d.success) {
                    showMsg(true, d.message);
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    showMsg(false, d.message || 'Invalid code.');
                    btn.disabled = false;
                    document.getElementById('twofaCode').focus();
                }
            } catch {
                showMsg(false, 'Network error.');
                btn.disabled = false;
            }
        });
    }

    // تعطيل — يتطلب كلمة المرور الحالية
    const disableForm = document.getElementById('twofaDisableForm');
    if (disableForm) {
        disableForm.addEventListener('submit', async e => {
            e.preventDefault();
            const passEl = document.getElementById('twofaDisablePassword');
            if (!passEl.value) {
                showMsg(false, 'Please enter your current password.');
                return;
            }
            const data = Object.fromEntries(new FormData(disableForm));
            msgEl.style.display = 'none';
            try {
                const d = await postJson('/admin/my-info/2fa/disable', data);
                if (d.success) {
                    showMsg(true, d.message);
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    showMsg(false, d.message || 'Could not disable 2FA.');
                    passEl.value = '';
                }
            } catch {
                showMsg(false, 'Network error.');
            }
        });
    }
})();
</script>
