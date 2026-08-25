// ══════════════════════════════════════════════════════════════
// js/core/modal-input-colors.js — فرض ألوان حقول النوافذ المنبثقة
// ══════════════════════════════════════════════════════════════
//
// نُقل من كتلة <script> مضمّنة (29 سطراً) في app/views/inc/footer.php.
// نقل خالص: الكتلة لم تكن تحقن أي قيمة PHP.
//
// ⚠️ هذا الملف حلٌّ التفافي لمشكلة CSS، لا منطق واجهة:
// حقول تسجيل الدخول والتسجيل و«نسيت كلمة المرور» داخل نوافذ Bootstrap
// كانت ترث ألوان المتصفح لا ألوان الثيم، فيُفرَض اللون هنا بـ
// setProperty(..., 'important') على كل حقل عند فتح النافذة وعند تبديل
// الثيم وعند التركيز.
//
// العلاج الجذري قاعدة CSS على `.modal input` تحترم الوضع الليلي — لكن
// ذلك تغيير في طبقة الأنماط بنتيجة مرئية، خارج نطاق نقل الملفات.
// نُقل كما هو ووُثّق ليُقرَّر لاحقاً.

(function fixInputFocus() {
    'use strict';

    const MODAL_INPUTS =
        '#loginModal input:not([type="checkbox"]), #forgotModal input, #registerModal input:not([type="checkbox"]),'
        + ' #registerModal select, #registerModal textarea';

    function themeColors() {
        const isDark = document.body.classList.contains('dark-mode');
        return {
            bg: isDark ? '#21262d' : '#ffffff',
            fg: isDark ? '#e6edf3' : '#1a1a2e',
        };
    }

    function applyInputColors() {
        const { bg, fg } = themeColors();
        document.querySelectorAll(MODAL_INPUTS).forEach(el => {
            el.style.setProperty('background-color', bg, 'important');
            el.style.setProperty('color', fg, 'important');
        });
    }

    document.addEventListener('shown.bs.modal', applyInputColors);

    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => setTimeout(applyInputColors, 50));
    }

    document.addEventListener('focusin', function (e) {
        if (e.target.type === 'checkbox') return;
        const modal = e.target.closest('#loginModal, #forgotModal, #registerModal');
        if (!modal) return;
        const { bg, fg } = themeColors();
        e.target.style.setProperty('background-color', bg, 'important');
        e.target.style.setProperty('color', fg, 'important');
    });
})();
