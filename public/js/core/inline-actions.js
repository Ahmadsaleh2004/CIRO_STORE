// ══════════════════════════════════════════════════════════════
// public/js/core/inline-actions.js — بديل المعالجات المضمّنة
// ══════════════════════════════════════════════════════════════
//
// كانت ثلاثة وثلاثون معالجاً مكتوبة في الوسوم نفسها:
//
//     <button onclick="openNotifyModal('user', 5, 'أحمد')">
//
// وهي كتل تنفيذ بكل معنى الكلمة، فتحجبها أي سياسة CSP لا تحمل
// 'unsafe-inline'. وكانت العائق الأخير أمام تفعيل السياسة الكاملة بعد
// إخراج أربع عشرة كتلة <script> إلى جزر JSON.
//
// ── لماذا التفويض لا مستمعٌ لكل عنصر ────────────────────────
//
// كثير من هذه الأزرار يُبنى في المتصفح بعد التحميل (صفوف الجداول،
// بطاقات المنتجات، محتوى المودالات). مستمع يُربط عند DOMContentLoaded
// لا يرى ما وُلد بعده، فكان سيحتاج إعادة ربط عند كل تصيير — وهو
// بالضبط صنف العطل الذي يظهر متأخّراً ويصعب تتبّعه.
//
// مستمع واحد على document يعمل على كل ما وُلد وما سيولد.
//
// ── العقد ───────────────────────────────────────────────────
//
// العنصر يعلن نيّته بـdata-action، ومعاملاته بـdata-* أخرى. والقيم
// تمرّ عبر htmlspecialchars في الـview كأي سمة، فلا حاجة لـaddslashes
// ولا لتهريب علامات الاقتباس داخل JS — وهو مصدر أخطاء قديم: اسم فيه
// فاصلة عليا كان يكسر المعالج المضمّن.

(function () {
    'use strict';

    /** يستدعي دالة عامة إن وُجدت، ويشتكي بوضوح إن غابت. */
    function call(name, args) {
        var fn = window[name];
        if (typeof fn !== 'function') {
            console.error('[inline-actions] الدالة [' + name + '] غير معرَّفة.');
            return;
        }
        return fn.apply(null, args || []);
    }

    var handlers = {
        // يمنع تفعيل صفّ الجدول القابل للنقر حين يُنقر ما بداخله.
        'stop-propagation': function (el, event) {
            event.stopPropagation();
        },

        'logout-admin': function () {
            call('logoutAdmin');
        },

        'logout-user': function () {
            call('logoutUser');
        },

        // زرّ يُفعّل نفسه بعد أول نقرة (كان this.removeAttribute).
        'self-enable': function (el) {
            el.removeAttribute('disabled');
        },

        navigate: function (el) {
            var href = el.getAttribute('data-href');
            if (href) window.location.href = href;
        },

        'switch-modal': function (el, event) {
            event.preventDefault();

            var target = el.getAttribute('data-modal-target');
            var extra = el.getAttribute('data-modal-after');

            // الوسيط الثالث كان دالةً مكتوبة داخل السمة. الحالة الوحيدة
            // التي تستعمله هي مودال الخصوصية: يؤشّر المربّع ثم يعيد
            // فحص صحّة النموذج. صارت نيّةً معلَنة لا كوداً في وسم.
            if (extra === 'accept-privacy') {
                call('switchAuthModal', [el, target, function () {
                    var cb = document.getElementById('privacyCheck');
                    if (cb) cb.checked = true;
                    if (typeof window.checkSignupFormValidity === 'function') {
                        window.checkSignupFormValidity();
                    }
                }]);
                return;
            }

            call('switchAuthModal', [el, target]);
        },

        'toggle-password': function (el) {
            call('togglePassword', [
                el.getAttribute('data-input'),
                el.getAttribute('data-eye'),
            ]);
        },

        'toggle-both-passwords': function (el) {
            call('toggleBothPasswords', [el.getAttribute('data-eye')]);
        },

        'notify-modal': function (el) {
            call('openNotifyModal', [
                el.getAttribute('data-notify-type'),
                Number(el.getAttribute('data-notify-id')),
                el.getAttribute('data-notify-name'),
            ]);
        },

        // الصلاحيات التسع تصل كسمة واحدة مفصولة بفواصل، بترتيب ثابت.
        // بديلها تسع سمات منفصلة — أطول بلا فائدة، وترتيبها هو العقد
        // نفسه في الحالتين.
        'perm-modal': function (el) {
            var perms = (el.getAttribute('data-perms') || '').split(',').map(Number);
            call('openPermModal', [
                Number(el.getAttribute('data-admin-id')),
                el.getAttribute('data-admin-name'),
                el.getAttribute('data-admin-role'),
            ].concat(perms));
        },

        'order-details': function (el) {
            call('goToOrderDetails', [Number(el.getAttribute('data-order-id'))]);
        },

        'take-order': function () {
            call('handleTakeIt');
        },

        'release-order': function () {
            call('handleReleaseOrder');
        },

        'submit-report': function () {
            call('submitReport');
        },

        'update-delivery': function (el) {
            call('updateDelivery', [el.getAttribute('data-delivery')]);
        },

        'change-qty': function (el) {
            call('changeQtyDB', [
                el.getAttribute('data-product-id'),
                Number(el.getAttribute('data-delta')),
            ]);
        },

        'add-to-cart': function (el) {
            call('addToCartDB', [
                Number(el.getAttribute('data-product-id')),
                Number(el.getAttribute('data-variant-id')),
                Number(el.getAttribute('data-price')),
                Number(el.getAttribute('data-stock')),
            ]);
        },

        'filter-status': function (el) {
            call('filterStatus', [el.value]);
        },
    };

    /** يصعد من هدف الحدث بحثاً عن أقرب عنصر يعلن data-action. */
    function dispatch(event) {
        // data-confirm مستقلّ عن data-action: كان
        // onclick="return confirm('…')" على رابط حذف، وقد يجتمع مع فعل.
        var confirmEl = event.target.closest ? event.target.closest('[data-confirm]') : null;
        if (confirmEl && !window.confirm(confirmEl.getAttribute('data-confirm'))) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        var el = event.target.closest ? event.target.closest('[data-action]') : null;
        if (!el) return;

        var action = el.getAttribute('data-action');
        var handler = handlers[action];

        if (!handler) {
            console.error('[inline-actions] فعل غير معروف: [' + action + ']');
            return;
        }

        handler(el, event);
    }

    document.addEventListener('click', dispatch);
    document.addEventListener('change', function (event) {
        var el = event.target.closest ? event.target.closest('[data-action]') : null;
        if (!el) return;

        // change يخصّ عناصر النماذج وحدها؛ فصل المستمعين يمنع أن يُطلق
        // فعلُ نقرٍ مرّتين على عنصر يستقبل الاثنين.
        var handler = handlers[el.getAttribute('data-action')];
        if (handler) handler(el, event);
    });
})();
