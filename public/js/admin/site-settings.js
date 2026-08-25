// ══════════════════════════════════════════════════════════════
// js/admin/site-settings.js — حفظ إعدادات الموقع عبر AJAX
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {

    var form = document.getElementById('siteSettingsForm');
    if (!form) return; // نتأكد إننا فعلاً بصفحة settings

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        var btn          = document.getElementById('saveSettingsBtn');
        var originalText = btn.innerHTML;
        btn.disabled     = true;
        btn.innerHTML    = '⏳ Saving...';

        var fd = new FormData(form);

        try {
            // fetchWithCsrfRetry من js/core/csrf.js — يعالج تجديد الـ CSRF تلقائياً
            var data = await fetchWithCsrfRetry(window.URLROOT + '/admin/settings', {
                method: 'POST',
                body: fd,
            });

            // showToast(message, icon) من js/core/ui.js
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
