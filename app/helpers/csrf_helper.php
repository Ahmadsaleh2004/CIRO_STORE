<?php

/**
 * app/helpers/csrf_helper.php
 * CSRF token management — generation and verification.
 *
 * Note: this file does not call session_start() at file level. The appropriate session
 * (PHPSESSID or admin_session) is assumed to have been started before
 * generateCsrfToken() or verifyCsrfToken() is called.
 */

function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Invalidates the current token and issues another — **on a privilege change alone**.
 *
 * ══════════════════════════════════════════════════════════════
 * The hole it closes
 * ══════════════════════════════════════════════════════════════
 *
 * `session_regenerate_id(true)` replaces the session id and keeps **its contents** as
 * they are — which is its purpose. But those contents include `csrf_token`.
 *
 * So the token an anonymous visitor obtained before signing in stays valid afterwards,
 * character for character. And anyone who managed to read a token before authentication
 * — through a shared window, a public page, or a subdomain — holds a token valid for an
 * **authenticated** session that did not exist when they read it. Guarding against that
 * is precisely why `session_regenerate_id` is called on the preceding line: we replace
 * the id so it is not inherited, and then inherit the token.
 *
 * ── Why here rather than after every successful POST ─────────
 *
 * Rotating after every state-changing request was the first option, and it was rejected
 * on measurement:
 *
 *   · the token in this project is **one per session**, and every open form reads it.
 *     So rotating it after every POST invalidates the forms in other tabs immediately —
 *     and each of them spends a wasted request retrying through js/core/csrf.js. That is
 *     double the requests on half the user's actions, the checkout page worst of all.
 *   · and the security gain against that is slight: the token travels in the request
 *     **body** rather than the URL, so it leaks into neither a server log nor a Referer
 *     header. Periodic rotation addresses a leak that does not occur with this design.
 *
 * A privilege change is an entirely different case: there the leak is **assumed**
 * rather than merely possible — we already assume that what preceded authentication may
 * be known, which is why the session id is replaced. So the token must follow it.
 *
 * ⚠️ Call it **after** `session_regenerate_id()`, not before.
 *
 * @return string The new token — return it in the response body so js/core/csrf.js
 *                updates immediately, rather than discovering the old one is stale
 *                through a failed request and then retrying.
 */
function rotateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    unset($_SESSION['csrf_token']);

    return generateCsrfToken();
}
