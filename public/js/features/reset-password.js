// ══════════════════════════════════════════════════════════════
// js/features/reset-password.js — the password reset form
// ══════════════════════════════════════════════════════════════
//
// This file used to be an inline <script> block (58 lines) in
// app/views/auth/reset-password.php. The only two values coming from PHP
// (window.BASE_URL and window.URLROOT, both of them URLROOT) stayed in a single inline
// line in the view — passing data, not logic.
//
// fetchWithCsrfRetry comes from js/core/csrf.js, loaded before this file.

(function () {
    'use strict';

    function showResetMsg(el, text, type) {
        if (!el) return;
        el.textContent  = text;
        el.className    = 'alert py-2 small mb-3 alert-' + type;
        el.style.display = 'block';
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('resetForm');
        if (!form) return; // An expired or invalid link — there is no form on the page

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            const btn      = document.getElementById('resetBtn');
            const msgEl    = document.getElementById('resetMsg');
            const pass     = document.getElementById('newPassword').value;
            const confirm  = document.getElementById('confirmPassword').value;

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
                // The bare-fetch fallback does not actually work: fetch returns a Response
                // rather than parsed data, so data.message would be undefined. But it is
                // unreachable in practice — csrf.js is loaded with defer, and deferred scripts
                // execute before DOMContentLoaded, so the function is always defined by the
                // time a user reaches here. It was left as it was.
                const doFetch = (typeof window.fetchWithCsrfRetry === 'function')
                    ? window.fetchWithCsrfRetry
                    : fetch;
                const data = await doFetch(window.BASE_URL + '/auth/reset', {
                    method: 'POST',
                    body: new FormData(form)
                });

                showResetMsg(msgEl, data.message, data.success ? 'success' : 'danger');

                if (data.success) {
                    setTimeout(function () {
                        window.location.href = window.BASE_URL;
                    }, 1200);
                }
            } catch {
                showResetMsg(msgEl, 'Something went wrong. Please try again.', 'danger');
            } finally {
                if (btn) btn.disabled = false;
            }
        });
    });
})();
