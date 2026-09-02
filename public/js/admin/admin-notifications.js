// ══════════════════════════════════════════════════════════════
// public/js/admin/admin-notifications.js — the admin notification bell.
// Responsible only for: #adminNotifBell / #adminNotifBadge / #adminNotifSidebar
// (the sidebar's HTML is static in footer.php — this file wires it to the backend).
// It uses fetchWithCsrfRetry for every POST. The /list request stays a bare fetch because
// it is a GET — there is no token in it and nothing to recover from.
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

    // ⚠️ This had no declaration at all — no let, no const, no var.
    //
    // So the first assignment (`allNotifs = data.notifications` in fetchList) created it
    // as an implicit global, and the code worked as long as that assignment preceded any
    // read. But it does not always precede one: if the /list request fails — a network
    // outage or a server error — the assignment never happens, and renderList or dismiss
    // then read it, throw a ReferenceError, and the notification bell stops entirely.
    //
    // And worse than the fault is that its source is invisible: the variable looks as
    // though it is declared somewhere, because another file (features/notifications.js)
    // carries an identical name — but that one is enclosed in an IIFE there and has
    // nothing to do with this.
    let allNotifs = [];

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
        badge.textContent = n > 99 ? '99+' : String(n);

        // ⚠️ `classList`, not `style.display`.
        //
        // #adminNotifBadge carries `d-none` in admin/inc/navbar.php, which in Bootstrap is
        // `display:none !important` — so assigning `style.display = ''` does not beat it.
        // Which means the admin bell's counter had the correct number written into it and
        // was never seen at all.
        badge.classList.toggle('d-none', n === 0);
    }

    function renderList() {
        if (!listEl) return;
        if (allNotifs.length === 0) {
            listEl.innerHTML = '<li class="notif-empty">No notifications yet</li>';
            return;
        }
        // ⚠️ No `onclick=` here — see its counterpart in js/features/notifications.js.
        //
        // The CSP in public/.htaccess has no script-src 'unsafe-inline', and it blocks an
        // inline handler even when permitted JavaScript injected it. So the admin bell's
        // items did not open and the ✕ buttons did not delete, silently.
        listEl.innerHTML = allNotifs.map(n => {
            const msg = n.message.length > 80 ? n.message.slice(0, 80) + '…' : n.message;
            return `
                <li class="notif-item ${Number(n.is_read) === 1 ? 'read' : 'unread'}"
                    data-id="${n.id}" data-notif-open="${n.id}">
                    <button type="button" class="notif-dismiss-btn"
                            data-notif-dismiss="${n.id}"
                            title="Dismiss">✕</button>
                    <div class="notif-title">${escHtml(n.title)}</div>
                    <div class="notif-msg">${escHtml(msg)}</div>
                    <div class="notif-time">${formatRelativeTime(n.created_at)}</div>
                    ${Number(n.is_read) === 0 ? '<span class="notif-dot"></span>' : ''}
                </li>
            `;
        }).join('');
    }

    // One listener on the list rather than a handler per item: the list is rebuilt
    // entirely on every fetch, and delegation from the parent stays valid afterwards.
    // The delete button is checked first, so its click does not open the details too.
    // listEl may be null: the guard at the top of the file checks bell, badge and sidebar
    // alone, and renderList checks it itself. So it is checked here too.
    if (listEl) {
        listEl.addEventListener('click', function (event) {
            const dismissBtn = event.target.closest('[data-notif-dismiss]');
            if (dismissBtn) {
                event.stopPropagation();
                window.adminNotifDeleteOne(event, dismissBtn.getAttribute('data-notif-dismiss'));
                return;
            }

            const item = event.target.closest('[data-notif-open]');
            if (item) {
                window.adminNotifOpen(item.getAttribute('data-notif-open'));
            }
        });
    }

    // They stop the polling when the session ends: an expired session does not recover
    // through retrying, and carrying on means a refused request every thirty seconds,
    // forever.
    let pollTimer = null;
    let sessionExpired = false;

    function handleExpiredSession() {
        sessionExpired = true;
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        setBadge(0);
        if (typeof showToast === 'function') {
            showToast('Session expired. Please log in again.', 'warning');
        }
    }

    async function fetchList() {
        if (sessionExpired) return;

        try {
            const res = await fetch(baseUrl + '/list');

            // ⚠️ Two checks rather than one, because the server answers in two shapes.
            //
            // Middleware::requireAdmin treats a request as AJAX if it carries
            // X-Requested-With or is a POST. This request is a bare GET — so neither
            // condition holds, and it takes the other branch:
            // `header('Location: ' . URLROOT)`. Which means fetch **follows the redirect**
            // and comes back with the store's home page as HTML.
            //
            // So res.json() threw on the first character of `<!DOCTYPE`, the catch swallowed
            // the error, wrote a line to the console and finished — and it then repeated every
            // thirty seconds forever. The admin bell froze with no visible cause.
            //
            // A 401 is checked too, so the code stays correct should the server ever answer
            // with one — which its counterpart in the store already does.
            const isJson = (res.headers.get('content-type') || '').includes('application/json');
            if (res.status === 401 || res.redirected || !isJson) {
                handleExpiredSession();
                return;
            }

            const data = await res.json();
            if (!data.success) return;
            allNotifs = data.notifications || [];
            setBadge(data.unread_count || 0);
            renderList();
        } catch (e) {
            console.warn('Admin notifications fetch error:', e);
        }
    }

    // ── Opening and marking read when an item is clicked ─────────
    window.adminNotifOpen = async function (id) {
        const n = allNotifs.find(x => sameId(x.id, id));
        if (n && Number(n.is_read) === 0) {
            await markRead(id);
        }
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: escHtml(n ? n.title : ''),
                html: `
                    <div class="u-text-left">
                        <p class="u-prewrap mb-3">${escHtml(n ? n.message : '')}</p>
                        <hr class="u-hr-light">
                        <small class="u-note-grey">
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
        allNotifs = allNotifs.filter(x => !sameId(x.id, id));
        const unread = allNotifs.filter(x => Number(x.is_read) === 0).length;
        setBadge(unread);
        renderList();
        try {
            // ⚠️ `/dismiss`, not `/mark-read`.
            //
            // The ✕ button deletes the notification, and it used to call the "mark as read"
            // endpoint. So the row vanished from the screen because the code removed it from
            // allNotifs locally, and then came back in full on the first refresh or poll —
            // because the server was never asked to delete anything. The fault presented as
            // "the deletion does not stick".
            //
            // AdminNotificationController::dismiss exists, is registered in
            // public/index.php:332, and genuinely deletes — all it lacked was a caller.
            //
            // And it returns an unread_count computed from the database, so that is taken
            // rather than our local estimate: it is the correct source when an unread
            // notification is deleted.
            const data = await fetchWithCsrfRetry(baseUrl + '/dismiss', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'notification_id=' + encodeURIComponent(id)
                    + '&csrf_token=' + encodeURIComponent(window._csrfToken || ''),
            });

            if (data && data.success && data.unread_count !== undefined) {
                setBadge(data.unread_count);
            }
        } catch (e) {
            console.warn('Admin notification dismiss failed:', e);
        }
    };

    async function markRead(id) {
        try {
            const data = await fetchWithCsrfRetry(baseUrl + '/mark-read', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'notification_id=' + encodeURIComponent(id)
                    + '&csrf_token=' + encodeURIComponent(window._csrfToken || ''),
            });
            if (data.success) {
                const n = allNotifs.find(x => sameId(x.id, id));
                if (n) n.is_read = 1;
                setBadge(data.unread_count !== undefined ? data.unread_count : allNotifs.filter(x => Number(x.is_read) === 0).length);
                renderList();
            }
        } catch {}
    }

    // ── The sidebar's buttons ────────────────────────────────────
    if (markAllBtn) {
        markAllBtn.addEventListener('click', async () => {
            try {
                const data = await fetchWithCsrfRetry(baseUrl + '/mark-all-read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=' + encodeURIComponent(window._csrfToken || ''),
                });
                if (data.success) {
                    allNotifs.forEach(x => x.is_read = 1);
                    setBadge(0);
                    renderList();
                }
            } catch {}
        });
    }

    if (deleteAllBtn) {
        deleteAllBtn.addEventListener('click', async () => {
            try {
                const data = await fetchWithCsrfRetry(baseUrl + '/delete-all', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=' + encodeURIComponent(window._csrfToken || ''),
                });
                if (data.success) {
                    allNotifs = [];
                    setBadge(0);
                    renderList();
                }
            } catch {}
        });
    }

    // ── Toggling the sidebar, and closing it from outside ───────
    bell.addEventListener('click', () => {
        toggleSidebar(!sidebar.classList.contains('open'));
        if (!sidebar.classList.contains('open')) fetchList();
    });

    if (closeBtn) closeBtn.addEventListener('click', () => toggleSidebar(false));

    backdropEl.addEventListener('click', () => toggleSidebar(false));

    // ── The initial load and the polling (the same interval as the user notifications: 30 seconds)
    fetchList();
    pollTimer = setInterval(fetchList, 30_000);

});