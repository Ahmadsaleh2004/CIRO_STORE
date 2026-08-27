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
 * headerValue(headers, name) — يقرأ ترويسة من options.headers أياً كان شكلها.
 *
 * fetch يقبل ثلاثة أشكال: كائن عادي، أو Headers، أو مصفوفة أزواج.
 * أسماء الترويسات غير حسّاسة لحالة الأحرف، فالمقارنة بحروف صغيرة.
 */
function headerValue(headers, name) {
    if (!headers) return '';
    const wanted = name.toLowerCase();

    if (typeof Headers !== 'undefined' && headers instanceof Headers) {
        return headers.get(name) || '';
    }
    if (Array.isArray(headers)) {
        const hit = headers.find(pair => String(pair[0]).toLowerCase() === wanted);
        return hit ? String(hit[1]) : '';
    }
    const key = Object.keys(headers).find(k => k.toLowerCase() === wanted);
    return key ? String(headers[key]) : '';
}

/**
 * rebuildBodyWithToken(options, newToken) — يُنتج جسماً جديداً يحمل التوكن
 * الجديد، محافظاً على شكل الجسم الأصلي.
 *
 * يُرجع { body, ok } — و ok=false تعني أننا لم نعرف كيف نعيد البناء،
 * فإعادة المحاولة بلا معنى ويجب ألّا تُجرَّب.
 *
 * ⚠️ الشكل الثالث (JSON) أُضيف لاحقاً، والنسخة السابقة لم تكن **تتجاهله**
 * بل **تفسده**: كل جسم نصّي كان يمرّ على URLSearchParams، فيتحوّل
 * {"csrf_token":"…","items":[…]} إلى مفتاح واحد مُرمَّز
 * %7B%22csrf_token%22… لا يستطيع json_decode قراءته. أي أن إعادة
 * المحاولة كانت تفشل حتماً لكل نقطة ترسل JSON — وهي صفحة الدفع و
 * admin/my-info و admin-notifications وغيرها.
 */
function rebuildBodyWithToken(options, newToken) {
    const body = options.body;

    // 1. FormData — الشكل الأكثر شيوعاً في فورمات المشروع
    if (typeof FormData !== 'undefined' && body instanceof FormData) {
        const out = new FormData();
        let sawToken = false;
        for (const [key, val] of body.entries()) {
            if (key === 'csrf_token') { sawToken = true; out.append(key, newToken); }
            else                      { out.append(key, val); }
        }
        // مهم: النسخة الأقدم كانت تستبدل مفتاحاً موجوداً فقط. الفورم الذي
        // لا يحوي csrf_token إطلاقاً (كفورم forgot-password سابقاً) كانت
        // تُعاد محاولته بنفس الطلب الناقص فيفشل مجدداً — شبكة أمان معطّلة
        // صامتة. الإضافة عند الغياب تصحّح ذلك.
        if (!sawToken) out.append('csrf_token', newToken);
        return { body: out, ok: true };
    }

    // 1ب. URLSearchParams ككائن — لا كنصّ.
    //
    // المشروع اليوم لا يرسله (مفحوص: 38 موضعاً بـFormData و3 بـJSON،
    // وURLSearchParams مستعملة لسلاسل الاستعلام وحدها). لكن `fetch`
    // يقبله جسماً ويسلسله urlencoded من تلقائه، فكتابة
    //     body: params
    // خطوة طبيعية تماماً لمن يضيف نقطة جديدة.
    //
    // وبلا هذا الفرع كانت تسقط إلى «شكل لا نعرفه» فتُرجع ok=false —
    // أي **تُفقد إعادة المحاولة بصمت**. وهو الصنف نفسه الذي أوقع هذا
    // الملف ثلاث مرّات: الشبكة تبدو عاملة لأن الطلب الأول ينجح، ولا
    // يظهر العطل إلا حين ينتهي التوكن فعلاً.
    //
    // أربعة أسطر تقفل باباً مفتوحاً، فُتح ثلاث مرّات من قبل.
    if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
        const out = new URLSearchParams(body.toString());
        out.set('csrf_token', newToken); // set يضيف إذا كان غائباً
        return { body: out, ok: true };
    }

    if (typeof body === 'string') {
        const contentType = headerValue(options.headers, 'content-type').toLowerCase();
        const looksJson   = contentType.includes('json')
                         || /^\s*[{[]/.test(body); // احتياط لو غابت الترويسة

        // 2. JSON
        if (looksJson) {
            let parsed;
            try {
                parsed = JSON.parse(body);
            } catch (e) {
                console.error('CSRF Retry: جسم JSON غير قابل للتحليل — لن تُعاد المحاولة', e);
                return { body, ok: false };
            }
            // التوكن حقل في كائن. مصفوفة أو قيمة مفردة لا مكان فيها له.
            if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
                console.error('CSRF Retry: جسم JSON ليس كائناً — لا موضع للتوكن');
                return { body, ok: false };
            }
            parsed.csrf_token = newToken;
            return { body: JSON.stringify(parsed), ok: true };
        }

        // 3. urlencoded
        const params = new URLSearchParams(body);
        params.set('csrf_token', newToken); // set يضيف إذا كان غائباً
        return { body: params.toString(), ok: true };
    }

    // شكل لا نعرفه (Blob أو ArrayBuffer أو بلا جسم): لا نخمّن
    return { body, ok: false };
}

/**
 * fetchWithCsrfRetry(url, options, _retried)
 * Wrapper لـ fetch() يُعيد المحاولة تلقائياً مرة واحدة إذا فشل CSRF
 *
 * يدعم ثلاثة أشكال أجسام: FormData · JSON · urlencoded.
 */
async function fetchWithCsrfRetry(url, options = {}, _retried = false) {
    const response = await fetch(url, options);
    const data     = await response.json();

    if (data.csrf_token) {
        updateCsrfToken(data.csrf_token);
    }

    // الاكتشاف برمز صريح لا بنصّ رسالة.
    //
    // كان الشرط هنا message.startsWith('Invalid CSRF token')، فكانت أي
    // نقطة تصوغ رسالتها بشكل آخر تفقد إعادة المحاولة **بصمت**. حدث ذلك
    // ثلاث مرات فعلاً: زر «نبّهني عند التوفّر» ونموذج «اتصل بنا» كانا
    // يردّان بـ'Invalid session…' و'Invalid request…'، وست نقاط أخرى نجت
    // بالصدفة لأن صياغتها بدأت بالبادئة نفسها.
    //
    // ERR_CSRF_INVALID معرَّف في App\Core\Controller ويُرسَل من
    // respondCsrfFailure(). الرسالة صارت للعرض وحدها وتتغيّر بحرية.
    if (!data.success && data.error_code === 'csrf_invalid' && !_retried) {
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

            const rebuilt = rebuildBodyWithToken(options, newToken);
            if (!rebuilt.ok) {
                // لم نعرف كيف نعيد بناء الجسم. إعادة المحاولة بجسم لا يحمل
                // التوكن الجديد ستفشل حتماً وتستهلك طلباً بلا فائدة.
                return data;
            }

            const newOptions = { ...options, body: rebuilt.body };
            return fetchWithCsrfRetry(url, newOptions, true);
        } catch (e) {
            console.error('CSRF Retry failed:', e);
        }
    }

    return data;
}
window.fetchWithCsrfRetry = fetchWithCsrfRetry;
