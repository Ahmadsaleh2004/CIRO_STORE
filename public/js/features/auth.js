/**
 * js/features/auth.js — handling the login, register and forgot-password forms over AJAX
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
        '+962': [9],      // Jordan
        '+20':  [10],     // Egypt
        '+966': [9],      // Saudi Arabia
        '+971': [9],      // United Arab Emirates
        '+1':   [10],     // United States
        '+44':  [10],     // United Kingdom
        '+90':  [10],     // Turkey
        '+49':  [10, 11]  // Germany
    };

    // ── Login Validation ───────────────────────────────────────
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        const loginEmail = document.getElementById('loginEmail');
        const loginPass  = document.getElementById('loginPass');
        const loginBtn   = document.getElementById('loginBtn');

        // ⚠️ Show and hide this element with classList, not with style.display.
        //
        // #loginError and #regError carry `d-none` in both modals' markup, and Bootstrap
        // defines it as `display:none !important` — which no inline style defeats. The file
        // used to set style.display='block' alone, so neither a login nor a registration
        // error message ever appeared **once**: a wrong password reset the button to its
        // normal state with no reason displayed at all.
        //
        // The fault did not surface in the project's other messages because they write the
        // whole className (`msgEl.className = 'alert …'`), which clears d-none incidentally —
        // see forgotMsg, resetMsg and addrMsg. These two alone did not.
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
                    errEl.classList.remove('d-none');
                }
                if (remaining <= 0) {
                    clearInterval(loginCountdownTimer);
                    if (errEl) errEl.classList.add('d-none');
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
            errEl.classList.add('d-none');

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
                    errEl.classList.remove('d-none');
                    loginBtn.disabled = false;
                    loginBtn.innerHTML = 'Sign In';
                    checkLoginFormValidity();
                }
            } catch {
                errEl.textContent  = 'Connection error. Please try again.';
                errEl.classList.remove('d-none');
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
            if (errEl) errEl.classList.add('d-none');

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
                        errEl.classList.remove('d-none');
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
                    errEl.classList.remove('d-none');
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
    // AuthController::logout now verifies CSRF (it did not, so any external site could
    // sign a visitor out). The token comes from a hidden field the authentication modals
    // print on every store page, and csrf.js refreshes it on every renewal.
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');

    try {
        // The safety net genuinely matters now: an expired token means a failed sign-out,
        // and this fetches a fresh token and retries exactly once.
        const data = await fetchWithCsrfRetry(window.BASE_URL + '/auth/logout', {
            method: 'POST',
            body: fd,
        });
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
