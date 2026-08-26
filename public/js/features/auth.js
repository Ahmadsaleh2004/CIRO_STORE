/**
 * js/features/auth.js — معالجة نماذج Login / Register / Forgot عبر AJAX
 */

function switchAuthModal(triggerEl, targetModalId, afterShown) {
    const currentModalEl = triggerEl.closest('.modal');
    const targetModalEl  = document.getElementById(targetModalId);
    if (!currentModalEl || !targetModalEl) return;

    const openTarget = function() {
        currentModalEl.removeEventListener('hidden.bs.modal', openTarget);

        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.paddingRight = '';
        document.body.style.overflow = '';

        if (typeof afterShown === 'function') {
            targetModalEl.addEventListener('shown.bs.modal', function onShown() {
                targetModalEl.removeEventListener('shown.bs.modal', onShown);
                afterShown();
            });
        }

        setTimeout(function() {
            bootstrap.Modal.getOrCreateInstance(targetModalEl).show();
        }, 150);
    };

    currentModalEl.addEventListener('hidden.bs.modal', openTarget, { once: true });
    bootstrap.Modal.getOrCreateInstance(currentModalEl).hide();
}
window.switchAuthModal = switchAuthModal;

document.addEventListener('DOMContentLoaded', () => {

    function calculateAge(birthDateString) {
        if (!birthDateString) return 0;
        const today = new Date();
        const birthDate = new Date(birthDateString);
        let age = today.getFullYear() - birthDate.getFullYear();
        const m = today.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        return age;
    }

    const countryPhoneLengths = {
        '+962': [9],      // الأردن
        '+20':  [10],     // مصر
        '+966': [9],      // السعودية
        '+971': [9],      // الإمارات
        '+1':   [10],     // أمريكا
        '+44':  [10],     // بريطانيا
        '+90':  [10],     // تركيا
        '+49':  [10, 11]  // ألمانيا
    };

    // ── Login Validation ───────────────────────────────────────
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        const loginEmail = document.getElementById('loginEmail');
        const loginPass  = document.getElementById('loginPass');
        const loginBtn   = document.getElementById('loginBtn');
        const errEl      = document.getElementById('loginError');

        function checkLoginFormValidity() {
            const isEmailOk = loginEmail && loginEmail.value.trim() !== '';
            const isPassOk  = loginPass && loginPass.value !== '';
            if (typeof updateButtonState === 'function') {
                updateButtonState(loginBtn, isEmailOk && isPassOk);
            }
        }

        [loginEmail, loginPass].forEach(el => {
            if (el) {
                el.addEventListener('input', checkLoginFormValidity);
                el.addEventListener('change', checkLoginFormValidity);
            }
        });

        let loginCountdownTimer = null;

        function startLoginCountdown(seconds) {
            clearInterval(loginCountdownTimer);
            let remaining = seconds;
            if (loginBtn) loginBtn.disabled = true;

            const tick = () => {
                if (errEl) {
                    errEl.textContent = `Too many attempts. Try again in ${remaining} second${remaining === 1 ? '' : 's'}.`;
                    errEl.style.display = 'block';
                }
                if (remaining <= 0) {
                    clearInterval(loginCountdownTimer);
                    if (errEl) errEl.style.display = 'none';
                    if (loginBtn) loginBtn.innerHTML = 'Sign In';
                    checkLoginFormValidity();
                    return;
                }
                remaining--;
            };

            tick();
            loginCountdownTimer = setInterval(tick, 1000);
        }

        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!loginBtn || !errEl) return;
            loginBtn.disabled = true;
            loginBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Signing in...';
            errEl.style.display = 'none';

            try {
                const data = await fetchWithCsrfRetry(
                    window.BASE_URL + '/auth/login',
                    { method: 'POST', body: new FormData(loginForm) }
                );

                if (data.success) {
                    if (typeof showToast === 'function') showToast(data.message, 'success');
                    setTimeout(() => { window.location.href = data.redirect || window.BASE_URL; }, 700);
                } else if (data.retry_after) {
                    loginBtn.innerHTML = 'Sign In';
                    startLoginCountdown(data.retry_after);
                } else {
                    errEl.textContent  = data.message;
                    errEl.style.display = 'block';
                    loginBtn.disabled = false;
                    loginBtn.innerHTML = 'Sign In';
                    checkLoginFormValidity();
                }
            } catch {
                errEl.textContent  = 'Connection error. Please try again.';
                errEl.style.display = 'block';
                loginBtn.disabled = false;
                loginBtn.innerHTML = 'Sign In';
                checkLoginFormValidity();
            }
        });

        checkLoginFormValidity();
    }

    // ── Register Validation ────────────────────────────────────
    const signupForm = document.getElementById('signupForm');
    if (signupForm) {
        const regName          = document.getElementById('regName');
        const regEmail         = document.getElementById('regEmail');
        const regPass          = document.getElementById('regPass');
        const regConfirmPass   = document.getElementById('regConfirmPass');
        const phoneCountryCode = document.getElementById('phoneCountryCode');
        const regPhoneLocal    = document.getElementById('regPhoneLocal');
        const regGender        = document.getElementById('regGender');
        const regBirthDate     = document.getElementById('regBirthDate');
        const regCountry       = document.getElementById('regCountry');
        const regCity          = document.getElementById('regCity');
        const privacyCheck     = document.getElementById('privacyCheck');
        const regBtn           = document.getElementById('regBtn');

        function checkSignupFormValidity() {
            const isNameOk      = regName && regName.value.trim().length >= 2;
            const isEmailOk     = regEmail && /^[a-zA-Z0-9._%+-]+@gmail\.com$/.test(regEmail.value.trim());
            const isPassOk      = regPass && regPass.value.length >= 8;
            const isConfirmOk   = regConfirmPass && regConfirmPass.value === regPass.value;
            
            const code          = phoneCountryCode ? phoneCountryCode.value : '';
            const localPhone    = regPhoneLocal ? regPhoneLocal.value.trim() : '';
            const allowedLens   = countryPhoneLengths[code] || [7, 8, 9, 10, 11, 12];
            const isPhoneOk     = localPhone.length > 0 && allowedLens.includes(localPhone.length) && /^\d+$/.test(localPhone);
            
            const isGenderOk    = regGender && regGender.value !== '';
            const isBirthOk     = regBirthDate && regBirthDate.value !== '' && calculateAge(regBirthDate.value) >= 13;
            const isCountryOk   = regCountry && regCountry.value.trim() !== '';
            const isCityOk      = regCity && regCity.value.trim() !== '';
            const isPrivacyOk   = privacyCheck && privacyCheck.checked;

            const isValid = isNameOk && isEmailOk && isPassOk && isConfirmOk && isPhoneOk && isGenderOk && isBirthOk && isCountryOk && isCityOk && isPrivacyOk;
            if (typeof updateButtonState === 'function') {
                updateButtonState(regBtn, isValid);
            }
        }

        window.checkSignupFormValidity = checkSignupFormValidity;

        [regName, regEmail, regPass, regConfirmPass, phoneCountryCode, regPhoneLocal, regGender, regBirthDate, regCountry, regCity, privacyCheck].forEach(el => {
            if (el) {
                el.addEventListener('input', checkSignupFormValidity);
                el.addEventListener('change', checkSignupFormValidity);
            }
        });

        signupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn   = document.getElementById('regBtn');
            const errEl = document.getElementById('regError');
            if (errEl) errEl.style.display = 'none';

            const code  = phoneCountryCode?.value || '';
            const local = regPhoneLocal?.value    || '';
            const phoneInput = signupForm.querySelector('input[name="phone"]');
            if (phoneInput && local) phoneInput.value = code + local;

            if (btn) {
                btn.disabled  = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Creating...';
            }

            try {
                const data = await fetchWithCsrfRetry(
                    window.BASE_URL + '/auth/register',
                    { method: 'POST', body: new FormData(signupForm) }
                );

                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('registerModal'))?.hide();
                    if (typeof showToast === 'function') showToast(data.message, 'success');
                    signupForm.reset();
                    setTimeout(() => new bootstrap.Modal(document.getElementById('loginModal')).show(), 700);
                } else {
                    if (errEl) {
                        errEl.textContent  = data.message;
                        errEl.style.display = 'block';
                    }
                    if (btn) {
                        btn.disabled  = false;
                        btn.innerHTML = 'Create Account';
                    }
                    checkSignupFormValidity();
                }
            } catch {
                if (errEl) {
                    errEl.textContent  = 'Connection error.';
                    errEl.style.display = 'block';
                }
                if (btn) {
                    btn.disabled  = false;
                    btn.innerHTML = 'Create Account';
                }
                checkSignupFormValidity();
            }
        });

        checkSignupFormValidity();
    }

    // ── Forgot Validation ──────────────────────────────────────
    const forgotForm = document.getElementById('forgotForm');
    if (forgotForm) {
        const forgotEmail = document.getElementById('forgotEmail');
        const forgotBtn   = document.getElementById('forgotBtn');

        function checkForgotFormValidity() {
            const isEmailOk = forgotEmail && forgotEmail.value.trim() !== '';
            if (typeof updateButtonState === 'function') {
                updateButtonState(forgotBtn, isEmailOk);
            }
        }

        if (forgotEmail) {
            forgotEmail.addEventListener('input', checkForgotFormValidity);
            forgotEmail.addEventListener('change', checkForgotFormValidity);
        }

        forgotForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msgEl = document.getElementById('forgotMsg');
            if (forgotBtn) forgotBtn.disabled = true;

            try {
                const data = await fetchWithCsrfRetry(
                    window.BASE_URL + '/auth/forgot',
                    { method: 'POST', body: new FormData(forgotForm) }
                );
                if (msgEl) {
                    msgEl.textContent = data.message;
                    msgEl.className   = `alert py-2 small mb-3 alert-${data.success ? 'success' : 'danger'}`;
                    msgEl.style.display = 'block';
                }

                if (data.retry_after && typeof window.startRetryCountdown === 'function') {
                    window.startRetryCountdown(msgEl, forgotBtn, data.retry_after, 'Please wait ');
                }
            } catch {
                if (msgEl) {
                    msgEl.textContent   = 'Connection error.';
                    msgEl.className     = 'alert py-2 small mb-3 alert-danger';
                    msgEl.style.display = 'block';
                }
            } finally {
                if (forgotBtn) forgotBtn.disabled = false;
                checkForgotFormValidity();
            }
        });

        checkForgotFormValidity();
    }

});

