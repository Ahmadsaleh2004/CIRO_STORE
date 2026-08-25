// ══════════════════════════════════════════════════════════════
// js/admin/store-reauth.js — إعادة التحقق قبل الرجوع من وضع المتجر
// ══════════════════════════════════════════════════════════════
//
// كان هذا الملف كتلة <script> مضمّنة (63 سطراً) في
// app/views/admin/store-reauth.php. نقل خالص: الكتلة لم تكن تحقن أي
// قيمة PHP، تقرأ window.URLROOT فقط (يضبطه سطر واحد في الـview —
// وهو تمرير بيانات لا منطق، فبقي مكانه).
//
// updateCsrfToken تأتي من js/core/csrf.js المحمَّل قبل هذا الملف.

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('storeReauthForm');
        const btn  = document.getElementById('reauthBtn');
        const alertEl = document.getElementById('alertMsg');
        if (!form || !btn || !alertEl) return;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            alertEl.textContent = '';
            alertEl.className = 'alert-msg';
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Verifying…';

            try {
                const fd = new FormData(form);
                const res = await fetch(window.URLROOT + '/admin/store-mode/reauth', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                let data;
                try {
                    data = await res.json();
                } catch {
                    alertEl.className = 'alert-msg error visible';
                    alertEl.textContent = 'Unexpected server response. Please try again.';
                    return;
                }

                if (data.success) {
                    alertEl.className = 'alert-msg success visible';
                    alertEl.textContent = data.message || 'Verified. Redirecting…';
                    setTimeout(function () {
                        window.location.href = data.redirect || (window.URLROOT + '/admin/home');
                    }, 500);
                } else {
                    alertEl.className = 'alert-msg error visible';
                    alertEl.textContent = data.message || 'Verification failed.';
                    // تحديث توكن CSRF إن أعادته اللوحة بعد الفشل
                    if (data.csrf_token && typeof updateCsrfToken === 'function') {
                        updateCsrfToken(data.csrf_token);
                    }
                }
            } catch (err) {
                console.error('Store reauth error:', err);
                alertEl.className = 'alert-msg error visible';
                alertEl.textContent = 'Connection error. Please try again.';
            } finally {
                btn.disabled = false;
                // ⚠️ كان هنا 'Verify &amp; Return' — و textContent لا يفكّ
                // كيانات HTML، فكان الزر يعرض «Verify &amp; Return» حرفياً
                // بعد أول محاولة فاشلة. الماركب في الـview يستعمل &amp;
                // لأنه HTML؛ هنا نصّ خام فيُكتب المحرف نفسه.
                btn.textContent = 'Verify & Return';
            }
        });
    });
})();
