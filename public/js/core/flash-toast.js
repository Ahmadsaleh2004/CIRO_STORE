// ══════════════════════════════════════════════════════════════
// js/core/flash-toast.js — showing transient messages as toasts
// ══════════════════════════════════════════════════════════════
//
// It picks up the elements app/views/shared/flash-toast.php prints and shows each of
// them through showToast, from js/core/ui.js.
//
// This logic used to be written inside the views: every page needing a transient message
// wrote a <script> listening for DOMContentLoaded and calling showToast itself.

document.addEventListener('DOMContentLoaded', function () {
    const nodes = document.querySelectorAll('.js-flash-toast[data-toast-message]');
    if (!nodes.length) return;

    if (typeof window.showToast !== 'function') {
        // The guard existed in the inline copies (typeof showToast === 'function') and was
        // kept: ui.js may not be loaded on a standalone page.
        console.warn('flash-toast: showToast is not defined — no messages will be shown');
        return;
    }

    nodes.forEach(el => {
        const msg  = el.dataset.toastMessage;
        const type = el.dataset.toastType || 'success';
        if (msg) window.showToast(msg, type);
    });
});
