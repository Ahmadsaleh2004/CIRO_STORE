// ══════════════════════════════════════════════════════════════
// js/admin/site-settings.js — saving the site settings over AJAX
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {

    const form = document.getElementById('siteSettingsForm');
    if (!form) return; // Confirm we really are on the settings page

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const btn          = document.getElementById('saveSettingsBtn');
        const originalText = btn.innerHTML;
        btn.disabled     = true;
        btn.innerHTML    = '⏳ Saving...';

        const fd = new FormData(form);

        try {
            // fetchWithCsrfRetry from js/core/csrf.js — it renews the CSRF token automatically
            const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/settings', {
                method: 'POST',
                body: fd,
            });

            // showToast(message, icon) from js/core/ui.js
            if (data.success) {
                showToast(data.message || 'Settings saved successfully!', 'success');
            } else {
                showToast(data.message || 'Error saving settings.', 'error');
            }
        } catch (err) {
            console.error('Site settings save failed:', err);
            showToast('Unexpected error, please try again.', 'error');
        } finally {
            btn.disabled  = false;
            btn.innerHTML = originalText;
        }
    });

});
