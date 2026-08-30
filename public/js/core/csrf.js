// ══════════════════════════════════════════════════════════════
// js/core/csrf.js — CSRF token management and the automatic retry
// ══════════════════════════════════════════════════════════════

/**
 * updateCsrfToken(newToken)
 * Updates every input[name="csrf_token"] on the page with the new token.
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
 * headerValue(headers, name) — reads a header out of options.headers, whatever its shape.
 *
 * fetch accepts three shapes: a plain object, a Headers instance, or an array of pairs.
 * Header names are case-insensitive, so the comparison is done in lower case.
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
 * rebuildBodyWithToken(options, newToken) — produces a new body carrying the new token,
 * preserving the original body's shape.
 *
 * It returns { body, ok } — and ok=false means we did not know how to rebuild it, so a
 * retry is meaningless and must not be attempted.
 *
 * ⚠️ The third shape (JSON) was added later, and the previous version did not **ignore**
 * it but **corrupted** it: every string body went through URLSearchParams, turning
 * {"csrf_token":"…","items":[…]} into a single encoded key,
 * %7B%22csrf_token%22…, that json_decode cannot read. Which means the retry failed with
 * certainty for every endpoint sending JSON — the checkout page, admin/my-info,
 * admin-notifications, and others.
 */
function rebuildBodyWithToken(options, newToken) {
    const body = options.body;

    // 1. FormData — the commonest shape among the project's forms
    if (typeof FormData !== 'undefined' && body instanceof FormData) {
        const out = new FormData();
        let sawToken = false;
        for (const [key, val] of body.entries()) {
            if (key === 'csrf_token') { sawToken = true; out.append(key, newToken); }
            else                      { out.append(key, val); }
        }
        // Important: the older version replaced an existing key only. A form containing no
        // csrf_token at all (the forgot-password form used to be one) was retried with the
        // same incomplete request and failed again — a silently disabled safety net. Adding
        // it when absent fixes that.
        if (!sawToken) out.append('csrf_token', newToken);
        return { body: out, ok: true };
    }

    // 1b. URLSearchParams as an object — not as a string.
    //
    // The project does not send it today (verified: 38 sites using FormData and 3 using
    // JSON, with URLSearchParams used for query strings alone). But `fetch` accepts it as a
    // body and serialises it as urlencoded on its own, so writing
    //     body: params
    // is an entirely natural step for anyone adding a new endpoint.
    //
    // And without this branch it fell through to "a shape we do not know" and returned
    // ok=false — that is, **the retry was lost silently**. The same class of fault that
    // caught this file three times: the net looks functional because the first request
    // succeeds, and the fault only surfaces once a token genuinely expires.
    //
    // Four lines closing an open door that has been opened three times before.
    if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
        const out = new URLSearchParams(body.toString());
        out.set('csrf_token', newToken); // set adds it when absent
        return { body: out, ok: true };
    }

    if (typeof body === 'string') {
        const contentType = headerValue(options.headers, 'content-type').toLowerCase();
        const looksJson   = contentType.includes('json')
                         || /^\s*[{[]/.test(body); // A fallback for when the header is missing

        // 2. JSON
        if (looksJson) {
            let parsed;
            try {
                parsed = JSON.parse(body);
            } catch (e) {
                console.error('CSRF Retry: the JSON body cannot be parsed — no retry will be made', e);
                return { body, ok: false };
            }
            // The token is a field on an object. An array or a scalar has nowhere to put it.
            if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
                console.error('CSRF Retry: the JSON body is not an object — there is nowhere for the token');
                return { body, ok: false };
            }
            parsed.csrf_token = newToken;
            return { body: JSON.stringify(parsed), ok: true };
        }

        // 3. urlencoded
        const params = new URLSearchParams(body);
        params.set('csrf_token', newToken); // set adds it when absent
        return { body: params.toString(), ok: true };
    }

    // A shape we do not know (a Blob, an ArrayBuffer, or no body at all): do not guess
    return { body, ok: false };
}

/**
 * fetchWithCsrfRetry(url, options, _retried)
 * A wrapper around fetch() that retries automatically, exactly once, on a CSRF failure.
 *
 * It supports three body shapes: FormData · JSON · urlencoded.
 */
async function fetchWithCsrfRetry(url, options = {}, _retried = false) {
    const response = await fetch(url, options);
    const data     = await response.json();

    if (data.csrf_token) {
        updateCsrfToken(data.csrf_token);
    }

    // Detection by an explicit code, not by a message's text.
    //
    // The condition here used to be message.startsWith('Invalid CSRF token'), so any
    // endpoint wording its message differently lost the retry **silently**. That happened
    // three times in fact: the "notify me when available" button and the contact form
    // answered with 'Invalid session…' and 'Invalid request…', and six other endpoints
    // survived by chance because their wording happened to start with the same prefix.
    //
    // ERR_CSRF_INVALID is defined in App\Core\Controller and sent from
    // respondCsrfFailure(). The message is now for display alone and can change freely.
    if (!data.success && data.error_code === 'csrf_invalid' && !_retried) {
        try {
            // A different path per context — the admin uses /admin/csrf, a regular user /auth/csrf
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
                // We did not know how to rebuild the body. Retrying with a body that does not
                // carry the new token would fail with certainty and spend a request for nothing.
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
