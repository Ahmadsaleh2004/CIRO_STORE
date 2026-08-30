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
    // ⚠️ 'use strict' أوّل عبارة في الدالة — قبل أي `let`.
    //
    // كانت مكتوبة **بعد** تعريف notifCountdownInterval أدناه، وهذا
    // يُبطلها تماماً: التوجيه لا يُفعّل الوضع الصارم إلا وهو في
    // «مقدّمة التوجيهات» (Directive Prologue) — أي سلسلةٌ حرفية تسبقها
    // سلاسل حرفية وحدها. فأي عبارة قبلها تحوّلها إلى تعبير نصّي لا
    // أثر له، يُقيَّم ويُهمَل.
    //
    // والفارق ليس نظرياً في ملف كهذا: بلا الوضع الصارم يصير الإسناد
    // إلى متغيّر غير معرَّف إنشاءً لمتغيّر عام صامت — وهو بالضبط العطل
    // الموثَّق في رأس js/admin/admin-notifications.js، حيث نشأت
    // `allNotifs` عامّةً ضمنيةً وانهار الجرس عند أول فشل شبكة.
    'use strict';

    // مؤقّت العدّ التنازلي. **التعريف هنا لا بعد أول استعمال.**
    //
    // كان مكتوباً أسفل الملف، بعد renderSidebar التي تقرأه بنحو ستّين
    // سطراً. لم يكن ينفجر لأن التعريف يُنفَّذ عند تحميل الملف بينما
    // renderSidebar تُستدعى لاحقاً — لكنه بالضبط شكل عطل TDZ الذي وقع
    // في account.js: يكفي أن يُستدعى القارئ مرّة واحدة أثناء تنفيذ جسم
    // الـIIFE ليُرمى ReferenceError.
    //
    // النقل إلى الأعلى يزيل صنف الخطر كله بلا تغيير سلوك.
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

    // ── الشارة: موضع واحد يكتبها ──────────────────────────────
    //
    // ⚠️ `classList` لا `style.display`.
    //
    // الشارة تحمل `d-none` في الترميز (inc/navbar.php)، وهي في
    // Bootstrap ‏`display:none !important` — فلا يزيلها إسناد
    // `style.display = ''` مهما تكرّر. أي أن **عدّاد الإشعارات لم يظهر
    // ولا مرّة**: الرقم يُكتب في العنصر بأمانة، والعنصر مخفيّ.
    //
    // وكان الإسناد مكرّراً في خمسة مواضع بنفس السطرين. التكرار هو ما
    // جعل العطل واحداً في خمسة أماكن بدل واحد — فصار هنا موضعاً واحداً
    // تقرؤه بقيّة الملف.
    function setBadge(unread) {
        if (!cfg.badge) return;

        const n = Math.max(0, Number(unread) || 0);
        cfg.badge.textContent = n > 99 ? '99+' : String(n);
        cfg.badge.classList.toggle('d-none', n === 0);
    }

    // يُضبط عند 401 كي يتوقّف الاستطلاع: جلسة منتهية لا تتعافى بإعادة
    // المحاولة، والاستمرار يعني طلباً مرفوضاً كل ثلاثين ثانية إلى ما
    // لا نهاية — وكلّه صامت.
    let pollTimer = null;
    let sessionExpired = false;

    async function fetchNotifications() {
        if (sessionExpired) return;

        try {
            const res  = await fetch(window.BASE_URL + '/notifications/list');

            // ⚠️ 401 تُعالَج قبل قراءة الجسم.
            //
            // كان السطر `if (!data.success) return;` يبتلع انتهاء
            // الجلسة كما يبتلع أي فشل آخر: الجرس يتجمّد على آخر رقم
            // رآه، والمستخدم يظنّ أنه لا إشعارات لديه بينما هو مخرَج
            // من جلسته أصلاً.
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
        // الاسمان حرفيّان لا متغيّران: كان هنا ترشيح بـisAdmin يختار بين
        // اسمَي الأدمن واسمَي المتجر، وكان يقع دائماً على هذين. ولاحظ أن
        // المتغيّر المحلي dismissFn كان **يُظلّل** الدالة dismissFn المعرَّفة
        // في نطاق الـIIFE — تظليل بلا ضرر هنا، لكنه من نفس عائلة العطل
        // الذي عطّل «إضافة عنوان» في features/account.js.
        // ⚠️ لا `onclick=` هنا.
        //
        // كان السطران يحملان onclick نصّاً داخل innerHTML، وسياسة CSP
        // في public/.htaccess تمنع script-src 'unsafe-inline'. والمنع
        // يشمل المعالجات المضمّنة أياً كان طريق وصولها إلى المستند —
        // فحقنها من جافاسكربت لا يعفيها. فكان كل عنصر إشعار لا يُفتح،
        // وكل زرّ ✕ لا يحذف، بلا أي أثر سوى سطر رفض في الـconsole.
        //
        // البديل: البيانات في سمات data-*، والنقر يفوَّض إلى مستمع
        // واحد على القائمة نفسها (أسفل هذه الدالة) — وهو نفس النمط
        // الذي تتبعه بقيّة الواجهة عبر js/core/inline-actions.js.
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

    // لم يعد شيء يستدعي هذا الاسم: الماركب كان يذكره نصّاً في onclick
    // وقد صار التفويض عبر data-notif-open. والتصدير باقٍ لأنه واجهة
    // عامة قد تنفع في التنقيح من الـconsole — لا لأن أحداً يعتمد عليه.
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

    // كسابقه: لم يعد الماركب يستدعيه نصّاً بعد نقل الحذف إلى
    // data-notif-dismiss. يبقى مصدَّراً للتنقيح لا للاعتماد.
    window.dismissNotif = dismissFn;

    // ── تفويض النقر على قائمة الإشعارات ────────────────────────
    //
    // مستمع واحد على القائمة لا معالج على كل عنصر: القائمة تُعاد
    // بناؤها بالكامل عند كل جلب (innerHTML)، فربط مستمع بكل عنصر
    // يعني إعادة الربط بعد كل رسم — ونسيانها مرّة واحدة يعيد العطل.
    // التفويض من الأب يبقى صالحاً مهما تغيّر ما بداخله.
    //
    // وموضعه هنا — **بعد** تعريف openDetail وdismissFn لا قبلهما —
    // مقصود. الاستدعاء لا يقع إلا عند نقرة المستخدم، فالسبق لا يضرّ
    // وقت التشغيل؛ لكن هذا الملف يحمل في ترويسته تحذيراً من عائلة
    // أعطال TDZ التي عطّلت features/account.js، ومخالفة الشكل الآمن
    // هنا تناقض ذلك التحذير نصّاً.
    //
    // الترتيب داخل المستمع مقصود أيضاً: زرّ الحذف يُفحص أوّلاً، فنقرة
    // عليه تحذف ولا تفتح التفاصيل معه.
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
