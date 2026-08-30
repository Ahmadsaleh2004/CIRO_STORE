// ══════════════════════════════════════════════════════════════
// public/js/admin/admin-notifications.js — جرس إشعارات الأدمن
// مسؤول فقط عن: #adminNotifBell / #adminNotifBadge / #adminNotifSidebar
// (الـ sidebar HTML ثابت بـ footer.php — هذا الملف يربطه بالباك اند)
// يستعمل fetchWithCsrfRetry لكل POST. طلب /list يبقى fetch عارياً
// لأنه GET — لا توكن فيه ولا شيء لتتعافى منه.
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

    // ⚠️ كانت هذه بلا تعريف إطلاقاً — لا let ولا const ولا var.
    //
    // فكان أول إسناد (`allNotifs = data.notifications` في fetchList)
    // يُنشئها متغيّراً عاماً ضمنياً، والكود يعمل ما دام ذلك الإسناد
    // يسبق أي قراءة. لكنه لا يسبقها دائماً: إن فشل طلب /list — انقطاع
    // شبكة أو خطأ خادم — لا يقع الإسناد، ثم تقرأها renderList أو
    // dismiss فترمي ReferenceError ويتوقّف جرس الإشعارات كلّياً.
    //
    // وأسوأ من العطل أن مصدره غير ظاهر: المتغيّر يبدو معرَّفاً في مكان
    // ما لأن ملفاً آخر (features/notifications.js) يحمل اسماً مطابقاً —
    // لكنه محبوس في IIFE هناك ولا علاقة له بهذا.
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

        // ⚠️ `classList` لا `style.display`.
        //
        // #adminNotifBadge يحمل `d-none` في admin/inc/navbar.php، وهي
        // في Bootstrap ‏`display:none !important` — فإسناد
        // `style.display = ''` لا يهزمها. أي أن عدّاد جرس الأدمن كان
        // يُكتب فيه الرقم الصحيح ولا يُرى إطلاقاً.
        badge.classList.toggle('d-none', n === 0);
    }

    function renderList() {
        if (!listEl) return;
        if (allNotifs.length === 0) {
            listEl.innerHTML = '<li class="notif-empty">لا يوجد إشعارات بعد</li>';
            return;
        }
        // ⚠️ لا `onclick=` هنا — راجع نظيره في js/features/notifications.js.
        //
        // CSP في public/.htaccess بلا script-src 'unsafe-inline'، وهو
        // يمنع المعالج المضمّن حتى لو حقنه جافاسكربت مسموح به. فكانت
        // عناصر جرس الأدمن لا تُفتح وأزرار ✕ لا تحذف، صامتةً.
        listEl.innerHTML = allNotifs.map(n => {
            const msg = n.message.length > 80 ? n.message.slice(0, 80) + '…' : n.message;
            return `
                <li class="notif-item ${n.is_read == 1 ? 'read' : 'unread'}"
                    data-id="${n.id}" data-notif-open="${n.id}">
                    <button type="button" class="notif-dismiss-btn"
                            data-notif-dismiss="${n.id}"
                            title="Dismiss">✕</button>
                    <div class="notif-title">${escHtml(n.title)}</div>
                    <div class="notif-msg">${escHtml(msg)}</div>
                    <div class="notif-time">${formatRelativeTime(n.created_at)}</div>
                    ${n.is_read == 0 ? '<span class="notif-dot"></span>' : ''}
                </li>
            `;
        }).join('');
    }

    // مستمع واحد على القائمة بدل معالج على كل عنصر: القائمة تُعاد
    // بناؤها كاملةً عند كل جلب، والتفويض من الأب يبقى صالحاً بعدها.
    // وزرّ الحذف يُفحص أوّلاً كي لا تفتح نقرتُه التفاصيل معه.
    // listEl قد يكون null: الحارس أعلى الملف يفحص bell وbadge وsidebar
    // وحدها، وrenderList تفحصه بنفسها. فنفحصه هنا كذلك.
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

    // يوقفان الاستطلاع عند انتهاء الجلسة: جلسة منتهية لا تتعافى
    // بإعادة المحاولة، والاستمرار طلبٌ مرفوض كل ثلاثين ثانية بلا نهاية.
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

            // ⚠️ فحصان لا واحد، لأن الخادم يردّ بشكلين مختلفين.
            //
            // Middleware::requireAdmin تعتبر الطلب AJAX إن حمل
            // X-Requested-With أو كان POST. وهذا الطلب GET عارٍ — فلا
            // يتحقّق الشرطان، ويأخذ الفرع الآخر:
            // `header('Location: ' . URLROOT)`. أي أن fetch **تتبع
            // التحويل** وتعود بصفحة المتجر الرئيسية HTML.
            //
            // فكان res.json() يرمي على أول محرف من `<!DOCTYPE`، ويبتلع
            // الخطأَ catch فيكتب سطراً في console وينتهي — ثم يتكرّر كل
            // ثلاثين ثانية إلى الأبد. جرس الأدمن يتجمّد بلا سبب ظاهر.
            //
            // 401 مفحوصة كذلك كي يبقى الكود صحيحاً إن ردّ الخادم بها
            // يوماً — وهو ما يفعله نظيره في المتجر أصلاً.
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
        allNotifs = allNotifs.filter(x => x.id != id);
        const unread = allNotifs.filter(x => x.is_read == 0).length;
        setBadge(unread);
        renderList();
        try {
            // ⚠️ `/dismiss` لا `/mark-read`.
            //
            // زرّ ✕ يحذف الإشعار، وكان يستدعي نقطة «تعليم كمقروء».
            // فيختفي السطر من الشاشة لأن الكود يزيله من allNotifs محلياً،
            // ثم يعود كاملاً عند أول تحديث أو استطلاع — لأن الخادم لم
            // يُطلَب منه حذف شيء قط. وبدا العطل «الحذف لا يثبت».
            //
            // AdminNotificationController::dismiss موجودة ومسجَّلة في
            // public/index.php:332 وتحذف فعلاً — لم يكن ينقصها إلا
            // من يناديها.
            //
            // وتُرجع unread_count محسوباً من القاعدة، فنأخذه بدل تقديرنا
            // المحلي: هو المصدر الصحيح إن حُذف إشعار غير مقروء.
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
                const n = allNotifs.find(x => x.id == id);
                if (n) n.is_read = 1;
                setBadge(data.unread_count !== undefined ? data.unread_count : allNotifs.filter(x => x.is_read == 0).length);
                renderList();
            }
        } catch {}
    }

    // ── أزرار الـ sidebar ────────────────────────────────────────
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

    // ── toggle الـ sidebar + إغلاق خارجي ─────────────────────────
    bell.addEventListener('click', () => {
        toggleSidebar(!sidebar.classList.contains('open'));
        if (!sidebar.classList.contains('open')) fetchList();
    });

    if (closeBtn) closeBtn.addEventListener('click', () => toggleSidebar(false));

    backdropEl.addEventListener('click', () => toggleSidebar(false));

    // ── التحميل الأولي + Polling (نفس فاصل الـ user notifications: 30 ثانية)
    fetchList();
    pollTimer = setInterval(fetchList, 30_000);

});