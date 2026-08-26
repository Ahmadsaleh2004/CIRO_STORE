// ══════════════════════════════════════════════════════════════
// public/js/shared/order-cancel.js — زر إلغاء/حذف الطلب المشترك
// (يستخدمه my-info.php للمستخدم + order-details.php للأدمن)
// السياق يُحدَّد عبر data-context على الزر (user | admin).
// CSRF: window._csrfToken (سياق الأدمن من navbar.php) أو
//       window.CSRF_INFO (سياق المستخدم من my-info.php).
// fetchWithCsrfRetry تعمل في السياقين: تختار /admin/csrf عند وجود
// window.URLROOT (الأدمن) و/auth/csrf عند وجود BASE_URL (المتجر).
// ══════════════════════════════════════════════════════════════

document.querySelectorAll('.order-cancel-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const orderId  = btn.dataset.orderId;
        const context  = btn.dataset.context;
        const endpoint = btn.dataset.endpoint;
        const isAdmin  = context === 'admin';

        const conf = await Swal.fire({
            icon: 'warning',
            title: isAdmin ? 'Delete this order permanently?' : 'Cancel Order?',
            text:  isAdmin
                ? `Order #${orderId} will be permanently deleted. This cannot be undone.`
                : `Are you sure you want to cancel order #${orderId}?`,
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: isAdmin ? 'Yes, Delete' : 'Yes, Cancel',
        });
        if (!conf.isConfirmed) return;

        const csrf   = window._csrfToken || window.CSRF_INFO || '';
        const fd     = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('order_id',   orderId);

        try {
            const data = await fetchWithCsrfRetry(endpoint, { method: 'POST', body: fd });

            if (data.success) {
                Swal.fire({ icon: 'success', text: data.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', text: data.message });
            }
        } catch (e) {
            Swal.fire({ icon: 'error', text: 'Network error. Please try again.' });
        }
    });
});