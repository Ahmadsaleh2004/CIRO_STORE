/**
 * variant-swatches.js — يلوّن أزرار ألوان الـvariants.
 *
 * لماذا يوجد هذا الملف أصلاً؟
 *
 * لون كل variant يأتي من قاعدة البيانات، فهو مجموعة مفتوحة لا يمكن أن
 * تصير classes. وكان يُكتب في الترميز مباشرةً:
 *
 *     style="border-left:14px solid #hex;"
 *
 * وهذا آخر ما منع حذف 'unsafe-inline' من style-src في الـCSP. سياسة
 * تسمح بالأنماط المضمّنة لا تستطيع أن تمنع نمطاً محقوناً — فالسمة كان
 * لا بدّ أن تختفي قبل أن يُشدَّد التوجيه.
 *
 * الحلّ: القيمة تخرج من PHP كبيان في data-swatch، وتُكتب هنا في خاصية
 * CSS مخصّصة عبر الـCSSOM. وCSP لا يمنع ذلك: هو يحكم ما في **الترميز**
 * لا ما يكتبه سكربت مسموح به. القاعدة نفسها (border-left) تبقى في
 * base/utilities.css تحت .u-swatch.
 *
 * الاحتياط: القيمة تُفحص قبل الكتابة. مصدرها القاعدة، ويكتبها الأدمن،
 * لكن setProperty بقيمة غير متوقَّعة تُدخل نصّاً غريباً في CSSOM بلا
 * فائدة. النمط السداسي وحده يمرّ.
 */

(function () {
    'use strict';

    const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

    function paint(root) {
        const nodes = (root || document).querySelectorAll('[data-swatch]');

        Array.prototype.forEach.call(nodes, function (el) {
            const value = el.getAttribute('data-swatch');

            if (!value || !HEX.test(value.trim())) {
                return;
            }

            el.classList.add('u-swatch');
            el.style.setProperty('--swatch', value.trim());
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            paint(document);
        });
    } else {
        paint(document);
    }

    // متاحة عالمياً كي يستدعيها أي كود يحقن أزرار variants بعد التحميل.
    window.paintVariantSwatches = paint;
})();
