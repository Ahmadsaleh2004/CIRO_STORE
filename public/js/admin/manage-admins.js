// ══════════════════════════════════════════════════════════════
// public/js/admin/manage-admins.js
// مسؤول عن: صفوف الجدول القابلة للنقر، فورم Add، Modal Edit
//            (openPermModal + تفعيل Save)، حذف الأدمن (SweetAlert2).
// notify + broadcast → admins.js (مشترك)
// يستعمل fetchWithCsrfRetry لكل POST.
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {

    // ── صفوف الجدول القابلة للنقر → صفحة التفاصيل ──────────────
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => {
            window.location.href = row.dataset.href;
        });
    });

    // ── فورم Add Admin: تفعيل الزر عند اكتمال الحقول ────────────
    const name    = document.getElementById('newAdmName');
    const email   = document.getElementById('newAdmEmail');
    const pass    = document.getElementById('newAdmPassword');
    const reason  = document.getElementById('newAdmReason');
    const current = document.getElementById('newAdmCurrentPass');
    const addBtn  = document.getElementById('addAdminBtn');

    if (addBtn) {
        const checkAddForm = () => {
            const ok = (name?.value.trim().length >= 2)
                && /^[^\s@]+@gmail\.com$/.test(email?.value.trim() || '')
                && (pass?.value.length >= 6)
                && (reason?.value.trim().length > 0)
                && (current?.value.length > 0);
            addBtn.style.display = ok ? '' : 'none';
        };
        [name, email, pass, reason, current].forEach(el => el?.addEventListener('input', checkAddForm));
        checkAddForm();
    }

    // ── Toggle Show/Hide Password (Add Admin form) ──────────────
    const toggleBtn  = document.getElementById('toggleNewAdmPassword');
    const passInput  = document.getElementById('newAdmPassword');
    if (toggleBtn && passInput) {
        toggleBtn.addEventListener('click', () => {
            const isHidden = passInput.type === 'password';
            passInput.type = isHidden ? 'text' : 'password';
            toggleBtn.textContent = isHidden ? '🙈' : '👁';
        });
    }

    // ── Toggle Show/Hide Password (Add Admin form — confirmation field) ──
    const toggleCurrentBtn = document.getElementById('toggleNewAdmCurrentPass');
    const currentPassInput = document.getElementById('newAdmCurrentPass');
    if (toggleCurrentBtn && currentPassInput) {
        toggleCurrentBtn.addEventListener('click', () => {
            const isHidden = currentPassInput.type === 'password';
            currentPassInput.type = isHidden ? 'text' : 'password';
            toggleCurrentBtn.textContent = isHidden ? '🙈' : '👁';
        });
    }

    // ── Add Admin form: AJAX submit ─────────────────────────────────
    document.getElementById('addAdminForm')?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData(this);

        try {
            const data = await fetchWithCsrfRetry(this.action, { method: 'POST', body: fd });

            if (data.success) {
                showToast(data.message || 'Admin added successfully.', 'success');
                setTimeout(() => {
                    window.location.href = window.URLROOT + '/admin/admins';
                }, 1200);
            } else {
                showToast(data.message || 'Could not add admin.', 'error');
            }
        } catch (err) {
            showToast('Network error. Please try again.', 'error');
        }
    });

    // ── Edit Permissions Modal: تفعيل زر Save ───────────────────
    const editReason  = document.getElementById('editAdminReason');
    const editPass    = document.getElementById('confirm_edit_pass');
    const editSaveBtn = document.getElementById('savePermsBtn');

    const checkEditForm = () => {
        if (editSaveBtn) {
            editSaveBtn.disabled = !(
                editReason?.value.trim().length > 0
                && editPass?.value.length > 0
            );
        }
    };
    editReason?.addEventListener('input', checkEditForm);
    editPass?.addEventListener('input', checkEditForm);

    // ── Edit Permissions Modal: AJAX submit ─────────────────────────
    document.getElementById('permForm')?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData(this);

        try {
            const data = await fetchWithCsrfRetry(this.action, { method: 'POST', body: fd });

            if (data.success) {
                showToast(data.message || 'Admin updated successfully.', 'success');
                const modalEl = document.getElementById('permModal');
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                setTimeout(() => window.location.reload(), 800);
            } else {
                showToast(data.message || 'Could not update admin.', 'error');
            }
        } catch (err) {
            showToast('Network error. Please try again.', 'error');
        }
    });

    // ── Delete Admin (SweetAlert2 — نفس نمط Delete Product بالضبط) ─
    document.querySelectorAll('.del-admin-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id   = btn.dataset.id;
            const name = btn.dataset.name;

            Swal.fire({
                title: 'Delete Admin?',
                text:  '"' + name + '" will be permanently removed.',
                icon:  'warning',
                html: `
                    <div class="mb-3 text-start">
                        <label class="form-label small fw-bold">Reason <span class="text-danger">*</span></label>
                        <textarea id="swal-del-reason" class="form-control form-control-sm" rows="2" placeholder="Reason for deletion..."></textarea>
                    </div>
                    <div class="text-start">
                        <label class="form-label small fw-bold">Your Password <span class="text-danger">*</span></label>
                        <input id="swal-del-pass" type="password" class="form-control form-control-sm" placeholder="Current password...">
                    </div>
                `,
                showCancelButton:   true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor:  '#6c757d',
                confirmButtonText:  '🗑 Confirm Delete',
                cancelButtonText:   'Cancel',
                preConfirm: () => {
                    const reason = document.getElementById('swal-del-reason')?.value.trim();
                    const pass   = document.getElementById('swal-del-pass')?.value;
                    if (!reason) {
                        Swal.showValidationMessage('A reason is required.');
                        return false;
                    }
                    if (!pass) {
                        Swal.showValidationMessage('Your password is required.');
                        return false;
                    }
                    return { reason, pass };
                },
            }).then(async function (result) {
                if (!result.isConfirmed) return;

                const fd = new FormData();
                fd.append('target_id',        id);
                fd.append('delete_reason',    result.value.reason);
                fd.append('confirm_del_pass', result.value.pass);
                fd.append('csrf_token',       window._csrfToken || '');

                try {
                    const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/admins/delete', {
                        method: 'POST',
                        body: fd,
                    });

                    if (data.success) {
                        if (typeof showToast === 'function') showToast(data.message, 'success');
                        setTimeout(() => window.location.reload(), 700);
                    } else {
                        if (typeof showToast === 'function') showToast(data.message || 'Error', 'error');
                    }
                } catch (err) {
                    if (typeof showToast === 'function') showToast('Network error.', 'error');
                }
            });
        });
    });

});

