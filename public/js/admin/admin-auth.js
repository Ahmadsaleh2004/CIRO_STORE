/**
 * admin-auth.js — منطق فورم تسجيل دخول الأدمن
 *
 * المسؤوليات:
 *  1. إرسال الفورم عبر fetch (JSON response)
 *  2. إظهار hCaptcha widget بعد أول محاولة فاشلة فقط (تحميل كسول)
 *  3. عرض رسائل الخطأ / النجاح
 *  4. إدارة حالة الحظر (lockout timer)
 *
 * ⚠️ **لا يستعمل fetchWithCsrfRetry — عن قصد، لثلاثة أسباب مفحوصة:**
 *
 *  1. الغلاف يختار نقطة التوكن بـ`typeof window.URLROOT !== 'undefined'`،
 *     و**صفحة تسجيل الدخول لا تعرّف window.URLROOT** (تقرأ الجذر من
 *     <meta name="urlroot"> إلى APP_ROOT أدناه). فسيسقط إلى الفرع الآخر
 *     `window.BASE_URL + '/auth/csrf'` وBASE_URL غير معرّف هنا أيضاً —
 *     أي أن إعادة المحاولة ستضرب "undefined/auth/csrf".
 *  2. الملف **يجدّد التوكن بنفسه** أصلاً: refreshCsrfToken() تُستدعى بعد
 *     كل فشل وتجلب /admin/csrf وتحدّث حقل الفورم — نفس عمل الغلاف،
 *     مكتوباً لسياق هذه الصفحة.
 *  3. يستعمل كائن الاستجابة (res.ok) قبل قراءة JSON، والغلاف يُرجع
 *     البيانات المُحلَّلة لا Response.
 *
 * (التعليقات المشابهة في ملفات الأدمن الأخرى كانت **خاطئة** — تلك
 * الصفحات تعرّف window.URLROOT فعلاً — وأُزيلت. هذا الملف الاستثناء
 * الحقيقي الوحيد.)
 *
 * لا يعتمد على: cart.js / wishlist.js / notifications.js / auth.js
 * ولا يلمس أي منطق خاص بالمستخدم العادي.
 */

'use strict';

