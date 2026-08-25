// ══════════════════════════════════════════════════════════════
// js/features/reset-password.js — فورم إعادة تعيين كلمة المرور
// ══════════════════════════════════════════════════════════════
//
// كان هذا الملف كتلة <script> مضمّنة (58 سطراً) في
// app/views/auth/reset-password.php. القيمتان الوحيدتان القادمتان من
// PHP (window.BASE_URL و window.URLROOT، وكلتاهما URLROOT) بقيتا في
// سطر مضمّن واحد في الـview — تمرير بيانات لا منطق.
//
// fetchWithCsrfRetry تأتي من js/core/csrf.js المحمَّل قبل هذا الملف.

(function () {
    'use strict';

    function showResetMsg(el, text, type) {
        if (!el) return;
        el.textContent  = text;
        el.className    = 'alert py-2 small mb-3 alert-' + type;
        el.style.display = 'block';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('resetForm');
        if (!form) return; // الرابط منتهٍ أو غير صحيح — لا فورم في الصفحة

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            var btn      = document.getElementById('resetBtn');
            var msgEl    = document.getElementById('resetMsg');
            var pass     = document.getElementById('newPassword').value;
            var confirm  = document.getElementById('confirmPassword').value;

            if (pass.length < 8) {
                showResetMsg(msgEl, 'Password must be at least 8 characters.', 'danger');
                return;
            }
            if (pass !== confirm) {
                showResetMsg(msgEl, 'Passwords do not match.', 'danger');
                return;
            }

            if (btn) btn.disabled = true;

            try {
                // الاحتياط fetch العاري لا يعمل فعلياً: fetch تُرجع Response
                // لا بيانات مُحلَّلة، فـdata.message ستكون undefined. لكنه
                // غير قابل للوصول عملياً — csrf.js يُحمَّل بـdefer، وسكربتات
                // defer تُنفَّذ قبل DOMContentLoaded، فالدالة معرَّفة دائماً
                // لحظة وصول المستخدم إلى هنا. تُرك كما كان.
                var doFetch = (typeof window.fetchWithCsrfRetry === 'function')
                    ? window.fetchWithCsrfRetry
                    : fetch;
                var data = await doFetch(window.BASE_URL + '/auth/reset', {
                    method: 'POST',
                    body: new FormData(form)
                });

                showResetMsg(msgEl, data.message, data.success ? 'success' : 'danger');

                if (data.success) {
                    setTimeout(function () {
                        window.location.href = window.BASE_URL;
                    }, 1200);
                }
            } catch (err) {
                showResetMsg(msgEl, 'Something went wrong. Please try again.', 'danger');
            } finally {
                if (btn) btn.disabled = false;
            }
        });
    });
})();
