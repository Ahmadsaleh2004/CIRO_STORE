// ══════════════════════════════════════════════════════════════
// js/features/account.js — the scripts for the account page (My Info)
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
    // ── 1. Profile Form AJAX Submit ──────────────────────────────
    const profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msgEl = document.getElementById('profileMsg');
            const formData = new FormData(profileForm);
            msgEl.style.display = 'none';

            try {
                const data = await fetchWithCsrfRetry(window.BASE_URL + '/user/info', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.fromEntries(formData))
                });

                msgEl.className = 'alert py-2 small ' + (data.success ? 'alert-success' : 'alert-danger');
                msgEl.textContent = data.message;
                msgEl.style.display = 'block';

                if (data.success) {
                    // Clear password fields after successful save
                    document.getElementById('currentPassword').value = '';
                    document.getElementById('newPassword').value = '';
                }
            } catch {
                msgEl.className = 'alert alert-danger py-2 small';
                msgEl.textContent = 'Network error, please try again.';
                msgEl.style.display = 'block';
            }
        });
    }

    // ── 2. Address Form AJAX Submit ──────────────────────────────
    const addAddrForm = document.getElementById('addAddrForm');
    if (addAddrForm) {
        addAddrForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const form  = e.target;
            const msgEl = document.getElementById('addrMsg');
            const data  = Object.fromEntries(new FormData(form));
            msgEl.style.display = 'none';

            try {
                // ⚠️ The request's result was also named `data` — which shadowed the `data`
                // declared above the try. And because const is block-scoped,
                // JSON.stringify(data) on the line below read the variable **inside its
                // temporal dead zone**, throwing
                // «ReferenceError: Cannot access 'data' before initialization»
                // every single time — meaning "add address" never once worked.
                // The result was renamed to `result` to undo the shadowing.
                const result = await fetchWithCsrfRetry(window.BASE_URL + '/user/addresses', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });

                msgEl.className = 'alert py-2 small ' + (result.success ? 'alert-success' : 'alert-danger');
                msgEl.textContent = result.message;
                msgEl.style.display = 'block';

                if (result.success) {
                    setTimeout(() => location.reload(), 1200);
                }
            } catch {
                msgEl.className = 'alert alert-danger py-2 small';
                msgEl.textContent = 'Network error, please try again.';
                msgEl.style.display = 'block';
            }
        });
    }

    // ── 3. Delete Address ────────────────────────────────────────
    document.querySelectorAll('.delete-addr-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const addrId = btn.dataset.addressId;
            const conf = await Swal.fire({
                icon: 'warning', title: 'Delete Address?',
                showCancelButton: true, confirmButtonColor: '#d33',
            });
            if (!conf.isConfirmed) return;

            try {
                const data = await fetchWithCsrfRetry(window.BASE_URL + '/user/addresses/delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: document.querySelector('input[name="csrf_token"]')?.value || '',
                        address_id: addrId,
                    })
                });

                if (data.success) {
                    btn.closest('.col-md-6')?.remove();
                    Swal.fire({ icon: 'success', text: 'Address deleted.', timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', text: data.message });
                }
            } catch {
                Swal.fire({ icon: 'error', text: 'Network error.' });
            }
        });
    });

    // ── 4. Open Edit Address Modal ──────────────────────────────
    window.openEditAddressModal = function (id, label, fullAddress, country, city, phone, isDefault) {
        const modal = document.getElementById('addressModal');
        if (!modal) return;

        modal.querySelector('.modal-title').textContent = '✏️ Edit Address';
        document.getElementById('addrIdInput').value    = id;
        document.getElementById('addrLabel').value      = label;
        document.getElementById('addrFull').value       = fullAddress;
        document.getElementById('addrCountry').value    = country || '';
        document.getElementById('addrCity').value       = city || '';
        document.getElementById('addrPhone').value      = phone || '';
        document.getElementById('addrDefaultCheck').checked = !!isDefault;

        if (typeof updateButtonState === 'function') {
            updateButtonState(document.getElementById('saveAddrBtn'), true);
        }

        new bootstrap.Modal(modal).show();
    };

    // Reset Address Modal on Close
    const addrModalEl = document.getElementById('addressModal');
    if (addrModalEl) {
        addrModalEl.addEventListener('hidden.bs.modal', () => {
            addrModalEl.querySelector('.modal-title').textContent = '➕ Add New Address';
            document.getElementById('addrIdInput').value = '';
            document.getElementById('addressForm')?.reset();
            if (typeof updateButtonState === 'function') {
                updateButtonState(document.getElementById('saveAddrBtn'), false);
            }
        });
    }

    // ── 5. Show/hide password toggle ──────────────────────────
    document.querySelectorAll('.toggle-password-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.dataset.target);
            if (!target) return;
            const isHidden = target.type === 'password';
            target.type = isHidden ? 'text' : 'password';
            btn.textContent = isHidden ? '🙈' : '👁';
        });
    });
});