// ── Logout ────────────────────────────────────────────────────
window.logoutUser = async function () {
    const fd = new FormData();
    fd.append('action', 'logout');
    try {
        // fetch عارٍ عن قصد — AuthController::logout لا تتحقق من CSRF
        // (تُدمّر الجلسة مباشرة). لا شيء لشبكة الأمان لتتعافى منه.
        // nosemgrep: cairo-bare-fetch-post
        const res  = await fetch(window.BASE_URL + '/auth/logout', { method: 'POST', body: fd });
        const data = await res.json();
        window.location.href = data.redirect || window.BASE_URL;
    } catch {
        window.location.href = window.BASE_URL;
    }
};

// ── Password Toggles ─────────────────────────────────────────
window.togglePassword = function (inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (!input || !icon) return;
    input.type     = input.type === 'password' ? 'text' : 'password';
    icon.innerText = input.type === 'password' ? '👁️' : '🙈';
};

window.toggleBothPasswords = function (iconId) {
    const p1   = document.getElementById('regPass');
    const p2   = document.getElementById('regConfirmPass');
    const icon = document.getElementById(iconId);
    if (!p1 || !p2 || !icon) return;
    const show = p1.type === 'password';
    p1.type = p2.type = show ? 'text' : 'password';
    icon.innerText    = show ? '🙈' : '👁️';
};

// ── validateSignUp (legacy — kept for safety) ─────────────────
window.validateSignUp = function (e) { if (e && e.preventDefault) e.preventDefault(); };
