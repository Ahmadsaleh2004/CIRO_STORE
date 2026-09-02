// ══════════════════════════════════════════════════════════════
// public/js/core/inline-actions.js — the replacement for inline handlers
// ══════════════════════════════════════════════════════════════
//
// There used to be thirty-three handlers written into the tags themselves:
//
//     <button onclick="openNotifyModal('user', 5, 'Ahmad')">
//
// Those are executable blocks in every sense, so any CSP without 'unsafe-inline' blocks
// them. And they were the last obstacle to enforcing the full policy, once fourteen
// <script> blocks had been moved out into JSON islands.
//
// ── Why delegation rather than a listener per element ───────
//
// Many of these buttons are built in the browser after load (table rows, product cards,
// modal contents). A listener bound at DOMContentLoaded does not see what is created
// afterwards, so it would need rebinding on every render — precisely the class of fault
// that surfaces late and is hard to trace.
//
// One listener on document works for everything created and everything yet to be created.
//
// ── The contract ────────────────────────────────────────────
//
// The element declares its intent with data-action, and its parameters with other data-*
// attributes. The values pass through htmlspecialchars in the view like any attribute, so
// there is no need for addslashes and no need to escape quotes inside JavaScript — an old
// source of bugs: a name containing an apostrophe broke the inline handler.

(function () {
    'use strict';

    /** Calls a global function if it exists, and complains clearly if it does not. */
    function call(name, args) {
        const fn = window[name];
        if (typeof fn !== 'function') {
            console.error('[inline-actions] the function [' + name + '] is not defined.');
            return;
        }
        return fn.apply(null, args || []);
    }

    const handlers = {
        // It stops a clickable table row firing when something inside it is clicked.
        'stop-propagation': function (el, event) {
            event.stopPropagation();
        },

        'logout-admin': function () {
            call('logoutAdmin');
        },

        'logout-user': function () {
            call('logoutUser');
        },

        // A button that disables itself after the first click (this used to be this.removeAttribute).
        'self-enable': function (el) {
            el.removeAttribute('disabled');
        },

        navigate: function (el) {
            const href = el.getAttribute('data-href');
            if (href) window.location.href = href;
        },

        'switch-modal': function (el, event) {
            event.preventDefault();

            const target = el.getAttribute('data-modal-target');
            const extra = el.getAttribute('data-modal-after');

            // The third argument used to be a function written inside the attribute. The
            // only case using it is the privacy modal: it ticks the box and then re-validates
            // the form. It is now a declared intent rather than code in a tag.
            if (extra === 'accept-privacy') {
                call('switchAuthModal', [el, target, function () {
                    const cb = document.getElementById('privacyCheck');
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

        // The nine permissions arrive as one comma-separated attribute, in a fixed order.
        // The alternative is nine separate attributes — longer for no benefit, and their
        // order is the contract either way.
        'perm-modal': function (el) {
            const perms = (el.getAttribute('data-perms') || '').split(',').map(Number);
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

    /** Walks up from the event's target looking for the nearest element declaring data-action. */
    function dispatch(event) {
        // data-confirm is independent of data-action: it used to be
        // onclick="return confirm('…')" on a delete link, and it may coexist with an action.
        const confirmEl = event.target.closest ? event.target.closest('[data-confirm]') : null;
        if (confirmEl && !window.confirm(confirmEl.getAttribute('data-confirm'))) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        const el = event.target.closest ? event.target.closest('[data-action]') : null;
        if (!el) return;

        const action = el.getAttribute('data-action');
        const handler = handlers[action];

        if (!handler) {
            console.error('[inline-actions] unknown action: [' + action + ']');
            return;
        }

        handler(el, event);
    }

    document.addEventListener('click', dispatch);
    document.addEventListener('change', function (event) {
        const el = event.target.closest ? event.target.closest('[data-action]') : null;
        if (!el) return;

        // change belongs to form elements alone; keeping the listeners separate stops a
        // click action firing twice on an element that receives both.
        const handler = handlers[el.getAttribute('data-action')];
        if (handler) handler(el, event);
    });
})();
