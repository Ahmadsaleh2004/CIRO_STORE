document.addEventListener('submit', async (e) => {
    const form = e.target.closest('.js-notify-form');
    if (!form) return;
    e.preventDefault();

    const btn = form.querySelector('.js-notify-btn');
    const productId = form.dataset.productId;
    const csrfInput = form.querySelector('[name="csrf_token"]');
    const csrf = csrfInput ? csrfInput.value : (window.__csrfTokenForWishlist || '');

    if (btn.disabled) return; // already notified, nothing to do

    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = 'Sending…';

    try {
        const fd = new FormData();
        fd.append('product_id', productId);
        fd.append('csrf_token', csrf);

        const data = await fetchWithCsrfRetry(window.BASE_URL + '/handlers/notify_handler.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        });

        if (data.success) {
            btn.textContent = "✅ We'll notify you!";
            btn.classList.remove('btn-outline-warning');
            btn.classList.add('btn-success');
            if (typeof showToast === 'function') showToast('We will notify you once this is back in stock.', 'success');
        } else {
            btn.disabled = false;
            btn.textContent = originalText;
            if (typeof showToast === 'function') showToast(data.message || 'Something went wrong.', 'error');
        }
    } catch {
        btn.disabled = false;
        btn.textContent = originalText;
        if (typeof showToast === 'function') showToast('Network error, please try again.', 'error');
    }
});