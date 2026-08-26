/**
 * js/features/notifications.js
 * جرس الإشعارات وسايدبارها — **لصفحات المتجر وحدها**.
 *
 * ⚠️ لا تُضف هنا فرعاً للأدمن. لوحة الأدمن يخدمها ملف مستقل هو
 * js/admin/admin-notifications.js، بجلسة أخرى (admin_session لا
 * PHPSESSID) ونقاط أخرى (/admin/notifications/*) وماركب مختلف.
 *
 * كان هذا الملف يحمل وضعين: يكتشف #adminNotifBell فيتصرّف كملف أدمن،
 * وإلا فكملف متجر. **والفرع الأول لم يُنفَّذ ولا مرة**، لأن ثلاث
 * حقائق مستقلة تمنعه:
 *   1. #adminNotifBell موجود في app/views/admin/inc/navbar.php وحده.
 *   2. هذا الملف مُدرَج في app/views/inc/footer.php وحده — فوتر المتجر.
 *   3. layout الأدمن في Core/Controller يستدعي admin/inc/footer.php،
 *      وهو ملف مختلف تماماً لا يذكر هذا السكربت.
 * أي أن الجرس الذي يبحث عنه لا يكون في الصفحة أبداً حين يعمل الملف.
 */

(function () {
    'use strict';

    const userBell = document.getElementById('notifBell');

    if (!userBell) return;

    const cfg = {
        bell:        userBell,
        badge:       document.getElementById('notifBadge'),
        sidebar:     document.getElementById('notifSidebar'),
        sidebarList: document.getElementById('notifList'),
        closeBtn:    document.getElementById('notifClose'),
        markAllBtn:  document.getElementById('notifMarkAll'),
        deleteAllBtn:document.getElementById('notifDeleteAll'),
        senderName:  'Cairo Store',
    };

    let allNotifs = [];

    async function fetchNotifications() {
        try {
            const res  = await fetch(window.BASE_URL + '/notifications/list');
            const data = await res.json();
            if (!data.success) return;

            allNotifs = data.notifications || [];
            const unread = data.unread_count || 0;

            if (cfg.badge) {
                cfg.badge.textContent = unread > 99 ? '99+' : unread;
                cfg.badge.style.display = unread > 0 ? '' : 'none';
            }

            renderSidebar();
        } catch (e) {
            console.warn('Notifications fetch error:', e);
        }
    }

    function renderSidebar() {
        if (!cfg.sidebarList) return;
        if (allNotifs.length === 0) {
            cfg.sidebarList.innerHTML = '<li class="notif-empty">No notifications</li>';
            return;
        }
        // الاسمان حرفيّان لا متغيّران: كان هنا ترشيح بـisAdmin يختار بين
        // اسمَي الأدمن واسمَي المتجر، وكان يقع دائماً على هذين. ولاحظ أن
        // المتغيّر المحلي dismissFn كان **يُظلّل** الدالة dismissFn المعرَّفة
        // في نطاق الـIIFE — تظليل بلا ضرر هنا، لكنه من نفس عائلة العطل
        // الذي عطّل «إضافة عنوان» في features/account.js.
        cfg.sidebarList.innerHTML = allNotifs.map(n => `
            <li class="notif-item ${n.is_read == 1 ? 'read' : 'unread'}"
                data-id="${n.id}" onclick="openNotifDetail(${n.id})">
                <button class="notif-dismiss-btn"
                        onclick="dismissNotif(event, ${n.id})"
                        title="Dismiss">✕</button>
                <div class="notif-title">${escHtml(n.title)}</div>
                <div class="notif-msg">${escHtml(n.message.length > 80 ? n.message.slice(0,80) + '…' : n.message)}</div>
                <div class="notif-time">${formatRelativeTime(n.created_at)} ${orderTakenCountdownHtml(n)}</div>
                ${n.is_read == 0 ? '<span class="notif-dot"></span>' : ''}
            </li>
        `).join('');

        tickNotifCountdowns();
        if (!notifCountdownInterval) {
            notifCountdownInterval = setInterval(tickNotifCountdowns, 1000);
        }
    }

    function orderTakenCountdownHtml(notif) {
        if (notif.type !== 'order_taken' || !notif.created_at) return '';
        const deadlineMs = new Date(notif.created_at.replace(' ', 'T')).getTime() + (4 * 60 * 60 * 1000);
        return `<span class="notif-countdown" data-deadline="${deadlineMs}">--:--:--</span>`;
    }

    let notifCountdownInterval = null;

    function tickNotifCountdowns() {
        const els = cfg.sidebarList ? cfg.sidebarList.querySelectorAll('.notif-countdown') : [];
        if (!els.length) {
            if (notifCountdownInterval) { clearInterval(notifCountdownInterval); notifCountdownInterval = null; }
            return;
        }
        const now = Date.now();
        els.forEach(function (el) {
            const deadline = parseInt(el.dataset.deadline, 10);
            let remaining = Math.max(0, Math.floor((deadline - now) / 1000));
            if (remaining <= 0) {
                el.textContent = 'Expired';
                return;
            }
            const h = Math.floor(remaining / 3600);
            const m = Math.floor((remaining % 3600) / 60);
            const s = remaining % 60;
            el.textContent = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        });
    }

    const openDetail = function (id) {
        const notif = allNotifs.find(n => n.id == id);
        if (!notif) return;

        if (notif.is_read == 0) markAsRead(id);

        const sentDate = new Date(notif.created_at).toLocaleString('en-US', {
            year:'numeric', month:'short', day:'numeric',
            hour:'2-digit', minute:'2-digit'
        });
        const senderName  = notif.sender_name  || cfg.senderName;
        const senderEmail = notif.sender_email || '';
        const hasProductLink = notif.related_type === 'product' && notif.related_id;

        Swal.fire({
            title: escHtml(notif.title),
            html: `
                <div style="text-align:left;">
                    <p style="white-space:pre-line;margin-bottom:1rem;">${escHtml(notif.message)}</p>
                    <hr style="border-color:#e5e7eb;">
                    <small style="color:#6b7280;">
                        <strong>From:</strong> ${escHtml(senderName)}<br>
                        ${senderEmail ? `<strong>Email:</strong> ${escHtml(senderEmail)}<br>` : ''}
                        <strong>Date:</strong> ${sentDate}
                    </small>
                </div>`,
            showDenyButton: hasProductLink,
            denyButtonText: '🔗 View Product',
            denyButtonColor: '#16a34a',
            confirmButtonText: 'Close',
            confirmButtonColor: '#6366f1',
            width: '500px',
        }).then((result) => {
            if (result.isDenied && hasProductLink) {
                window.location.href = window.BASE_URL + '/product?id=' + notif.related_id;
            }
        });
    };

    // الماركب المُولَّد يستدعي هذا الاسم نصّاً في onclick، فيجب أن يبقى
    // على window. وحُذف معه توأمه openAdminNotifDetail — لم يكن يشير إليه
    // شيء في المشروع كله (لا ماركب ولا admin-notifications.js).
    window.openNotifDetail = openDetail;

    async function markAsRead(id) {
        try {
            const fd = new FormData();
            fd.append('notification_id', id);
            fd.append('csrf_token', window._csrfToken || '');
            const data = await fetchWithCsrfRetry(
                window.BASE_URL + '/notifications/mark-read',
                { method: 'POST', body: fd }
            );
            if (data.csrf_token && typeof updateCsrfToken === 'function') updateCsrfToken(data.csrf_token);
            const n = allNotifs.find(n => n.id == id);
            if (n) n.is_read = 1;
            const unread = data.unread_count !== undefined
                ? data.unread_count
                : allNotifs.filter(n => n.is_read == 0).length;
            if (cfg.badge) {
                cfg.badge.textContent = unread > 99 ? '99+' : unread;
                cfg.badge.style.display = unread > 0 ? '' : 'none';
            }
            renderSidebar();
        } catch (e) {}
    }

    const dismissFn = async function(event, id) {
        if (event && event.stopPropagation) event.stopPropagation();
        const fd = new FormData();
        fd.append('notification_id', id);
        fd.append('csrf_token', window._csrfToken || document.querySelector('input[name="csrf_token"]')?.value || '');
        const data = await fetchWithCsrfRetry(window.BASE_URL + '/notifications/dismiss', { method: 'POST', body: fd });
        if (data.success) {
            allNotifs = allNotifs.filter(n => n.id != id);
            const unread = data.unread_count !== undefined
                ? data.unread_count
                : allNotifs.filter(n => n.is_read == 0).length;
            if (cfg.badge) {
                cfg.badge.textContent = unread > 99 ? '99+' : unread;
                cfg.badge.style.display = unread > 0 ? '' : 'none';
            }
            renderSidebar();
        }
    };

    // كسابقه: الاسم مطلوب على window لأن الماركب يستدعيه نصّاً. وحُذف
    // توأمه dismissAdminNotif — كان يشير إليه سطر واحد في هذا الملف
    // نفسه، وهو السطر المحذوف في renderSidebar.
    window.dismissNotif = dismissFn;

    if (cfg.deleteAllBtn) {
        cfg.deleteAllBtn.addEventListener('click', async () => {
            const fd = new FormData();
            fd.append('csrf_token', window._csrfToken || document.querySelector('input[name="csrf_token"]')?.value || '');
            const data = await fetchWithCsrfRetry(window.BASE_URL + '/notifications/delete-all', { method: 'POST', body: fd });
            if (data.success) {
                allNotifs = [];
                if (cfg.badge) cfg.badge.style.display = 'none';
                renderSidebar();
            }
        });
    }

    if (cfg.markAllBtn) {
        cfg.markAllBtn.addEventListener('click', async () => {
            const fd = new FormData();
            fd.append('csrf_token', window._csrfToken || '');
            const data = await fetchWithCsrfRetry(
                window.BASE_URL + '/notifications/mark-all-read',
                { method: 'POST', body: fd }
            );
            if (data.csrf_token && typeof updateCsrfToken === 'function') updateCsrfToken(data.csrf_token);
            allNotifs.forEach(n => n.is_read = 1);
            if (cfg.badge) cfg.badge.style.display = 'none';
            renderSidebar();
        });
    }

    // ── Backdrop element ────────────────────────────────────
    let backdropEl = document.querySelector('.notif-backdrop');
    if (!backdropEl) {
        backdropEl = document.createElement('div');
        backdropEl.className = 'notif-backdrop';
        document.body.appendChild(backdropEl);
    }

    function toggleSidebar(open) {
        cfg.sidebar.classList.toggle('open', open);
        backdropEl.classList.toggle('show', open);
    }

    if (cfg.bell && cfg.sidebar) {
        cfg.bell.addEventListener('click', () => {
            toggleSidebar(!cfg.sidebar.classList.contains('open'));
        });
    }
    if (cfg.closeBtn && cfg.sidebar) {
        cfg.closeBtn.addEventListener('click', () => toggleSidebar(false));
    }
    backdropEl.addEventListener('click', () => toggleSidebar(false));

    fetchNotifications();
    setInterval(fetchNotifications, 30_000);

})();
