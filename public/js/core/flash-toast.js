// ══════════════════════════════════════════════════════════════
// js/core/flash-toast.js — عرض الرسائل العابرة كـtoast
// ══════════════════════════════════════════════════════════════
//
// يلتقط العناصر التي يطبعها app/views/shared/flash-toast.php ويعرض
// كلاً منها عبر showToast من js/core/ui.js.
//
// كان هذا المنطق مكتوباً داخل الـviews: كل صفحة تحتاج رسالة عابرة
// تكتب <script> يستمع لـDOMContentLoaded ويستدعي showToast بنفسها.

document.addEventListener('DOMContentLoaded', function () {
    const nodes = document.querySelectorAll('.js-flash-toast[data-toast-message]');
    if (!nodes.length) return;

    if (typeof window.showToast !== 'function') {
        // الحارس كان موجوداً في النسخ المضمّنة (typeof showToast === 'function')
        // وأُبقي: ui.js قد لا يكون محمَّلاً على صفحة مستقلة.
        console.warn('flash-toast: showToast غير معرَّفة — لن تُعرض الرسائل');
        return;
    }

    nodes.forEach(el => {
        const msg  = el.dataset.toastMessage;
        const type = el.dataset.toastType || 'success';
        if (msg) window.showToast(msg, type);
    });
});
