// ══════════════════════════════════════════════════════════════
// js/admin/admin-layout/admin-navbar.js — the admin panel's top bar
// ══════════════════════════════════════════════════════════════
//
// logoutAdmin() was moved out of an inline <script> block in
// app/views/admin/inc/navbar.php. A single line remains in the view passing
// window._csrfToken — data, not logic.
//
// The function stays **global** deliberately: the markup calls it straight from an
// onclick (<a onclick="logoutAdmin()">), so wrapping it in an IIFE would break the button.
//
// Separate from the store's logoutUser(): the two sessions differ in name and contents
// (admin_session against PHPSESSID), and the two sign-out endpoints differ too.

// A bare fetch, deliberately — not fetchWithCsrfRetry: AdminAuthController::logout
// redirects with a 302 and returns no JSON at all, and the wrapper calls response.json(),
// so it would throw on the redirect.
//
// The token is sent and verified on the server, since the sign-out CSRF fix. An expired
// token means the admin stays signed in and is redirected to /admin/home — a visible
// failure rather than a silent one, so no automatic retry is needed here.
// Called from HTML rather than from JavaScript: app/views/admin/inc/navbar.php:120
// carries onclick="logoutAdmin()". And ESLint does not see the views, so it reads this as
// a function with no caller. The exception is local rather than in the global config, so
// the check keeps working on every other function in the project.
// eslint-disable-next-line no-unused-vars
function logoutAdmin() {
    // nosemgrep: cairo-bare-fetch-post
    fetch(window.URLROOT + '/admin/logout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(window._csrfToken)
    }).then(() => { window.location.href = window.URLROOT + '/admin/login'; });
}
