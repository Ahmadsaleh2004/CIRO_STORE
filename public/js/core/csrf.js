// ══════════════════════════════════════════════════════════════
// js/core/csrf.js — إدارة CSRF Token والـ Retry التلقائي
// ══════════════════════════════════════════════════════════════

/**
 * updateCsrfToken(newToken)
 * تُحدّث كل input[name="csrf_token"] بالصفحة بالتوكن الجديد.
 */
function updateCsrfToken(newToken) {
    if (!newToken) return;
    document.querySelectorAll('input[name="csrf_token"]').forEach(el => {
        el.value = newToken;
    });
    window._csrfToken = newToken;
}
window.updateCsrfToken = updateCsrfToken;

/**
 * fetchWithCsrfRetry(url, options, _retried)
 * Wrapper لـ fetch() يُعيد المحاولة تلقائياً مرة واحدة إذا فشل CSRF
 */
async function fetchWithCsrfRetry(url, options = {}, _retried = false) {
    const response = await fetch(url, options);
    const data     = await response.json();

    if (data.csrf_token) {
        updateCsrfToken(data.csrf_token);
    }

    // الصياغتان مستخدمتان بالمشروع: 'Invalid CSRF token.' و'Invalid CSRF token, please refresh and try again.'
    if (!data.success && typeof data.message === 'string'
        && data.message.startsWith('Invalid CSRF token') && !_retried) {
        try {
            // مسار مختلف حسب السياق — الأدمن يستخدم /admin/csrf، المستخدم العادي /auth/csrf
            const csrfEndpoint = (typeof window.URLROOT !== 'undefined')
                ? window.URLROOT + '/admin/csrf'
                : window.BASE_URL + '/auth/csrf';
            const csrfRes = await fetch(csrfEndpoint);
            const csrfData = await csrfRes.json();
            const newToken = csrfData.token;
            if (!newToken) throw new Error('No token received');

            updateCsrfToken(newToken);

            const newOptions = { ...options };
            if (options.body instanceof FormData) {
                const newBody = new FormData();
                let sawToken = false;
                for (const [key, val] of options.body.entries()) {
                    if (key === 'csrf_token') {
                        sawToken = true;
                        newBody.append(key, newToken);
                    } else {
                        newBody.append(key, val);
                    }
                }
                // مهم: النسخة السابقة كانت تستبدل مفتاحًا موجودًا فقط. إذا كان
                // الفورم لا يحتوي حقل csrf_token إطلاقًا (كما كان في فورم
                // forgot-password)، لم تكن الحلقة تضيفه، فتُعاد المحاولة بنفس
                // الطلب الفارغ وتفشل مجددًا — أي أن شبكة الأمان كانت معطّلة
                // صامتة تمامًا لأي فورم ينقصه الحقل.
                if (!sawToken) newBody.append('csrf_token', newToken);
                newOptions.body = newBody;
            } else if (typeof options.body === 'string') {
                const params = new URLSearchParams(options.body);
                params.set('csrf_token', newToken); // set يضيف إذا كان غائبًا
                newOptions.body = params.toString();
            }

            return fetchWithCsrfRetry(url, newOptions, true);
        } catch (e) {
            console.error('CSRF Retry failed:', e);
        }
    }

    return data;
}
window.fetchWithCsrfRetry = fetchWithCsrfRetry;