// ── openPermModal — مستدعاة من onclick بكل صف بالجدول ────────
window.openPermModal = function (id, name, role, adm, prod, users, dash, supp, cont, check, ord, branding) {
    try {
        const targetIdEl = document.getElementById('permTargetId');
        if (!targetIdEl) {
            console.error('[openPermModal] عنصر permTargetId غير موجود بالصفحة!');
            return;
        }

        targetIdEl.value = id;
        document.getElementById('permModalTitle').textContent = 'Edit: ' + name;
        document.getElementById('permRole').value             = role;
        document.getElementById('ep_admins').checked          = !!adm;
        document.getElementById('ep_products').checked        = !!prod;
        document.getElementById('ep_users').checked           = !!users;
        document.getElementById('ep_dashboard').checked       = !!dash;
        document.getElementById('ep_support').checked         = !!supp;
        document.getElementById('ep_content').checked         = !!cont;
        document.getElementById('ep_checkout').checked        = !!check;
        document.getElementById('ep_orders').checked          = !!ord;
        document.getElementById('ep_branding').checked        = !!branding;
        document.getElementById('confirm_edit_pass').value    = '';
        document.getElementById('editAdminReason').value      = '';
        document.getElementById('savePermsBtn').disabled      = true;

        const modalEl = document.getElementById('permModal');
        if (!modalEl) {
            console.error('[openPermModal] عنصر permModal (المودال نفسه) غير موجود بالصفحة!');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    } catch (err) {
        console.error('[openPermModal] حدث خطأ غير متوقع:', err);
        alert('حدث خطأ أثناء فتح نافذة التعديل — افتح Console (F12) وشارك تفاصيل الخطأ.');
    }
};
