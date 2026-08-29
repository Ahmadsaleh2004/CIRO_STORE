<?php
/**
 * app/views/admin/my-info.php — fragment فقط (بدون DOCTYPE/html/head/body)
 * يُحمَّل من AdminController::adminView() بعد inc/head.php و inc/navbar.php
 * المتغيرات المتاحة: $adminName, $adminRole, $adminId, $csrf (من adminView) + $profile (من Controller)
 * لا يحتوي على أي منطق أو استيراد خاص باليوزر العادي.
 */
?>

<?php // Header ?>
<div class="d-flex align-items-center gap-3 mb-4">
    <div class="u-fs-xxl">👤</div>
    <div>
        <h1 class="fw-bold mb-0"><?= htmlspecialchars($profile['full_name'] ?? '') ?></h1>
        <p class="text-muted mb-0 u-fs-90"><?= htmlspecialchars($profile['email'] ?? '') ?></p>
    </div>
</div>

<?php // Tab Header — تبويب واحد فقط، نشط دائمًا ?>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <button class="nav-link info-tab-btn active" type="button" disabled
                class="u-cursor-default">
            📋 Personal Info
        </button>
    </li>
</ul>

<?php // كارد الفورم ?>
<div class="card p-4 u-mw-550">
    <h4 class="mb-4">✏️ Personal Information</h4>

    <form id="adminProfileForm" novalidate autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <?php // رسالة النجاح / الخطأ ?>
        <div id="profileMsg" class="alert py-2 small d-none"></div>

        <?php // Full Name ?>
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

        <?php // Email — readonly ?>
        <div class="float-group mb-3">
            <input type="email"
                   value="<?= htmlspecialchars($profile['email'] ?? '') ?>"
                   placeholder=" "
                   disabled
                   class="u-disabled">
            <label>Email Address <small class="text-muted">(cannot change)</small></label>
        </div>

        <?php // Phone Number — نفس الـpartial المستعمل في صفحة المستخدم ?>
        <div class="float-group mb-3">
            <?php
            $phoneValue   = $profile['phone_number'] ?? '';
            $phoneInputId = 'adminPhone';
            require APPROOT . '/views/shared/phone-input.php';
            ?>
            <label class="phone-group-label">Phone Number</label>
        </div>

        <?php // New Password ?>
        <div class="float-group mb-3">
            <input type="password"
                   id="adminNewPassword"
                   name="new_password"
                   placeholder=" "
                   autocomplete="new-password"
                   maxlength="128">
            <label for="adminNewPassword">New Password <small class="text-muted">(leave blank to keep)</small></label>
        </div>

        <?php // Current Password — إلزامي دائمًا ?>
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

<?php // ════════════════════ التحقق الثنائي (2FA / TOTP) ════════════════════ ?>
<div class="card p-4 mt-4 u-mw-550">
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

    <div id="twofaMsg" class="alert py-2 small d-none"></div>

    <?php if (!empty($profile['totp_enabled'])): ?>
        <?php // حالة: مفعّل → زر تعطيل مع طلب كلمة المرور الحالية ?>
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
        <?php // حالة: غير مفعّل → زر تفعيل + عرض QR/secret + حقل تأكيد الكود ?>
        <div id="twofaSetup">
            <button type="button" id="twofaEnableBtn" class="btn btn-success w-100">🔐 Enable 2FA</button>
        </div>

        <div id="twofaSetupStep" class="d-none">
            <div class="text-center my-3">
                <?php /*
بلا سمة src: src="" يجعل المتصفح يطلب عنوان الصفحة
                     نفسها في كل تحميل ثم يفشل. المصدر يضبطه
                     js/admin/my-info.js عند بدء الإعداد. العنصر داخل
                     حاوية مخفية حتى تلك اللحظة، وwidth/height يحجزان
                     مساحته فلا يقفز التخطيط.
*/ ?>
                <img id="twofaQr" alt="QR Code" width="220" height="220"
                     class="twofa-qr">
            </div>
            <div class="text-center small mb-3">
                <span class="text-muted">Manual entry key:</span>
                <code id="twofaSecret" class="d-block mt-1" class="u-secret-text"></code>
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

<?php // منطق الصفحة في js/admin/my-info.js — يُحمَّل عبر extraScripts ?>

