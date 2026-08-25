(function () {
    const msgArea = document.getElementById('contactMessage');
    const sendBtn = document.getElementById('contactSendBtn');
    if (!msgArea || !sendBtn) return;

    function checkContact() {
        if (!window.__userLoggedIn) {
            sendBtn.disabled = true;
            return;
        }
        const ok = msgArea.value.trim().length >= 10;
        if (typeof updateButtonState === 'function') {
            updateButtonState(sendBtn, ok);
        } else {
            sendBtn.disabled = !ok;
        }
    }
    msgArea.addEventListener('input', checkContact);
    checkContact();

    const contactForm = document.querySelector('#contactMessage')?.closest('form');
    if (contactForm) {
        contactForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (sendBtn.disabled) return;

            sendBtn.disabled = true;
            const originalText = sendBtn.textContent;
            sendBtn.textContent = 'Sending…';

            try {
                const fd = new FormData(contactForm);
                const res = await fetch(window.BASE_URL + '/contact/send', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                });
                const data = await res.json();

                if (typeof showToast === 'function') {
                    showToast(data.message, data.success ? 'success' : 'error');
                }

                if (data.success) {
                    msgArea.value = '';
                    checkContact();
                }
            } catch (err) {
                if (typeof showToast === 'function') showToast('Network error, please try again.', 'error');
            } finally {
                sendBtn.disabled = false;
                sendBtn.textContent = originalText;
                checkContact();
            }
        });
    }
})();