(function () {

    // ── ثوابت ──────────────────────────────────────────────────────────────
    const FORM_SELECTOR         = '#adminLoginForm';
    const BTN_SELECTOR          = '#loginBtn';
    const ALERT_SELECTOR        = '#alertMsg';
    const CAPTCHA_CONTAINER_ID  = 'captcha-container';
    const LOCKOUT_TIMER_ID      = 'lockoutTimer';
    const LOCKOUT_COUNTDOWN_ID  = 'lockoutCountdown';
    const TWOFAGROUP_SELECTOR   = '#twofaGroup';

    // تحديد جذر التطبيق (يُستخدم لبناء مسارات الـ AJAX)
    const APP_ROOT = document.querySelector('meta[name="urlroot"]')?.content
                        || window.location.origin;

    const LOGIN_ENDPOINT        = APP_ROOT + '/admin/login';
    const LOGIN_2FA_ENDPOINT    = APP_ROOT + '/admin/login/2fa';

    // حالة داخلية
    let captchaLoaded     = false;
    let captchaWidgetId   = null;
    let lockoutInterval   = null;
    let requiresTwofa     = false;

    // ── DOM جاهز ───────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const form = document.querySelector(FORM_SELECTOR);
        if (!form) return;

        form.addEventListener('submit', handleSubmit);
    }

    // ── معالجة الإرسال ─────────────────────────────────────────────────────
    async function handleSubmit(e) {
        e.preventDefault();

        const form    = e.currentTarget;
        const btn     = document.querySelector(BTN_SELECTOR);
        const alertEl = document.querySelector(ALERT_SELECTOR);

        clearAlert(alertEl);
        setLoading(btn, true);

        try {
            const formData = new FormData(form);

            // أضف hCaptcha response إن كان الـ widget محمَّل
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
                // نجاح — SweetAlert ترحيبي ثم التوجيه
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
                // خطوة 2FA — أظهر حقل الكود وحوّل الـ POST القادم لمسار /admin/login/2fa
                requiresTwofa = true;
                const group = document.querySelector(TWOFAGROUP_SELECTOR);
                if (group) {
                    group.style.display = 'block';
                    const codeInput = document.getElementById('adminTOTP');
                    codeInput.required = true;
                    codeInput.focus();
                }
                showAlert(alertEl, data.message || 'Enter your 2FA code.', 'success');
            } else {
                // فشل
                showAlert(alertEl, data.message || 'Login failed.', 'error');

                // إعادة ضبط hCaptcha إن كانت محمّلة
                if (captchaLoaded && captchaWidgetId !== null && typeof hcaptcha !== 'undefined') {
                    hcaptcha.reset(captchaWidgetId);
                }

                // هل نُظهر الـ CAPTCHA؟
                if (data.show_captcha) {
                    loadCaptchaIfNeeded();
                }

                // إعادة توليد CSRF token
                refreshCsrfToken(form);
            }

        } catch (err) {
            console.error('Admin login error:', err);
            showAlert(alertEl, 'Connection error. Please check your network and try again.', 'error');
        } finally {
            setLoading(btn, false);
        }
    }

    // ── تحميل hCaptcha بشكل كسول (فقط عند الحاجة) ──────────────────────────
    function loadCaptchaIfNeeded() {
        const container = document.getElementById(CAPTCHA_CONTAINER_ID);
        if (!container) return;

        // أظهر الحاوية أولاً
        container.style.display = 'block';
        container.setAttribute('aria-hidden', 'false');

        if (captchaLoaded) return; // لا تُحمّله مرتين

        // احصل على site key من meta tag أو من global var
        const siteKey = getSiteKey();
        if (!siteKey) {
            console.warn('admin-auth.js: HCAPTCHA_SITE_KEY not found');
            return;
        }

        // تحميل script hCaptcha ديناميكياً
        const script = document.createElement('script');
        script.src   = 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=adminHcaptchaOnLoad';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);

        // Callback عند جهوزية الـ API
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
     * يحاول الحصول على hCaptcha Site Key من:
     * 1. data attribute على الـ captcha container
     * 2. window.HCAPTCHA_SITE_KEY (يمكن تمريره من الـ View)
     */
    function getSiteKey() {
        const container = document.getElementById(CAPTCHA_CONTAINER_ID);
        const key = (container?.dataset?.sitekey || window.HCAPTCHA_SITE_KEY || '').trim();

        // قيمة placeholder غير مستبدلة (YOUR_HCAPTCHA_SITE_KEY_HERE) تُعامل كأنها
        // غير مضبوطة — بدون هذا الفحص تُمرَّر إلى hcaptcha.render() فيفشل الويدجت
        // بصمت ولا يُنتج حقل h-captcha-response إطلاقًا. هذا يطابق الفحص المقابل
        // في AdminAuthController::verifyCaptcha() فيبقى الطرفان متسقين.
        if (!key || key.startsWith('YOUR_')) return null;

        return key;
    }

    // ── تحديث CSRF Token بعد كل فشل ───────────────────────────────────────
    async function refreshCsrfToken(form) {
        try {
            const csrfInput = form.querySelector('input[name="csrf_token"]');
            if (!csrfInput) return;

            // endpoint مخصص لجلسة الأدمن — منفصل عن /auth/csrf العام
            const res  = await fetch(APP_ROOT + '/admin/csrf', {
                credentials: 'same-origin',
                headers:     { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (res.ok) {
                const d = await res.json();
                if (d.token) csrfInput.value = d.token;
            }
        } catch {
            // صامت — الفشل هنا لا يوقف العمل
        }
    }

    // ── مؤقت الحظر (للعرض فقط — الحظر الحقيقي server-side) ────────────────
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

    // export للاستخدام الخارجي إن احتجنا لاحقاً
    window.adminAuth = { startLockoutTimer };

})();
