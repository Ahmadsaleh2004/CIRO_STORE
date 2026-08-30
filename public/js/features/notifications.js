/**
 * js/features/notifications.js
 * The notification bell and its sidebar — **for the store pages alone**.
 *
 * ⚠️ Do not add an admin branch here. The admin panel is served by a file of its own,
 * js/admin/admin-notifications.js, with a different session (admin_session rather than
 * PHPSESSID), different endpoints (/admin/notifications/*) and different markup.
 *
 * This file used to carry two modes: it detected #adminNotifBell and behaved as the
 * admin file, and otherwise as the store one. **And the first branch never once ran**,
 * because three independent facts prevent it:
 *   1. #adminNotifBell exists in app/views/admin/inc/navbar.php alone.
 *   2. this file is included from app/views/inc/footer.php alone — the store's footer.
 *   3. the admin layout in Core/Controller includes admin/inc/footer.php, an entirely
 *      different file that never mentions this script.
 * Which is to say the bell it looks for is never on the page while this file runs.
 */

(function () {
    // ⚠️ 'use strict' is the first statement in the function — before any `let`.
    //
    // It used to be written **after** the notifCountdownInterval declaration below, which
    // nullifies it entirely: the directive only enables strict mode while it is in the
    // "directive prologue" — that is, a string literal preceded by string literals alone.
    // So any statement before it turns it into an inert string expression, evaluated and
    // discarded.
    //
    // And the difference is not theoretical in a file like this: without strict mode,
    // assigning to an undeclared variable silently creates a global — precisely the fault
    // documented at the top of js/admin/admin-notifications.js, where `allNotifs` came into
    // being as an implicit global and the bell collapsed on the first network failure.
    'use strict';

    // The countdown timer. **Declared here rather than after its first use.**
    //
    // It used to be written at the bottom of the file, some sixty lines after
    // renderSidebar, which reads it. It did not blow up because the declaration executes
    // when the file loads while renderSidebar is called later — but it is exactly the shape
    // of the TDZ fault that hit account.js: one call to the reader during the IIFE's body
    // is enough to throw a ReferenceError.
    //
    // Moving it to the top removes the entire class of risk with no behavioural change.
    let notifCountdownInterval = null;

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

    // ── The badge: one place writes it ──────────────────────────
    //
    // ⚠️ `classList`, not `style.display`.
    //
    // The badge carries `d-none` in the markup (inc/navbar.php), which in Bootstrap is
    // `display:none !important` — so assigning `style.display = ''` does not remove it,
    // however often it is repeated. Which means **the notification counter never once
    // appeared**: the number was faithfully written into the element, and the element was
    // hidden.
    //
    // And the assignment was repeated across five places with the same two lines. That
    // repetition is what made one fault appear in five places instead of one — so it is now
    // a single place the rest of the file reads.
    function setBadge(unread) {
        if (!cfg.badge) return;

        const n = Math.max(0, Number(unread) || 0);
        cfg.badge.textContent = n > 99 ? '99+' : String(n);
        cfg.badge.classList.toggle('d-none', n === 0);
    }

    // Set on a 401 so the polling stops: an expired session does not recover through
    // retrying, and carrying on means a refused request every thirty seconds, forever — all
    // of it silent.
    let pollTimer = null;
    let sessionExpired = false;

    async function fetchNotifications() {
        if (sessionExpired) return;

        try {
            const res  = await fetch(window.BASE_URL + '/notifications/list');

            // ⚠️ A 401 is handled before the body is read.
            //
            // The line `if (!data.success) return;` swallowed an expired session just as it
            // swallowed any other failure: the bell froze on the last number it saw, and the
            // user believed they had no notifications while in fact they had been signed out.
            if (res.status === 401) {
                sessionExpired = true;
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                setBadge(0);
                if (typeof showToast === 'function') {
                    showToast('Your session has expired. Please log in again.', 'warning');
                }
                return;
            }

            const data = await res.json();
            if (!data.success) return;

            allNotifs = data.notifications || [];
            setBadge(data.unread_count || 0);

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
        // The two names are literals rather than variables: there used to be an isAdmin
        // selection here choosing between the admin names and the store names, and it always
        // landed on these two. Note also that the local dismissFn **shadowed** the dismissFn
        // declared in the IIFE's scope — harmless shadowing here, but from the same family
        // of fault that disabled "add address" in features/account.js.
        // ⚠️ No `onclick=` here.
        //
        // The two lines used to carry an onclick as text inside innerHTML, and the CSP in
        // public/.htaccess forbids script-src 'unsafe-inline'. That prohibition covers inline
        // handlers however they reach the document — injecting them from JavaScript does not
        // exempt them. So no notification item opened and no ✕ button deleted, with no trace
        // beyond a refusal line in the console.
        //
        // The alternative: the data lives in data-* attributes, and the click is delegated
        // to a single listener on the list itself (below this function) — the same pattern
        // the rest of the interface follows through js/core/inline-actions.js.
        cfg.sidebarList.innerHTML = allNotifs.map(n => `
            <li class="notif-item ${n.is_read == 1 ? 'read' : 'unread'}"
                data-id="${n.id}" data-notif-open="${n.id}">
                <button class="notif-dismiss-btn" type="button"
                        data-notif-dismiss="${n.id}"
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
                <div class="u-text-left">
                    <p class="u-prewrap mb-3">${escHtml(notif.message)}</p>
                    <hr class="u-hr-light">
                    <small class="u-note-grey">
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

    // Nothing calls this name any more: the markup used to mention it as text in an
    // onclick, and the delegation now happens through data-notif-open. The export remains
    // because it is a public surface that may be useful for debugging from the console — not
    // because anything depends on it.
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
            setBadge(unread);
            renderSidebar();
        } catch {}
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
            setBadge(unread);
            renderSidebar();
        }
    };

    // As above: the markup no longer calls it as text, since the deletion moved to
    // data-notif-dismiss. It stays exported for debugging rather than for depending on.
    window.dismissNotif = dismissFn;

    // ── Delegating clicks on the notification list ──────────────
    //
    // One listener on the list rather than a handler per item: the list is rebuilt
    // entirely on every fetch (innerHTML), so binding a listener to each item means
    // rebinding after every render — and forgetting once reproduces the fault. Delegation
    // from the parent stays valid however much its contents change.
    //
    // And its position here — **after** openDetail and dismissFn are declared rather than
    // before them — is deliberate. The call only happens on a user's click, so ordering does
    // no harm at runtime; but this file's header carries a warning about the family of TDZ
    // faults that disabled features/account.js, and violating the safe shape here would
    // contradict that warning in so many words.
    //
    // The order inside the listener is deliberate too: the delete button is checked first,
    // so a click on it deletes without opening the details as well.
    if (cfg.sidebarList) {
        cfg.sidebarList.addEventListener('click', function (event) {
            const dismissBtn = event.target.closest('[data-notif-dismiss]');
            if (dismissBtn) {
                event.stopPropagation();
                dismissFn(event, dismissBtn.getAttribute('data-notif-dismiss'));
                return;
            }

            const item = event.target.closest('[data-notif-open]');
            if (item) {
                openDetail(item.getAttribute('data-notif-open'));
            }
        });
    }

    if (cfg.deleteAllBtn) {
        cfg.deleteAllBtn.addEventListener('click', async () => {
            const fd = new FormData();
            fd.append('csrf_token', window._csrfToken || document.querySelector('input[name="csrf_token"]')?.value || '');
            const data = await fetchWithCsrfRetry(window.BASE_URL + '/notifications/delete-all', { method: 'POST', body: fd });
            if (data.success) {
                allNotifs = [];
                setBadge(0);
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
            setBadge(0);
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
    pollTimer = setInterval(fetchNotifications, 30_000);

})();
