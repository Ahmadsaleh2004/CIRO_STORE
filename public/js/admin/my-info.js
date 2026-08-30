// ══════════════════════════════════════════════════════════════
// js/admin/my-info.js — صفحة «بياناتي» في لوحة التحكم
// ══════════════════════════════════════════════════════════════
//
// كان هذا الملف كتلتَي <script> مضمّنتين (144 سطراً) داخل
// app/views/admin/my-info.php: تحديث بيانات الحساب، وتفعيل/تعطيل 2FA.
//
// النقل هنا نقل خالص بلا سمات data-*: الكتلتان لم تكونا تحقنان أي قيمة
// PHP إطلاقاً — كل ما تحتاجانه هو window.URLROOT (يضبطه
// admin/inc/head.php) ومعرّفات عناصر DOM.
//
// يُحمَّل عبر extraScripts من AdminMyInfoController، لا من فوتر الأدمن:
// فوتر الأدمن يحمّل ثلاثة عشر ملفاً على كل صفحة، ولا داعي لإضافة رابع
// عشر يخصّ صفحة واحدة. ملف my-info.css محمَّل بنفس الطريقة أصلاً.

(function () {
    'use strict';

    // ── تحديث بيانات الحساب ─────────────────────────────────────
    document.getElementById('adminProfileForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        const form  = e.target;
        const msgEl = document.getElementById('profileMsg');
        const data  = Object.fromEntries(new FormData(form));
        msgEl.style.display = 'none';

        try {
            const res = await fetchWithCsrfRetry(window.URLROOT + '/admin/my-info', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(data),
            });

            msgEl.className   = res.success
                ? 'alert alert-success py-2 small'
                : 'alert alert-danger py-2 small';
            msgEl.textContent = res.message;
            msgEl.style.display = 'block';

            if (res.success) {
                form.querySelector('[name="current_password"]').value = '';
            }
        } catch {
            msgEl.className   = 'alert alert-danger py-2 small';
            msgEl.textContent = 'Network error.';
            msgEl.style.display = 'block';
        }
    });

    // ── 2FA (TOTP) — تفعيل / تعطيل عبر AJAX ─────────────────────
    const msgEl = document.getElementById('twofaMsg');
    if (!msgEl) return;

    function showMsg(success, text) {
        msgEl.className   = success
            ? 'alert alert-success py-2 small'
            : 'alert alert-danger py-2 small';
        msgEl.textContent = text;
        msgEl.style.display = 'block';
    }

    // fetchWithCsrfRetry تدعم أجسام JSON منذ المرحلة 6ب-1: تعيد بناء
    // الجسم بالتوكن الجديد وتحافظ على بقية الحقول. قبل ذلك كانت تفسده،
    // ولهذا كان هذا الملف يستعمل fetch عارياً.
    async function postJson(url, data) {
        return fetchWithCsrfRetry(window.URLROOT + url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(data),
        });
    }

    const csrf = () => document.querySelector('#adminProfileForm [name="csrf_token"]')?.value || '';

    // تفعيل — الخطوة 1: توليد secret وإظهار QR
    const enableBtn = document.getElementById('twofaEnableBtn');
    if (enableBtn) {
        enableBtn.addEventListener('click', async () => {
            enableBtn.disabled = true;
            msgEl.style.display = 'none';
            try {
                const d = await postJson('/admin/my-info/2fa/generate', { csrf_token: csrf() });
                if (!d.success) {
                    showMsg(false, d.message || 'Could not start 2FA setup.');
                    enableBtn.disabled = false;
                    return;
                }
                // ⚠️ twofaSetupStep يحمل `d-none` في الترميز، وهي
                // `display:none !important` — فـstyle.display='block'
                // لا يظهرها. كانت خطوة إعداد المصادقة الثنائية لا تُفتح
                // إطلاقاً: يُنشأ السرّ على الخادم ولا يراه الأدمن.
                //
                // twofaSetup لا يحمل d-none، فيبقى إخفاؤه بـstyle.
                document.getElementById('twofaSetup').style.display = 'none';
                document.getElementById('twofaSetupStep').classList.remove('d-none');
                document.getElementById('twofaQr').src  = d.qrcode_url;
                document.getElementById('twofaSecret').textContent = d.secret;
                document.getElementById('twofaCode').focus();
            } catch {
                showMsg(false, 'Network error.');
                enableBtn.disabled = false;
            }
        });

        // إلغاء الإعداد
        document.getElementById('twofaCancelBtn').addEventListener('click', () => {
            document.getElementById('twofaSetupStep').classList.add('d-none');
            document.getElementById('twofaSetup').style.display = 'block';
            enableBtn.disabled = false;
        });

        // تفعيل — الخطوة 2: تأكيد الكود الأول
        document.getElementById('twofaConfirmBtn').addEventListener('click', async () => {
            const code = document.getElementById('twofaCode').value.trim();
            if (!/^\d{6}$/.test(code)) {
                showMsg(false, 'Please enter the 6-digit code from your authenticator app.');
                return;
            }
            const btn = document.getElementById('twofaConfirmBtn');
            btn.disabled = true;
            msgEl.style.display = 'none';
            try {
                const d = await postJson('/admin/my-info/2fa/confirm', {
                    csrf_token: csrf(),
                    code:       code,
                });
                if (d.success) {
                    showMsg(true, d.message);
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    showMsg(false, d.message || 'Invalid code.');
                    btn.disabled = false;
                    document.getElementById('twofaCode').focus();
                }
            } catch {
                showMsg(false, 'Network error.');
                btn.disabled = false;
            }
        });
    }

    // تعطيل — يتطلب كلمة المرور الحالية
    const disableForm = document.getElementById('twofaDisableForm');
    if (disableForm) {
        disableForm.addEventListener('submit', async e => {
            e.preventDefault();
            const passEl = document.getElementById('twofaDisablePassword');
            if (!passEl.value) {
                showMsg(false, 'Please enter your current password.');
                return;
            }
            const data = Object.fromEntries(new FormData(disableForm));
            msgEl.style.display = 'none';
            try {
                const d = await postJson('/admin/my-info/2fa/disable', data);
                if (d.success) {
                    showMsg(true, d.message);
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    showMsg(false, d.message || 'Could not disable 2FA.');
                    passEl.value = '';
                }
            } catch {
                showMsg(false, 'Network error.');
            }
        });
    }
})();
