// ══════════════════════════════════════════════════════════════
// js/core/modal-input-colors.js — forcing the colours of fields inside modals
// ══════════════════════════════════════════════════════════════
//
// Moved out of an inline <script> block (29 lines) in app/views/inc/footer.php.
// A pure move: the block injected no PHP values.
//
// ⚠️ This file is a workaround for a CSS problem, not interface logic:
// the sign-in, registration and "forgot password" fields inside Bootstrap modals
// inherited the browser's colours rather than the theme's, so the colour is forced here
// with setProperty(..., 'important') on every field when the modal opens, when the theme
// is switched, and on focus.
//
// The proper fix is a CSS rule on `.modal input` that respects dark mode — but that is a
// change in the styling layer with a visible result, outside the scope of moving files.
// It was moved as-is and documented, to be decided later.

(function fixInputFocus() {
    'use strict';

    const MODAL_INPUTS =
        '#loginModal input:not([type="checkbox"]), #forgotModal input, #registerModal input:not([type="checkbox"]),'
        + ' #registerModal select, #registerModal textarea';

    function themeColors() {
        const isDark = document.body.classList.contains('dark-mode');
        return {
            bg: isDark ? '#21262d' : '#ffffff',
            fg: isDark ? '#e6edf3' : '#1a1a2e',
        };
    }

    function applyInputColors() {
        const { bg, fg } = themeColors();
        document.querySelectorAll(MODAL_INPUTS).forEach(el => {
            el.style.setProperty('background-color', bg, 'important');
            el.style.setProperty('color', fg, 'important');
        });
    }

    document.addEventListener('shown.bs.modal', applyInputColors);

    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => setTimeout(applyInputColors, 50));
    }

    document.addEventListener('focusin', function (e) {
        if (e.target.type === 'checkbox') return;
        const modal = e.target.closest('#loginModal, #forgotModal, #registerModal');
        if (!modal) return;
        const { bg, fg } = themeColors();
        e.target.style.setProperty('background-color', bg, 'important');
        e.target.style.setProperty('color', fg, 'important');
    });
})();
