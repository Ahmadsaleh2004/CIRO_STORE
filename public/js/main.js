// ══════════════════════════════════════════════════════════════
// js/main.js — Entry Point
// It initialises the components and events in the right order once the document loads
// ══════════════════════════════════════════════════════════════

document.addEventListener("DOMContentLoaded", () => {
    // 1. Apply and activate the theme
    if (typeof applySavedTheme === 'function') applySavedTheme();
    if (typeof initializeTheme === 'function') initializeTheme();

    // 2. Refresh the counters and the navigation icons
    if (typeof updateCounters === 'function') updateCounters();

    // 3. Initialise the general interactive page elements
    if (typeof initBackToTop === 'function') initBackToTop();
    if (typeof initPageTransitions === 'function') initPageTransitions();
    if (typeof initImageFallbacks === 'function') initImageFallbacks();

    // 4. The navbar's backdrop blur on scroll
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

    // 5. Open the login modal automatically on ?openLogin=1,
    //    or show a Google OAuth error on &error=google_xxx
    (function handleLoginParams() {
        const params     = new URLSearchParams(window.location.search);
        const openLogin  = params.get('openLogin');
        const errorCode  = params.get('error');

        if (!openLogin && !errorCode) return;

        // The Google OAuth error messages
        const googleErrors = {
            google_unavailable:  'Google Sign-In is not configured yet. Please use email and password.',
            google_cancelled:    'Google Sign-In was cancelled. Please try again.',
            google_no_email:     'Could not retrieve your email from Google. Please try again.',
            google_create_failed:'Failed to create your account. Please try again or register manually.',
            google_error:        'Google Sign-In failed. Please try again or use email and password.',
        };

        // Clear the params out of the URL so the modal does not reopen on a refresh
        const cleanUrl = window.location.pathname;
        history.replaceState(null, '', cleanUrl);

        const loginModalEl = document.getElementById('loginModal');
        if (!loginModalEl) return;

        const showLoginModal = () => {
            new bootstrap.Modal(loginModalEl).show();
        };

        if (errorCode && googleErrors[errorCode]) {
            // Show the error message first, then open the modal
            Swal.fire({
                icon:             'error',
                title:            'Sign-In Failed',
                text:             googleErrors[errorCode],
                confirmButtonText:'Try Again',
                confirmButtonColor:'#1a1a2e',
            }).then(showLoginModal);
        } else if (openLogin === '1') {
            // Open the modal straight away
            showLoginModal();
        }
    })();
});