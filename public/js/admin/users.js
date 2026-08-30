// ══════════════════════════════════════════════════════════════
// public/js/admin/users.js — the user list and a user's details.
// notify → admins.js (openNotifyModal is shared).
// It uses fetchWithCsrfRetry for every POST — the shared CSRF safety net.
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {

    // ── Clickable table rows → the details page ─────────────────
    document.querySelectorAll('.user-row').forEach(function (row) {
        row.addEventListener('mouseenter', () => { row.style.backgroundColor = 'rgba(99,102,241,.06)'; });
        row.addEventListener('mouseleave', () => { row.style.backgroundColor = ''; });
        row.addEventListener('click', function (e) {
            if (e.target.closest('button, a, form, input')) return;
            // The full href (not just the pathname) — the controller's open-redirect check
            // compares against the complete URLROOT (scheme + host), so the href must be sent
            window.location.href = window.URLROOT + '/admin/users/details?id=' + row.dataset.uid;
        });
    });

    // ── Delete User ─────────────────────────────────────────────
    document.querySelectorAll('.delete-user-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const uid  = btn.dataset.uid;
            const name = btn.dataset.name;   // ⚠️ Never pass this inside title: or html:

            Swal.fire({
                title: 'Delete User?',
                text: '"' + name + '" will be permanently deleted along with all their data.',
                icon: 'warning',
                input: 'textarea',
                inputPlaceholder: 'Reason for deleting this user (required)...',
                inputValidator: (value) => (!value || !value.trim()) ? 'A reason is required.' : undefined,
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
            }).then(async function (result) {
                if (!result.isConfirmed) return;
                const fd = new FormData();
                fd.append('user_id', uid);
                fd.append('reason', (result.value || '').trim());
                fd.append('csrf_token', window._csrfToken || '');

                try {
                    const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/users/delete', { method: 'POST', body: fd });
                    if (data.success) {
                        if (typeof showToast === 'function') showToast(data.message, 'success');
                        const row = document.querySelector('.user-row[data-uid="' + uid + '"]');
                        if (row) { row.style.transition = 'opacity .3s'; row.style.opacity = '0'; setTimeout(() => row.remove(), 300); }
                    } else {
                        if (typeof showToast === 'function') showToast(data.message || 'Error', 'error');
                    }
                } catch {
                    if (typeof showToast === 'function') showToast('Network error.', 'error');
                }
            });
        });
    });

    // ── Strikes (three fixed circles — the same logic as the old project) ──
    document.querySelectorAll('.strike-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const index    = btn.dataset.index;
            const isActive = btn.dataset.active === '1';
            const strikeId = btn.dataset.strikeId;
            const userId   = btn.dataset.userId;

            if (isActive) {
                // Removing a strike
                const result = await Swal.fire({
                    title: 'Remove Strike #' + index + '?',
                    text: "This will remove the strike from the user's record.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'Yes, Remove',
                    cancelButtonText: 'Cancel',
                });
                if (!result.isConfirmed) return;

                const fd = new FormData();
                fd.append('strike_id', strikeId);
                fd.append('user_id', userId);
                fd.append('csrf_token', window._csrfToken || '');
                try {
                    const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/users/strikes/remove', { method: 'POST', body: fd });
                    if (data.success) { setTimeout(() => window.location.reload(), 500); }
                    else if (typeof showToast === 'function') showToast(data.message || 'Error', 'error');
                } catch {
                    if (typeof showToast === 'function') showToast('Network error.', 'error');
                }
            } else {
                // Adding a strike
                const result = await Swal.fire({
                    title: 'Issue Strike #' + index,
                    input: 'textarea',
                    inputPlaceholder: 'Reason for this strike (required)...',
                    inputValidator: (value) => (!value || !value.trim()) ? 'A reason is required.' : undefined,
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'Issue Strike',
                    cancelButtonText: 'Cancel',
                });
                if (!result.isConfirmed) return;

                const fd = new FormData();
                fd.append('user_id', userId);
                fd.append('reason', (result.value || '').trim());
                fd.append('csrf_token', window._csrfToken || '');
                try {
                    const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/users/strikes/add', { method: 'POST', body: fd });
                    if (data.success) { setTimeout(() => window.location.reload(), 500); }
                    else if (typeof showToast === 'function') showToast(data.message || 'Error', 'error');
                } catch {
                    if (typeof showToast === 'function') showToast('Network error.', 'error');
                }
            }
        });
    });

});
