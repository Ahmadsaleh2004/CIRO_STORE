// ══════════════════════════════════════════════════════════════
// public/js/shared/order-cancel.js — the shared cancel/delete order button.
// (Used by my-info.php for the user and order-details.php for the admin.)
// The context is set through data-context on the button (user | admin).
// CSRF: window._csrfToken (the admin context, from navbar.php) or
//       window.CSRF_INFO (the user context, from my-info.php).
// fetchWithCsrfRetry works in both: it picks /admin/csrf when window.URLROOT exists (the
// admin) and /auth/csrf when BASE_URL exists (the store).
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
        } catch {
            Swal.fire({ icon: 'error', text: 'Network error. Please try again.' });
        }
    });
});