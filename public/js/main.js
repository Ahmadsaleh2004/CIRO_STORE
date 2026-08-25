// ══════════════════════════════════════════════════════════════
// js/main.js — Entry Point
// يُهيّئ المكونات والأحداث بالترتيب الصحيح عند تحميل المستند
// ══════════════════════════════════════════════════════════════

document.addEventListener("DOMContentLoaded", () => {
    // 1. تطبيق الثيم وتفعيله
    if (typeof applySavedTheme === 'function') applySavedTheme();
    if (typeof initializeTheme === 'function') initializeTheme();

    // 2. تحديث العدادات وأيقونات التصفح
    if (typeof updateCounters === 'function') updateCounters();

    // 3. تهيئة عناصر الصفحة التفاعلية العامة
    if (typeof initBackToTop === 'function') initBackToTop();
    if (typeof initPageTransitions === 'function') initPageTransitions();
    if (typeof initImageFallbacks === 'function') initImageFallbacks();

    // 4. Navbar Backdrop Blur عند السكرول
    (function initNavbarBlur() {
        const navbar = document.getElementById('mainNavbar');
        if (!navbar) return;
        window.addEventListener('scroll', () => {
            const isDark = document.body.classList.contains('dark-mode');
            if (window.scrollY > 50) {
                navbar.style.backdropFilter       = 'blur(14px)';
                navbar.style.webkitBackdropFilter = 'blur(14px)';
                navbar.style.backgroundColor      = isDark
                    ? 'rgba(1,4,9,0.85)' : 'rgba(26,26,46,0.85)';
            } else {
                navbar.style.backdropFilter       = '';
                navbar.style.webkitBackdropFilter = '';
                navbar.style.backgroundColor      = '';
            }
        }, { passive: true });
    })();

    // 5. فتح Login Modal تلقائياً عند ?openLogin=1
    //    أو عرض رسالة خطأ Google OAuth عند &error=google_xxx
    (function handleLoginParams() {
        const params     = new URLSearchParams(window.location.search);
        const openLogin  = params.get('openLogin');
        const errorCode  = params.get('error');

        if (!openLogin && !errorCode) return;

        // رسائل أخطاء Google OAuth
        const googleErrors = {
            google_unavailable:  'Google Sign-In is not configured yet. Please use email and password.',
            google_cancelled:    'Google Sign-In was cancelled. Please try again.',
            google_no_email:     'Could not retrieve your email from Google. Please try again.',
            google_create_failed:'Failed to create your account. Please try again or register manually.',
            google_error:        'Google Sign-In failed. Please try again or use email and password.',
        };

        // نظّف الـ URL من الـ params حتى لا يُعاد فتح المودال عند Refresh
        const cleanUrl = window.location.pathname;
        history.replaceState(null, '', cleanUrl);

        const loginModalEl = document.getElementById('loginModal');
        if (!loginModalEl) return;

        const showLoginModal = () => {
            new bootstrap.Modal(loginModalEl).show();
        };

        if (errorCode && googleErrors[errorCode]) {
            // اعرض رسالة الخطأ أولاً، ثم افتح المودال بعدها
            Swal.fire({
                icon:             'error',
                title:            'Sign-In Failed',
                text:             googleErrors[errorCode],
                confirmButtonText:'Try Again',
                confirmButtonColor:'#1a1a2e',
            }).then(showLoginModal);
        } else if (openLogin === '1') {
            // افتح المودال مباشرة
            showLoginModal();
        }
    })();
});