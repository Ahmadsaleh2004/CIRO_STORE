// ══════════════════════════════════════════════════════════════
// public/js/admin/admin-notifications.js — جرس إشعارات الأدمن
// مسؤول فقط عن: #adminNotifBell / #adminNotifBadge / #adminNotifSidebar
// (الـ sidebar HTML ثابت بـ footer.php — هذا الملف يربطه بالباك اند)
// ⚠️ استخدم fetch() مباشرة + window.URLROOT — لا تستخدم
//    fetchWithCsrfRetry() لأنها تعتمد على window.BASE_URL غير موجود
//    بصفحات الأدمن.
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {

    const baseUrl = window.URLROOT + '/admin/notifications';

    const bell      = document.getElementById('adminNotifBell');
    const badge     = document.getElementById('adminNotifBadge');
    const sidebar   = document.getElementById('adminNotifSidebar');
    const listEl    = document.getElementById('adminNotifList');
    const closeBtn  = document.getElementById('adminNotifClose');
    const markAllBtn = document.getElementById('adminNotifMarkAll');
    const deleteAllBtn = document.getElementById('adminNotifDeleteAll');

    if (!bell || !badge || !sidebar) return;

    // ── Backdrop element (shared) ──
    let backdropEl = document.querySelector('.notif-backdrop');
    if (!backdropEl) {
        backdropEl = document.createElement('div');
        backdropEl.className = 'notif-backdrop';
        document.body.appendChild(backdropEl);
    }

    function toggleSidebar(open) {
        sidebar.classList.toggle('open', open);
        backdropEl.classList.toggle('show', open);
    }

    function setBadge(unread) {
        const n = Math.max(0, unread || 0);
        badge.textContent = n > 99 ? '99+' : n;
        badge.style.display = n > 0 ? '' : 'none';
    }

    function renderList() {
        if (!listEl) return;
        if (allNotifs.length === 0) {
            listEl.innerHTML = '<li class="notif-empty">لا يوجد إشعارات بعد</li>';
            return;
        }
        listEl.innerHTML = allNotifs.map(n => {
            const msg = n.message.length > 80 ? n.message.slice(0, 80) + '…' : n.message;
            return `
                <li class="notif-item ${n.is_read == 1 ? 'read' : 'unread'}"
                    data-id="${n.id}" onclick="window.adminNotifOpen(${n.id})">
                    <button type="button" class="notif-dismiss-btn"
                            onclick="window.adminNotifDeleteOne(event, ${n.id})"
                            title="Dismiss">✕</button>
                    <div class="notif-title">${escHtml(n.title)}</div>
                    <div class="notif-msg">${escHtml(msg)}</div>
                    <div class="notif-time">${formatRelativeTime(n.created_at)}</div>
                    ${n.is_read == 0 ? '<span class="notif-dot"></span>' : ''}
                </li>
            `;
        }).join('');
    }

    async function fetchList() {
        try {
            const res  = await fetch(baseUrl + '/list');
            const data = await res.json();
            if (!data.success) return;
            allNotifs = data.notifications || [];
            setBadge(data.unread_count || 0);
            renderList();
        } catch (e) {
            console.warn('Admin notifications fetch error:', e);
        }
    }

    // ── فتح/تحديد كإشعار مقروء عند الضغط على عنصر ─────────────────
    window.adminNotifOpen = async function (id) {
        const n = allNotifs.find(x => x.id == id);
        if (n && n.is_read == 0) {
            await markRead(id);
        }
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: escHtml(n ? n.title : ''),
                html: `
                    <div style="text-align:left;">
                        <p style="white-space:pre-line;margin-bottom:1rem;">${escHtml(n ? n.message : '')}</p>
                        <hr style="border-color:#e5e7eb;">
                        <small style="color:#6b7280;">
                            ${n && n.type ? `<strong>Type:</strong> ${escHtml(n.type)}<br>` : ''}
                            <strong>Date:</strong> ${new Date(n.created_at).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                        </small>
                    </div>`,
                confirmButtonText: 'Close',
                confirmButtonColor: '#6366f1',
                width: '480px',
            });
        }
    };

    window.adminNotifDeleteOne = async function (event, id) {
        if (event && event.stopPropagation) event.stopPropagation();
        allNotifs = allNotifs.filter(x => x.id != id);
        const unread = allNotifs.filter(x => x.is_read == 0).length;
        setBadge(unread);
        renderList();
        try {
            await fetch(baseUrl + '/mark-read', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'notification_id=' + encodeURIComponent(id)
                    + '&csrf_token=' + encodeURIComponent(window._csrfToken || ''),
            });
        } catch (e) {}
    };

    async function markRead(id) {
        try {
            const res  = await fetch(baseUrl + '/mark-read', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'notification_id=' + encodeURIComponent(id)
                    + '&csrf_token=' + encodeURIComponent(window._csrfToken || ''),
            });
            const data = await res.json();
            if (data.success) {
                const n = allNotifs.find(x => x.id == id);
                if (n) n.is_read = 1;
                setBadge(data.unread_count !== undefined ? data.unread_count : allNotifs.filter(x => x.is_read == 0).length);
                renderList();
            }
        } catch (e) {}
    }

    // ── أزرار الـ sidebar ────────────────────────────────────────
    if (markAllBtn) {
        markAllBtn.addEventListener('click', async () => {
            try {
                const res  = await fetch(baseUrl + '/mark-all-read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=' + encodeURIComponent(window._csrfToken || ''),
                });
                const data = await res.json();
                if (data.success) {
                    allNotifs.forEach(x => x.is_read = 1);
                    setBadge(0);
                    renderList();
                }
            } catch (e) {}
        });
    }

    if (deleteAllBtn) {
        deleteAllBtn.addEventListener('click', async () => {
            try {
                const res  = await fetch(baseUrl + '/delete-all', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=' + encodeURIComponent(window._csrfToken || ''),
                });
                const data = await res.json();
                if (data.success) {
                    allNotifs = [];
                    setBadge(0);
                    renderList();
                }
            } catch (e) {}
        });
    }

    // ── toggle الـ sidebar + إغلاق خارجي ─────────────────────────
    bell.addEventListener('click', () => {
        toggleSidebar(!sidebar.classList.contains('open'));
        if (!sidebar.classList.contains('open')) fetchList();
    });

    if (closeBtn) closeBtn.addEventListener('click', () => toggleSidebar(false));

    backdropEl.addEventListener('click', () => toggleSidebar(false));

    // ── التحميل الأولي + Polling (نفس فاصل الـ user notifications: 30 ثانية)
    fetchList();
    setInterval(fetchList, 30_000);

});