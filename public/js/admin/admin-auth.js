/**
 * admin-auth.js — the admin sign-in form's logic
 *
 * Responsibilities:
 *  1. submitting the form through fetch (a JSON response)
 *  2. showing the hCaptcha widget only after the first failed attempt (lazy loading)
 *  3. displaying the error and success messages
 *  4. managing the lockout state (its timer)
 *
 * ⚠️ **It does not use fetchWithCsrfRetry — deliberately, for three verified reasons:**
 *
 *  1. the wrapper picks its token endpoint with `typeof window.URLROOT !== 'undefined'`,
 *     and **the sign-in page does not define window.URLROOT** (it reads the root from
 *     <meta name="urlroot"> into APP_ROOT below). So it would fall through to the other
 *     branch, `window.BASE_URL + '/auth/csrf'`, and BASE_URL is not defined here either —
 *     meaning the retry would hit "undefined/auth/csrf".
 *  2. the file **renews the token itself** already: refreshCsrfToken() is called after
 *     every failure, fetches /admin/csrf and updates the form's field — the same work the
 *     wrapper does, written for this page's context.
 *  3. it uses the response object (res.ok) before reading the JSON, and the wrapper
 *     returns the parsed data rather than a Response.
 *
 * (Similar comments in the other admin files were **wrong** — those pages do define
 * window.URLROOT — and were removed. This file is the one genuine exception.)
 *
 * It depends on none of: cart.js / wishlist.js / notifications.js / auth.js,
 * and it touches no logic belonging to the regular user.
 */

'use strict';

