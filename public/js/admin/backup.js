// ══════════════════════════════════════════════════════════════
// public/js/admin/backup.js — the database backup page.
// Responsible for: the create button (with its spinner) and the delete buttons.
// It uses fetchWithCsrfRetry for every POST, taking the token from backupCsrfToken or
// window._csrfToken.
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {

    const csrfValue = () => document.getElementById('backupCsrfToken')?.value || window._csrfToken || '';

    // ── Creating a new backup ──────────────────────────────────
    const createBtn = document.getElementById('createBackupBtn');
    if (createBtn) {
        createBtn.addEventListener('click', async () => {
            const result = await Swal.fire({
                title: 'Create a new backup?',
                text: 'A full SQL dump of the database will be created. This may take a few seconds.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#198754',
                confirmButtonText: 'Yes, Backup Now',
                cancelButtonText: 'Cancel',
            });
            if (!result.isConfirmed) return;

            createBtn.disabled = true;
            createBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Creating...';

            try {
                const fd = new FormData();
                fd.append('csrf_token', csrfValue());

                const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/backup/create', { method: 'POST', body: fd });

                if (data.success) {
                    if (typeof showToast === 'function') showToast('Backup created. Refreshing list...', 'success');
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    if (typeof showToast === 'function') showToast(data.message || 'Error creating backup.', 'error');
                }
            } catch {
                if (typeof showToast === 'function') showToast('Network error.', 'error');
            } finally {
                createBtn.disabled = false;
                createBtn.innerHTML = '➕ Create Backup Now';
            }
        });
    }

    // ── Deleting a backup ──────────────────────────────────────
    document.querySelectorAll('.backup-delete-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const filename = btn.dataset.file;
            if (!filename) return;

            const result = await Swal.fire({
                title: 'Delete this backup?',
                text: `"${filename}" will be permanently removed.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
            });
            if (!result.isConfirmed) return;

            btn.disabled = true;

            try {
                const fd = new FormData();
                fd.append('file', filename);
                fd.append('csrf_token', csrfValue());

                const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/backup/delete', { method: 'POST', body: fd });

                if (data.success) {
                    if (typeof showToast === 'function') showToast('Backup deleted.', 'success');
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    if (typeof showToast === 'function') showToast(data.message || 'Error deleting backup.', 'error');
                    btn.disabled = false;
                }
            } catch {
                if (typeof showToast === 'function') showToast('Network error.', 'error');
                btn.disabled = false;
            }
        });
    });

});