// ══════════════════════════════════════════════════════════════
// public/js/admin/admins.js — notify + broadcast مشتركَين
// مسؤول فقط عن: openNotifyModal (generic)، notifyModal، broadcastModal.
// Add/Edit/Delete → manage-admins.js
// يستعمل fetchWithCsrfRetry لنقطتَي الإرسال (notify · broadcast).
// ══════════════════════════════════════════════════════════════

// State المشترك للـ Modal — يُعبّأ عبر openNotifyModal()
let currentNotifyTarget = { type: null, id: null };

// openNotifyModal — مستدعاة من onclick بأي view فيه notify button
window.openNotifyModal = function (targetType, id, name) {
    currentNotifyTarget = { type: targetType, id: id };

    const nameEl = document.getElementById('notifyTargetName');
    if (nameEl) nameEl.textContent = name;

    // reset الحقول
    const titleEl = document.getElementById('notifyTitleInput');
    const bodyEl  = document.getElementById('notifyBodyInput');
    if (titleEl) titleEl.value = '';
    if (bodyEl)  bodyEl.value  = '';

    // reset hidden inputs
    const typeEl = document.getElementById('notifyTargetType');
    const idEl   = document.getElementById('notifyTargetId');
    if (typeEl) typeEl.value = targetType;
    if (idEl)   idEl.value   = id;

    const sendBtn = document.getElementById('notifySendBtn');
    if (typeof updateButtonState === 'function') {
        updateButtonState(sendBtn, false);
    } else if (sendBtn) {
        sendBtn.disabled = true;
    }

    new bootstrap.Modal(document.getElementById('notifyModal')).show();
};

document.addEventListener('DOMContentLoaded', () => {

    // ── Notify Modal: تفعيل الزر عند إدخال العنوان + النص ──────
    ['notifyTitleInput', 'notifyBodyInput'].forEach(elId => {
        document.getElementById(elId)?.addEventListener('input', () => {
            const t = document.getElementById('notifyTitleInput')?.value.trim() || '';
            const b = document.getElementById('notifyBodyInput')?.value.trim()  || '';
            const sendBtn = document.getElementById('notifySendBtn');
            if (typeof updateButtonState === 'function') {
                updateButtonState(sendBtn, t.length > 0 && b.length > 0);
            } else if (sendBtn) {
                sendBtn.disabled = !(t.length > 0 && b.length > 0);
            }
        });
    });

    // ── Notify Modal: إرسال AJAX ────────────────────────────────
    document.getElementById('notifySendBtn')?.addEventListener('click', async function () {
        const title = document.getElementById('notifyTitleInput')?.value.trim();
        const body  = document.getElementById('notifyBodyInput')?.value.trim();
        if (!title || !body || !currentNotifyTarget.id) return;

        const fd = new FormData();
        fd.append('target_type', currentNotifyTarget.type || 'admin');
        fd.append('target_id',   currentNotifyTarget.id);
        fd.append('title',       title);
        fd.append('message',     body);
        fd.append('csrf_token',  window._csrfToken || '');

        try {
            const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/messaging/notify', {
                method: 'POST',
                body: fd,
            });

            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('notifyModal'))?.hide();
                if (typeof showToast === 'function') showToast('Message sent!', 'success');
            } else {
                if (typeof showToast === 'function') showToast(data.message || 'Error', 'error');
            }
        } catch {
            if (typeof showToast === 'function') showToast('Network error.', 'error');
        }
    });

    // ── Broadcast Modal: تفعيل الزر عند اكتمال الفلاتر ─────────
    const bTitle   = document.getElementById('adminBroadTitle');
    const bBody    = document.getElementById('adminBroadBody');
    const bBtn     = document.getElementById('adminBroadSendBtn');
    const bFilters = document.querySelectorAll('.broad-filter');

    const checkBroad = () => {
        const form       = document.getElementById('broadcastForm');
        const targetType = form?.dataset.targetType || 'admin';

        let filtersReady;
        if (targetType === 'user') {
            filtersReady = document.querySelectorAll("input[name='statuses[]']:checked").length > 0;
        } else {
            const anyPerm = document.querySelectorAll("input[name='perms[]']:checked").length > 0;
            const anyRank = document.querySelectorAll("input[name='ranks[]']:checked").length  > 0;
            filtersReady  = anyPerm && anyRank;
        }

        const ready = (bTitle?.value.trim().length > 0)
                   && (bBody?.value.trim().length  > 0)
                   && filtersReady;

        if (typeof updateButtonState === 'function') {
            updateButtonState(bBtn, ready);
        } else if (bBtn) {
            bBtn.disabled = !ready;
        }
    };

    if (bTitle) bTitle.addEventListener('input', checkBroad);
    if (bBody)  bBody.addEventListener('input', checkBroad);
    bFilters.forEach(el => el.addEventListener('change', checkBroad));

    // ── Broadcast Modal: إرسال AJAX ─────────────────────────────
    document.getElementById('broadcastForm')?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData(this);

        try {
            const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/messaging/broadcast', {
                method: 'POST',
                body: fd,
            });

            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('broadcastModal'))?.hide();
                if (typeof showToast === 'function') showToast(data.message, 'success');
            } else {
                if (typeof showToast === 'function') showToast(data.message || 'Error', 'error');
            }
        } catch {
            if (typeof showToast === 'function') showToast('Network error.', 'error');
        }
    });

});