(function () {

    // ── Constants ──────────────────────────────────────────────────────────
    const FORM_SELECTOR         = '#adminLoginForm';
    const BTN_SELECTOR          = '#loginBtn';
    const ALERT_SELECTOR        = '#alertMsg';
    const CAPTCHA_CONTAINER_ID  = 'captcha-container';
    const LOCKOUT_TIMER_ID      = 'lockoutTimer';
    const LOCKOUT_COUNTDOWN_ID  = 'lockoutCountdown';
    const TWOFAGROUP_SELECTOR   = '#twofaGroup';

    // Determining the application root (used to build the AJAX paths)
    const APP_ROOT = document.querySelector('meta[name="urlroot"]')?.content
                        || window.location.origin;

    const LOGIN_ENDPOINT        = APP_ROOT + '/admin/login';
    const LOGIN_2FA_ENDPOINT    = APP_ROOT + '/admin/login/2fa';

    // Internal state
    let captchaLoaded     = false;
    let captchaWidgetId   = null;
    let lockoutInterval   = null;
    let requiresTwofa     = false;

    // ── DOM ready ──────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const form = document.querySelector(FORM_SELECTOR);
        if (!form) return;

        form.addEventListener('submit', handleSubmit);
    }

    // ── Handling the submission ────────────────────────────────────────────
    async function handleSubmit(e) {
        e.preventDefault();

        const form    = e.currentTarget;
        const btn     = document.querySelector(BTN_SELECTOR);
        const alertEl = document.querySelector(ALERT_SELECTOR);

        clearAlert(alertEl);
        setLoading(btn, true);

        try {
            const formData = new FormData(form);

            // Add the hCaptcha response if the widget is loaded
            if (captchaLoaded && captchaWidgetId !== null && typeof hcaptcha !== 'undefined') {
                const captchaVal = hcaptcha.getResponse(captchaWidgetId);
                formData.set('h-captcha-response', captchaVal);
            }

            // nosemgrep: cairo-bare-fetch-post
            const response = await fetch(requiresTwofa ? LOGIN_2FA_ENDPOINT : LOGIN_ENDPOINT, {
                method:      'POST',
                body:        formData,
                credentials: 'same-origin',
                headers:     { 'X-Requested-With': 'XMLHttpRequest' },
            });

            let data;
            try {
                data = await response.json();
            } catch {
                showAlert(alertEl, 'Unexpected server response. Please try again.', 'error');
                return;
            }

            if (data.success) {
                // Success — a welcome SweetAlert, then the redirect
                btn.disabled = true;
                Swal.fire({
                    icon: 'success',
                    title: data.message || 'Welcome!',
                    text: 'Redirecting to your dashboard...',
                    timer: 1500,
                    timerProgressBar: true,
                    showConfirmButton: false,
                    confirmButtonColor: '#16a34a'
                }).then(() => {
                    window.location.href = data.redirect || (window.location.origin + '/admin/home');
                });
            } else if (data.requires_2fa) {
                // The 2FA step — show the code field and point the next POST at /admin/login/2fa
                requiresTwofa = true;
                const group = document.querySelector(TWOFAGROUP_SELECTOR);
                if (group) {
                    // ⚠️ classList, not style.display: the field carries `d-none` in the
                    // markup, which is `display:none !important` — so no inline style beats it,
                    // whatever its value. It used to work for the wrong reason: a duplicated
                    // class attribute in admin/login.php dropped `d-none` entirely, so the
                    // field was visible from the moment the page opened rather than after the
                    // server asked for it.
                    group.classList.remove('d-none');
                    const codeInput = document.getElementById('adminTOTP');
                    codeInput.required = true;
                    codeInput.focus();
                }
                showAlert(alertEl, data.message || 'Enter your 2FA code.', 'success');
            } else {
                // Failure
                showAlert(alertEl, data.message || 'Login failed.', 'error');

                // Reset hCaptcha if it is loaded
                if (captchaLoaded && captchaWidgetId !== null && typeof hcaptcha !== 'undefined') {
                    hcaptcha.reset(captchaWidgetId);
                }

                // Should the CAPTCHA be shown?
                if (data.show_captcha) {
                    loadCaptchaIfNeeded();
                }

                // Reissue the CSRF token
                refreshCsrfToken(form);
            }

        } catch (err) {
            console.error('Admin login error:', err);
            showAlert(alertEl, 'Connection error. Please check your network and try again.', 'error');
        } finally {
            setLoading(btn, false);
        }
    }

    // ── Loading hCaptcha lazily (only when it is needed) ───────────────────
    function loadCaptchaIfNeeded() {
        const container = document.getElementById(CAPTCHA_CONTAINER_ID);
        if (!container) return;

        // Show the container first
        container.style.display = 'block';
        container.setAttribute('aria-hidden', 'false');

        if (captchaLoaded) return; // Do not load it twice

        // Get the site key from a meta tag or from a global variable
        const siteKey = getSiteKey();
        if (!siteKey) {
            console.warn('admin-auth.js: HCAPTCHA_SITE_KEY not found');
            return;
        }

        // Load the hCaptcha script dynamically
        const script = document.createElement('script');
        script.src   = 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=adminHcaptchaOnLoad';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);

        // The callback for when the API is ready
        window.adminHcaptchaOnLoad = function () {
            captchaLoaded    = true;
            captchaWidgetId  = hcaptcha.render(CAPTCHA_CONTAINER_ID, {
                sitekey: siteKey,
                theme:   'dark',
                size:    'normal',
            });
        };
    }

    /**
     * It tries to obtain the hCaptcha site key from:
     * 1. a data attribute on the captcha container
     * 2. window.HCAPTCHA_SITE_KEY (which the view can pass)
     */
    function getSiteKey() {
        const container = document.getElementById(CAPTCHA_CONTAINER_ID);
        const key = (container?.dataset?.sitekey || window.HCAPTCHA_SITE_KEY || '').trim();

        // An unreplaced placeholder (YOUR_HCAPTCHA_SITE_KEY_HERE) is treated as unset —
        // without this check it is passed to hcaptcha.render(), the widget fails silently,
        // and no h-captcha-response field is produced at all. This matches the corresponding
        // check in AdminAuthController::verifyCaptcha(), keeping the two sides consistent.
        if (!key || key.startsWith('YOUR_')) return null;

        return key;
    }

    // ── Refreshing the CSRF token after every failure ─────────────────────
    async function refreshCsrfToken(form) {
        try {
            const csrfInput = form.querySelector('input[name="csrf_token"]');
            if (!csrfInput) return;

            // An endpoint specific to the admin session — separate from the public /auth/csrf
            const res  = await fetch(APP_ROOT + '/admin/csrf', {
                credentials: 'same-origin',
                headers:     { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (res.ok) {
                const d = await res.json();
                if (d.token) csrfInput.value = d.token;
            }
        } catch {
            // Silent — a failure here does not stop anything
        }
    }

    // ── The lockout timer (for display alone — the real lockout is server-side) ──
    function startLockoutTimer(minutes) {
        const timerEl     = document.getElementById(LOCKOUT_TIMER_ID);
        const countdownEl = document.getElementById(LOCKOUT_COUNTDOWN_ID);
        const loginBtn    = document.querySelector(BTN_SELECTOR);

        if (!timerEl || !countdownEl) return;

        let totalSeconds = minutes * 60;

        timerEl.style.display  = 'block';
        if (loginBtn) loginBtn.disabled = true;

        if (lockoutInterval) clearInterval(lockoutInterval);

        lockoutInterval = setInterval(() => {
            totalSeconds--;
            if (totalSeconds <= 0) {
                clearInterval(lockoutInterval);
                timerEl.style.display = 'none';
                if (loginBtn) loginBtn.disabled = false;
                return;
            }
            const m = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
            const s = (totalSeconds % 60).toString().padStart(2, '0');
            countdownEl.textContent = `${m}:${s}`;
        }, 1000);
    }

    // ── Utilities ──────────────────────────────────────────────────────────

    function showAlert(el, message, type = 'error') {
        if (!el) return;
        el.textContent = message;
        el.className   = `alert-msg ${type} visible`;
    }

    function clearAlert(el) {
        if (!el) return;
        el.textContent = '';
        el.className   = 'alert-msg';
    }

    function setLoading(btn, loading) {
        if (!btn) return;
        if (loading) {
            btn.disabled   = true;
            btn.innerHTML  = '<span class="spinner"></span> Signing in…';
        } else {
            btn.disabled   = false;
            btn.textContent = 'Sign In';
        }
    }

    // Exported for external use, should it be needed later
    window.adminAuth = { startLockoutTimer };

})();
