// ══════════════════════════════════════════════════════════════
// js/admin/my-info.js — the "my info" page in the admin panel
// ══════════════════════════════════════════════════════════════
//
// This file used to be two inline <script> blocks (144 lines) inside
// app/views/admin/my-info.php: updating the account details, and enabling or disabling 2FA.
//
// The move here is pure, with no data-* attributes: neither block injected any PHP value
// at all — all they need is window.URLROOT (set by admin/inc/head.php) and DOM element ids.
//
// Loaded through extraScripts from AdminMyInfoController rather than from the admin
// footer: that footer already loads thirteen files on every page, and there is no reason
// to add a fourteenth belonging to one page. my-info.css is already loaded the same way.

(function () {
    'use strict';

    // ── Updating the account details ────────────────────────────
    document.getElementById('adminProfileForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        const form  = e.target;
        const msgEl = document.getElementById('profileMsg');
        const data  = Object.fromEntries(new FormData(form));
        msgEl.style.display = 'none';

        try {
            const res = await fetchWithCsrfRetry(window.URLROOT + '/admin/my-info', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(data),
            });

            msgEl.className   = res.success
                ? 'alert alert-success py-2 small'
                : 'alert alert-danger py-2 small';
            msgEl.textContent = res.message;
            msgEl.style.display = 'block';

            if (res.success) {
                form.querySelector('[name="current_password"]').value = '';
            }
        } catch {
            msgEl.className   = 'alert alert-danger py-2 small';
            msgEl.textContent = 'Network error.';
            msgEl.style.display = 'block';
        }
    });

    // ── 2FA (TOTP) — enabling and disabling over AJAX ───────────
    const msgEl = document.getElementById('twofaMsg');
    if (!msgEl) return;

    function showMsg(success, text) {
        msgEl.className   = success
            ? 'alert alert-success py-2 small'
            : 'alert alert-danger py-2 small';
        msgEl.textContent = text;
        msgEl.style.display = 'block';
    }

    // fetchWithCsrfRetry has supported JSON bodies since phase 6b-1: it rebuilds the body
    // with the new token and preserves the remaining fields. Before that it corrupted them,
    // which is why this file used a bare fetch.
    async function postJson(url, data) {
        return fetchWithCsrfRetry(window.URLROOT + url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(data),
        });
    }

    const csrf = () => document.querySelector('#adminProfileForm [name="csrf_token"]')?.value || '';

    // Enabling — step 1: generate the secret and show the QR code
    const enableBtn = document.getElementById('twofaEnableBtn');
    if (enableBtn) {
        enableBtn.addEventListener('click', async () => {
            enableBtn.disabled = true;
            msgEl.style.display = 'none';
            try {
                const d = await postJson('/admin/my-info/2fa/generate', { csrf_token: csrf() });
                if (!d.success) {
                    showMsg(false, d.message || 'Could not start 2FA setup.');
                    enableBtn.disabled = false;
                    return;
                }
                // ⚠️ twofaSetupStep carries `d-none` in the markup, which is
                // `display:none !important` — so style.display='block' does not reveal it.
                // The two-factor setup step never opened at all: the secret was created on
                // the server and the admin never saw it.
                //
                // twofaSetup does not carry d-none, so hiding it with style still works.
                document.getElementById('twofaSetup').style.display = 'none';
                document.getElementById('twofaSetupStep').classList.remove('d-none');
                document.getElementById('twofaQr').src  = d.qrcode_url;
                document.getElementById('twofaSecret').textContent = d.secret;
                document.getElementById('twofaCode').focus();
            } catch {
                showMsg(false, 'Network error.');
                enableBtn.disabled = false;
            }
        });

        // Cancelling the setup
        document.getElementById('twofaCancelBtn').addEventListener('click', () => {
            document.getElementById('twofaSetupStep').classList.add('d-none');
            document.getElementById('twofaSetup').style.display = 'block';
            enableBtn.disabled = false;
        });

        // Enabling — step 2: confirming the first code
        document.getElementById('twofaConfirmBtn').addEventListener('click', async () => {
            const code = document.getElementById('twofaCode').value.trim();
            if (!/^\d{6}$/.test(code)) {
                showMsg(false, 'Please enter the 6-digit code from your authenticator app.');
                return;
            }
            const btn = document.getElementById('twofaConfirmBtn');
            btn.disabled = true;
            msgEl.style.display = 'none';
            try {
                const d = await postJson('/admin/my-info/2fa/confirm', {
                    csrf_token: csrf(),
                    code:       code,
                });
                if (d.success) {
                    showMsg(true, d.message);
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    showMsg(false, d.message || 'Invalid code.');
                    btn.disabled = false;
                    document.getElementById('twofaCode').focus();
                }
            } catch {
                showMsg(false, 'Network error.');
                btn.disabled = false;
            }
        });
    }

    // Disabling — it requires the current password
    const disableForm = document.getElementById('twofaDisableForm');
    if (disableForm) {
        disableForm.addEventListener('submit', async e => {
            e.preventDefault();
            const passEl = document.getElementById('twofaDisablePassword');
            if (!passEl.value) {
                showMsg(false, 'Please enter your current password.');
                return;
            }
            const data = Object.fromEntries(new FormData(disableForm));
            msgEl.style.display = 'none';
            try {
                const d = await postJson('/admin/my-info/2fa/disable', data);
                if (d.success) {
                    showMsg(true, d.message);
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    showMsg(false, d.message || 'Could not disable 2FA.');
                    passEl.value = '';
                }
            } catch {
                showMsg(false, 'Network error.');
            }
        });
    }
})();
