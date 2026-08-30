// ══════════════════════════════════════════════════════════════
// public/js/core/page-data.js — نقل بيانات الصفحة إلى النطاق العام
// ══════════════════════════════════════════════════════════════
//
// كانت أربع عشرة صفحة تمرّر بياناتها بكتلة <script> مضمّنة:
//
//     <script>window.dbProducts = { … };</script>
//
// وهي كتلة قابلة للتنفيذ، فأي سياسة CSP جادّة تحجبها — ولهذا بقيت
// سياسة المشروع بوضع الإبلاغ فقط طوال الوقت.
//
// الآن تطبع الـviews جزيرة <script type="application/json"> — وهي
// ليست كتلة تنفيذ، فلا يعنيها script-src أصلاً — وهذا الملف ينسخها
// إلى window.
//
// ⚠️ **يجب أن يُحمَّل أوّلاً، قبل أي ملف يقرأ window.**
// موضعه في footer.php و admin/inc/footer.php أعلى قائمة السكربتات،
// وقبل حزم الطرف الثالث. نقله لاحقاً يكسر كل صفحة تعتمد على بياناتها.
//
// ولماذا ليس defer مثل البقية؟ لأن defer يؤجّل التنفيذ إلى ما بعد
// تحليل المستند — وهو ما نريده تماماً للبقية، لكنه هنا يعني أن ملفاً
// آخر بـdefer قد يسبقه في الترتيب لو أُعيد ترتيب الوسوم يوماً. الحمل
// المتزامن يجعل الأسبقية صفةً لا يمكن نقضها بترتيب.

// ── تمريرة ثانية بعد اكتمال التحليل ───────────────────────────
//
// الحمل المتزامن يضمن الأسبقية، لكنه يضمن معها أن ما لم يُحلَّل بعد
// **لا يُرى**. وجزيرة تُطبع أسفل الفوتر — بعد وسم هذا الملف — كانت
// تسقط بلا أثر: لا خطأ، لا تحذير، فقط `window.X` غير معرَّف وميزة
// كاملة لا تعمل.
//
// وقد وقع ذلك فعلاً: صفحة admin/orders/details.php كانت تُسنِد
// جزيرتها إلى $extraScripts، والفوتر يطبع $extraScripts بعد هذا
// الملف. فلم تصل ADMIN_ORDER_DETAILS إلى window قط، ولم تُعرَّف
// window.handleTakeIt المتفرّعة عنها، فبدا زرّ «Take It» معطّلاً.
//
// فالتمريرة الأولى تبقى متزامنة لأسبقيتها، وتلحقها تمريرة عند
// DOMContentLoaded تلتقط ما وُلد متأخّراً. والعنصر يُوسَم عند
// معالجته فلا يُقرأ مرّتين ولا يُحذّر من نفسه.
(function () {
    'use strict';

    var PROCESSED = 'data-page-data-loaded';

    function absorb() {
        var islands = document.querySelectorAll(
            'script[type="application/json"][data-page-data]:not([' + PROCESSED + '])'
        );

        for (var i = 0; i < islands.length; i++) {
            var island = islands[i];
            island.setAttribute(PROCESSED, '');

            var raw = island.textContent;
            if (!raw) continue;

            var payload;
            try {
                payload = JSON.parse(raw);
            } catch (e) {
                // بيانات معطوبة تعني صفحة بلا وظيفة، والصمت هنا يجعل السبب
                // مستحيل التتبّع. نُبقي الصفحة تعمل بما بقي ونُبلغ صراحةً.
                console.error('[page-data] تعذّر تحليل جزيرة البيانات:', e);
                continue;
            }

            if (!payload || typeof payload !== 'object') continue;

            for (var key in payload) {
                if (!Object.prototype.hasOwnProperty.call(payload, key)) continue;

                // ⚠️ لا نكتب فوق شيء موجود سلفاً. جزيرتان تحملان المفتاح
                // نفسه خطأ برمجي، والكتابة الصامتة تجعل الفائز يتبع ترتيب
                // العناصر في المستند — وهو ترتيب لا يقصده أحد.
                if (key in window && window[key] !== undefined && window[key] !== null) {
                    console.warn('[page-data] المفتاح [' + key + '] معرَّف سلفاً — تُرك كما هو.');
                    continue;
                }

                window[key] = payload[key];
            }
        }
    }

    absorb();

    // ⚠️ التمريرة الثانية شبكة أمان لا رخصة: أي ملف يقرأ window في
    // جسمه مباشرة (لا داخل DOMContentLoaded) سيسبقها. فموضع الجزيرة
    // الصحيح يبقى **قبل الفوتر**، واختبار
    // tests/Unit/PageDataIslandTest.php يفرض ذلك.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', absorb);
    }
})();
