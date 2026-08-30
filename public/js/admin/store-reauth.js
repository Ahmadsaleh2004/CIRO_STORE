// ══════════════════════════════════════════════════════════════
// js/admin/store-reauth.js — re-authenticating before returning from store mode
// ══════════════════════════════════════════════════════════════
//
// This file used to be an inline <script> block (63 lines) in
// app/views/admin/store-reauth.php. A pure move: the block injected no PHP values, it
// reads window.URLROOT alone (set by a single line in the view — which is passing data
// rather than logic, so it stayed where it was).
//
// updateCsrfToken comes from js/core/csrf.js, loaded before this file.

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('storeReauthForm');
        const btn  = document.getElementById('reauthBtn');
        const alertEl = document.getElementById('alertMsg');
        if (!form || !btn || !alertEl) return;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            alertEl.textContent = '';
            alertEl.className = 'alert-msg';
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Verifying…';

            try {
                const fd = new FormData(form);

                // fetchWithCsrfRetry works here: the view sets window.URLROOT, so it picks
                // /admin/csrf. The benefit is tangible — without the wrapper, a token failure
                // makes the admin type their password again; with it, the request recovers
                // silently.
                //
                // Note: the wrapper calls response.json() unguarded, so a non-JSON response
                // now reaches the outer catch and is shown as "Connection error" rather than
                // "Unexpected server response". Both are error messages for the same case.
                const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/store-mode/reauth', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (data.success) {
                    alertEl.className = 'alert-msg success visible';
                    alertEl.textContent = data.message || 'Verified. Redirecting…';
                    setTimeout(function () {
                        window.location.href = data.redirect || (window.URLROOT + '/admin/home');
                    }, 500);
                } else {
                    alertEl.className = 'alert-msg error visible';
                    alertEl.textContent = data.message || 'Verification failed.';
                    // Refresh the CSRF token if the panel returned one after the failure
                    if (data.csrf_token && typeof updateCsrfToken === 'function') {
                        updateCsrfToken(data.csrf_token);
                    }
                }
            } catch (err) {
                console.error('Store reauth error:', err);
                alertEl.className = 'alert-msg error visible';
                alertEl.textContent = 'Connection error. Please try again.';
            } finally {
                btn.disabled = false;
                // ⚠️ This used to be 'Verify &amp; Return' — and textContent does not decode
                // HTML entities, so the button displayed "Verify &amp; Return" literally after
                // the first failed attempt. The markup in the view uses &amp; because it is
                // HTML; here it is plain text, so the character itself is written.
                btn.textContent = 'Verify & Return';
            }
        });
    });
})();
