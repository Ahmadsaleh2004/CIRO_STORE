<?php

namespace App\Controllers;

// ════════════════════════════════════════════════════════════════════════════
// TODO (a later phase — do not implement now):
//
//  1. 2FA (TOTP): ✅ done — see login() (the pending_2fa_admin_id state),
//     verify2FALogin(), and the POST /admin/login/2fa route.
//     Enabling and disabling live on the AdminMyInfoController page (my-info).
//
//  2. Email alert on a new device or IP:
//     after a fully successful login (and after 2FA), add:
//     - a comparison of the current IP with the admin's last recorded IP
//     - if it differs → call sendNewDeviceAlert($admin)
//     The present structure of login() is shaped to receive that call
//     after the line: "// [EMAIL_ALERT_HOOK]"
//
// Note: every future admin-panel controller must use the same
// session_name('admin_session') before session_start() — recorded here so the
// point is not forgotten while the remaining admin pages are built.
// ════════════════════════════════════════════════════════════════════════════

use App\Core\Controller;
use App\Models\AdminModel;
use App\Services\AdminSessionOpener;
use OpenApi\Attributes as OA;

/**
 * AdminAuthController — admin sign-in and sign-out.
 *
 * Entirely separate from the public AuthController.
 * It uses the admins table exclusively — it never touches the users table.
 * It uses session_name('admin_session'), distinct from the regular user session.
 */
class AdminAuthController extends Controller
{
    /** Lifetime, in seconds, of the pending state between password and code. */
    private const PENDING_2FA_TTL = 300;

    /** How many wrong codes the pending state tolerates before it is discarded. */
    private const MAX_2FA_ATTEMPTS = 5;

    /**
     * Ends the pending state between password and code.
     *
     * All three keys are always cleared together: leaving one behind without the
     * others would leave a half state — an id with no deadline, or a counter with
     * no id — and those are precisely the states that become hard to reason about
     * later.
     */
    private function clearPending2FA(): void
    {
        unset(
            $_SESSION['pending_2fa_admin_id'],
            $_SESSION['pending_2fa_started_at'],
            $_SESSION['pending_2fa_attempts']
        );
    }

    // ════════════════════════════════════════════════════════
    // GET /admin/login — render the sign-in page
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/admin/login',
        summary: 'Render the admin sign-in page',
        tags: ['Admin - Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Sign-in HTML page')
        ]
    )]
    public function showLogin(): void
    {
        startAdminSession();

        // If the admin is already signed in, send them to admin/home
        if (!empty($_SESSION['admin_id'])) {
            header('Location: ' . URLROOT . '/admin/home');
            exit;
        }

        // Prevent caching of the sign-in page
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $this->view('admin/login', [], 'bare');
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/login — handle the sign-in
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/login',
        summary: 'Sign the admin in',
        tags: ['Admin - Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['email', 'password', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'email', type: 'string', format: 'email', description: "The admin's email address"),
                        new OA\Property(property: 'password', type: 'string', format: 'password', description: 'Password'),
                        new OA\Property(property: 'csrf_token', type: 'string', description: 'CSRF token — always required'),
                        new OA\Property(property: 'h-captcha-response', type: 'string', description: 'hCaptcha response — required after the first failed attempt'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Whether the sign-in succeeded',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'redirect', type: 'string', description: 'Present on success only'),
                        new OA\Property(property: 'show_captcha', type: 'boolean', description: 'Present on failure — means hCaptcha must be shown'),
                        new OA\Property(property: 'failed_attempts', type: 'integer', description: 'Number of failed attempts'),
                    ]
                )
            )
        ]
    )]
    public function login(): void
    {
        startAdminSession();

        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rotateCsrfToken();
            $this->respond(false, 'Method not allowed.');
        }

        // CSRF
        $token = $_POST['csrf_token'] ?? '';
        // Deliberately not using beginJsonPost: this rotates the CSRF token on every
        // failure (rotateCsrfToken) before responding. The unified gate answers
        // immediately with no rotation, so switching to it would drop the rotation on
        // the most sensitive path in the project — the admin login.
        if (!verifyCsrfToken($token)) {
            rotateCsrfToken();
            $this->respondCsrfFailure();
        }

        $email = trim(strtolower($_POST['email']    ?? ''));
        $pass  = $_POST['password'] ?? '';

        if (!$email || !$pass) {
            rotateCsrfToken();
            $this->respond(false, 'Please enter your email and password.');
        }

        // ── Rate limiting (3 attempts / 30 minutes) ─────────────
        if (AdminModel::isRateLimited($email)) {
            rotateCsrfToken();
            $this->respond(false, 'Too many failed attempts. Access is locked for 30 minutes.');
        }

        // ── CAPTCHA (after the first failed attempt) ────────────
        $failedAttempts = AdminModel::getFailedAttempts($email);
        if ($failedAttempts >= 1) {
            $captchaResponse = $_POST['h-captcha-response'] ?? '';
            if (!$this->verifyCaptcha($captchaResponse)) {
                rotateCsrfToken();
                $this->respond(false, 'Captcha verification failed. Please try again.');
            }
        }

        // ── Verify the admin (the admins table only) ───────────
        $admin = AdminModel::findByEmail($email);

        if ($admin && password_verify($pass, $admin['password'])) {
            AdminModel::logLoginAttempt($email, true);

            // ── 2FA (TOTP) — an optional second step, per admin ──────
            // Immediately after the password succeeds: the full session is not opened
            // yet; the id is held in a temporary "pending" state until the correct code
            // arrives.
            if (!empty($admin['totp_enabled']) && !empty($admin['totp_secret'])) {
                $_SESSION['pending_2fa_admin_id'] = (int)$admin['id'];
                // The pending state is stamped with its start time and attempt counter.
                //
                // Without them it stayed open forever: whoever cleared the password kept
                // the pending session and could guess inside it without limit and without
                // expiry. The deadline narrows the window, the counter narrows the number
                // of requests inside it — and the router's throttle above both limits the
                // source itself.
                $_SESSION['pending_2fa_started_at'] = time();
                $_SESSION['pending_2fa_attempts']   = 0;
                rotateCsrfToken();
                $this->respond(true, 'Enter your 2FA code.', ['requires_2fa' => true]);
            }
            // When 2FA is not enabled, the normal session opening continues below
            //
            // Everything that used to be written here — the id rotation, the identity,
            // the permissions, the audit record, clearing the throttle, the alert email —
            // now lives in AdminSessionOpener, because it was duplicated verbatim in
            // verify2FALogin. And what gets forgotten in one of two copies does not
            // surface as an error but as a silent gap: a login path with no id rotation,
            // or with no permissions.
            AdminSessionOpener::open($admin);

            rotateCsrfToken();
            $this->respond(true, 'Welcome, ' . $_SESSION['admin_name'], [
                'redirect' => URLROOT . '/admin/home',
            ]);
        }

        // Failed
        AdminModel::logLoginAttempt($email, false);

        // Recount the attempts after recording this one
        $attemptsNow = AdminModel::getFailedAttempts($email);

        if ($attemptsNow === 3) {
            $adminRow = AdminModel::findByEmail($email);
            if ($adminRow) {
                \App\Core\Mailer::queue(
                    $adminRow['email'],
                    $adminRow['full_name'] ?? 'Admin',
                    'Alert: repeated failed sign-in attempts',
                    \App\Core\Mailer::template('Security alert', "
                        Three consecutive failed sign-in attempts were detected on your account.<br>
                        Sign-in has been locked for 30 minutes as a protective measure.<br><br>
                        If it was not you who tried to sign in, we recommend changing your password immediately.
                    ")
                );
            }
        }

        rotateCsrfToken();
        $this->respond(false, 'Email or password is incorrect.', [
            'show_captcha'    => $attemptsNow >= 1,
            'failed_attempts' => $attemptsNow,
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/login/2fa — verify the TOTP code after the password succeeds
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/login/2fa',
        summary: 'The second step of the admin sign-in — verifying the TOTP code',
        description: 'Called after /admin/login returns the requires_2fa field. '
                   . 'The code comes from an authenticator app (Google Authenticator or similar).',
        tags: ['Admin - Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['code', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'code', type: 'string', description: 'Six-digit TOTP code'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
            new OA\Response(response: 401, ref: '#/components/responses/SessionExpired'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
        ]
    )]
    public function verify2FALogin(): void
    {
        startAdminSession();

        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $token = $_POST['csrf_token'] ?? '';
        // Deliberately not using beginJsonPost: it rotates the token on failure, as login() does.
        if (!verifyCsrfToken($token)) {
            rotateCsrfToken();
            $this->respondCsrfFailure();
        }

        $pendingId = (int)($_SESSION['pending_2fa_admin_id'] ?? 0);
        if ($pendingId <= 0) {
            rotateCsrfToken();
            $this->respond(false, 'Session expired. Please log in again.');
        }

        // ── Limits on the pending state ─────────────────────────
        //
        // This step used to have no limit at all: the password had cleared, and the
        // code is six digits within a ±30-second window — three valid codes out of a
        // million at any instant. Anyone holding the password could get past the second
        // layer with a guessing loop, leaving it present in form but not in effect.
        //
        // Three limits now work together, each closing what the others do not:
        //   · the router throttle (throttle:admin-2fa) limits the source across sessions
        //   · the deadline below closes the time window
        //   · the counter ends the pending state itself
        if (time() - (int)($_SESSION['pending_2fa_started_at'] ?? 0) > self::PENDING_2FA_TTL) {
            $this->clearPending2FA();
            $this->respond(false, 'Session expired. Please log in again.');
        }

        $admin = AdminModel::findById($pendingId);
        if (!$admin || empty($admin['totp_enabled']) || empty($admin['totp_secret'])) {
            $this->clearPending2FA();
            $this->respond(false, '2FA is not enabled for this account. Please log in again.');
        }

        $code  = $_POST['code'] ?? '';
        $slice = \App\Core\Totp::verifyAndGetSlice(
            $admin['totp_secret'],
            $code,
            isset($admin['last_totp_slice']) ? (int)$admin['last_totp_slice'] : null
        );

        // Consumption is a condition of success, not a side effect of it:
        // consumeTotpSlice writes conditionally, so it returns false when a concurrent
        // request with the same code got there first. Refusing on that here is what
        // actually makes a single code single-use.
        if ($slice === null || !AdminModel::consumeTotpSlice($pendingId, $slice)) {
            $attempts = (int)($_SESSION['pending_2fa_attempts'] ?? 0) + 1;
            $_SESSION['pending_2fa_attempts'] = $attempts;

            if ($attempts >= self::MAX_2FA_ATTEMPTS) {
                AdminModel::logAction($pendingId, '2fa_attempts_exceeded');
                $this->clearPending2FA();
                $this->respond(false, 'Too many attempts. Please log in again.');
            }

            $this->respond(false, 'Invalid code. Please try again.');
        }

        // Success — the same path as after the password alone, through the same service.
        AdminSessionOpener::open($admin);

        $this->clearPending2FA();
        rotateCsrfToken();
        $this->respond(true, 'Welcome, ' . $_SESSION['admin_name'], [
            'redirect' => URLROOT . '/admin/home',
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/logout — sign out the admin only
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/logout',
        summary: 'Sign the admin out',
        tags: ['Admin - Auth'],
        responses: [
            new OA\Response(response: 302, description: 'Redirect to /admin/login after the session is destroyed')
        ]
    )]
    public function logout(): void
    {
        startAdminSession();

        // Verify before destroying. No beginJsonPost here: this endpoint redirects
        // with a 302 and never returns JSON (js/admin/admin-layout/admin-navbar.js
        // calls it with a bare fetch and then navigates itself).
        //
        // Without this check any external site could sign the admin out with
        // `<img src=".../admin/logout">` — the browser sends the admin_session cookie
        // automatically. The impact is annoyance rather than a breach, but it is a real
        // CSRF hole.
        //
        // On failure we redirect to the panel without destroying the session: the
        // request did not come from our pages, so the safe behaviour is not to obey it.
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            header('Location: ' . URLROOT . '/admin/home');
            exit;
        }

        // Destroy the admin session only — do not touch the regular user session (PHPSESSID)
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();

        header('Location: ' . URLROOT . '/admin/login');
        exit;
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/forgot — request an admin password reset link
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/forgot',
        summary: 'Request an admin password reset link',
        description: 'The message returned is the same whether the email exists or not, so the '
                   . 'endpoint does not disclose which addresses are registered as admins.',
        tags: ['Admin - Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['email', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'email', type: 'string', format: 'email'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
            new OA\Response(response: 401, ref: '#/components/responses/SessionExpired'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
        ]
    )]
    public function forgotPassword(): void
    {
        startAdminSession();

        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $token = $_POST['csrf_token'] ?? '';
        // Deliberately not using beginJsonPost: it rotates the token on failure, as login() does.
        if (!verifyCsrfToken($token)) {
            rotateCsrfToken();
            $this->respondCsrfFailure();
        }

        $email = trim(strtolower($_POST['email'] ?? ''));
        $admin = AdminModel::findByEmail($email);

        if ($admin) {
            $resetToken = AdminModel::createPasswordReset($email, 'admin');
            if ($resetToken) {
                $resetLink = URLROOT . '/auth/reset?token=' . $resetToken . '&email=' . urlencode($email) . '&type=admin';
                \App\Core\Mailer::queue(
                    $admin['email'],
                    $admin['full_name'] ?? 'Admin',
                    'Reset your password',
                    \App\Core\Mailer::template(
                        'Reset your password',
                        'Follow this link to reset your password'
                        . ' (valid for 60 minutes only):<br><br>'
                        . '<a href="{link}">{link}</a><br><br>'
                        . 'If you did not request this, ignore this email.',
                        ['link' => $resetLink]
                    )
                );
            }
        }

        // Always report success, to prevent enumeration of registered admin addresses
        $this->respond(true, 'If this email is registered, you will receive a reset link shortly.');
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/store-mode/enter — enter store-browsing mode as a visitor
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/store-mode/enter',
        summary: 'Put the admin into store-browsing mode, as a visitor (Store Mode)',
        tags: ['Admin - Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['csrf_token'],
                    properties: [
                        new OA\Property(property: 'csrf_token', type: 'string', description: 'CSRF token — always required'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 302, description: 'Redirect to the store / once store mode is set'),
            new OA\Response(response: 401, description: 'Not signed in (AJAX/POST)')
        ]
    )]
    public function enterStoreMode(): void
    {
        startAdminSession();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . URLROOT . '/admin/home');
            exit;
        }

        if (!isAdmin()) {
            header('Location: ' . URLROOT . '/admin/login');
            exit;
        }

        $token = $_POST['csrf_token'] ?? '';
        // Deliberately not using beginJsonPost: this fails with a header(Location),
        // not with JSON. The gate emits a JSON header and then responds, which would
        // turn a redirect into a response body.
        if (!verifyCsrfToken($token)) {
            header('Location: ' . URLROOT . '/admin/home');
            exit;
        }

        // The store-mode flag in the admin session (for any future check inside the panel)
        $_SESSION['admin_in_store_mode'] = true;

        // ⚠️ The admin session (admin_session) and the visitor session (PHPSESSID) are
        // entirely separate, and the store reads the store-mode flag only from the
        // visitor session (PHPSESSID). So a copy of the flag is written into both,
        // through a guarded switch of the session name.
        session_write_close();

        session_name('PHPSESSID');
        session_start();
        $_SESSION['admin_in_store_mode'] = true;
        session_write_close();

        // Restore the admin session context for the current response
        session_name('admin_session');
        session_start();

        header('Location: ' . URLROOT . '/');
        exit;
    }

    // ════════════════════════════════════════════════════════
    // GET /admin/store-mode/reauth — the password re-authentication page
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/admin/store-mode/reauth',
        summary: 'Show the password re-authentication page before returning to the panel',
        tags: ['Admin - Auth'],
        responses: [
            new OA\Response(response: 200, description: 'A standalone HTML page for re-authentication'),
            new OA\Response(response: 302, description: 'Redirect to /admin/home when the admin is not in store mode')
        ]
    )]
    public function showReauth(): void
    {
        startAdminSession();

        if (!isAdmin()) {
            header('Location: ' . URLROOT . '/admin/login');
            exit;
        }

        // If the admin is not in store mode, this page has no purpose
        if (empty($_SESSION['admin_in_store_mode'])) {
            header('Location: ' . URLROOT . '/admin/home');
            exit;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $this->view('admin/store-reauth', [
            'return' => $this->safeAdminReturn($_GET['return'] ?? ''),
        ], 'bare');
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/store-mode/reauth — password re-authentication (JSON)
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/store-mode/reauth',
        summary: 'Re-authenticate the admin by password before returning to the panel (JSON)',
        tags: ['Admin - Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['password', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'password', type: 'string', format: 'password', description: "The admin's password"),
                        new OA\Property(property: 'csrf_token', type: 'string', description: 'CSRF token — always required'),
                        new OA\Property(property: 'return', type: 'string', description: 'Return destination (optional) — guarded against open redirects'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Whether the check succeeded',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'redirect', type: 'string', description: 'Present on success only'),
                    ]
                )
            )
        ]
    )]
    public function reauth(): void
    {
        startAdminSession();

        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        if (!isAdmin()) {
            http_response_code(401);
            $this->respond(false, 'Session expired. Please log in again.');
        }

        $token = $_POST['csrf_token'] ?? '';
        // Deliberately not using beginJsonPost: this rotates the token **and returns
        // it in the response** ($extra['csrf_token']) so admin-auth.js can carry on
        // without a further fetch. That is special behaviour the unified gate does not
        // have.
        if (!verifyCsrfToken($token)) {
            // The new token is returned in the response so store-reauth.js can carry on
            // without a further fetch — and this endpoint really does go through
            // fetchWithCsrfRetry, so without error_code it would have lost its retry once
            // the message-text matching was removed from csrf.js.
            $this->respondCsrfFailure(['csrf_token' => rotateCsrfToken()]);
        }

        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $pass    = $_POST['password'] ?? '';

        if ($adminId <= 0 || $pass === '') {
            $this->respond(false, 'Please enter your password.');
        }

        if (!AdminModel::verifyPassword($adminId, $pass)) {
            AdminModel::logAction($adminId, 'store_mode_reauth_failed');
            $this->respond(false, 'Incorrect password.', [
                'csrf_token' => rotateCsrfToken(),
            ]);
        }

        // Success — clear store mode from the admin session and the visitor session alike.
        //
        // Extracting this into a function is not cosmetic: writing unset on $_SESSION
        // twice hides that the two calls act on **two different sessions** — the first
        // on admin_session and the second on PHPSESSID after the name is switched. The
        // two lines are textually identical and different in effect, which is the worst
        // thing two adjacent lines can be.
        $this->forgetStoreMode();
        session_write_close();

        session_name('PHPSESSID');
        session_start();
        $this->forgetStoreMode();
        session_write_close();

        session_name('admin_session');
        session_start();

        AdminModel::logAction($adminId, 'store_mode_exit');

        $this->respond(true, 'Welcome back, ' . ($_SESSION['admin_name'] ?? 'Admin'), [
            'redirect' => $this->safeAdminReturn($_POST['return'] ?? ''),
        ]);
    }

    // ════════════════════════════════════════════════════════
    // GET /admin/csrf — issue a fresh CSRF token for the admin forms
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/admin/csrf',
        summary: 'Issue a fresh CSRF token for the admin forms',
        tags: ['Admin - Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A fresh CSRF token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'token', type: 'string', description: 'CSRF token hex string'),
                    ]
                )
            )
        ]
    )]
    public function getCsrf(): void
    {
        startAdminSession();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'token' => generateCsrfToken()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ════════════════════════════════════════════════════════
    // Private helpers
    // ════════════════════════════════════════════════════════

    /**
     * Verify hCaptcha server-side.
     */
    private function verifyCaptcha(string $captchaResponse): bool
    {
        // ⚠️ A captcha is not merely unnecessary on a developer's copy — it is
        // IMPOSSIBLE there. hCaptcha refuses to issue a token to localhost and says so
        // inside its own widget, in red: "Warning: localhost detected. Please use a valid
        // host." The token therefore never arrives, verification fails, and because the
        // captcha only appears AFTER a failed attempt, the admin login becomes a dead end
        // from the first mistyped password onward, with a message that names the captcha
        // and explains nothing.
        //
        // The bypass below existed for this, but it was keyed on an unset secret — and a
        // developer with a real secret in .env (the ordinary case) never reached it.
        //
        // The test is APP_URL, not $_SERVER['HTTP_HOST']. The Host header comes from the
        // client, so a check resting on it could be switched off by sending
        // "Host: localhost" — turning a defence off from outside. APP_URL is server
        // configuration and cannot be reached that way.
        if (isLocalUrl(URLROOT)) {
            error_log('AdminAuthController: APP_URL is local — hCaptcha cannot issue a token for localhost, so the captcha is skipped');
            return true;
        }

        $secretKey = trim($_ENV['HCAPTCHA_SECRET_KEY'] ?? '');

        // A placeholder value (such as YOUR_HCAPTCHA_SECRET_KEY_HERE) is treated as
        // unset. Without this check, empty() passed because the string is not empty, so
        // the placeholder was sent to hCaptcha and always rejected — a permanent failure
        // with no visible cause, instead of activating the intended development bypass
        // below.
        if ($secretKey === '' || str_starts_with($secretKey, 'YOUR_')) {
            error_log("AdminAuthController: HCAPTCHA_SECRET_KEY not configured in .env — bypassing captcha check (dev mode)");
            return true;
        }

        if (empty($captchaResponse)) {
            error_log("AdminAuthController: captcha required but h-captcha-response was empty in POST");
            return false;
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query([
                    'secret'   => $secretKey,
                    'response' => $captchaResponse,
                    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ]),
                'timeout' => 5,
            ],
        ]);

        $result = @file_get_contents('https://hcaptcha.com/siteverify', false, $context);
        if ($result === false) {
            // Happens when the network is down, outbound HTTPS is blocked, or allow_url_fopen=Off
            error_log("AdminAuthController: hCaptcha siteverify request failed (network/allow_url_fopen?)");
            return false;
        }

        $data = json_decode($result, true);

        // hCaptcha returns error-codes explaining the refusal (such as
        // invalid-or-already-seen-response or sitekey-secret-mismatch). Without logging
        // them, any failure becomes entirely silent.
        if (empty($data['success'])) {
            $codes = isset($data['error-codes']) && is_array($data['error-codes'])
                ? implode(', ', $data['error-codes'])
                : 'none returned';
            error_log("AdminAuthController: hCaptcha verification rejected — error-codes: {$codes}");
            return false;
        }

        return true;
    }

    /**
     * Guards the return parameter against open redirects on the Store Mode pages:
     * it accepts only an internal destination starting with URLROOT.'/admin/' or the
     * relative /admin/. Anything outside that range is replaced with a safe default.
     */
    private function safeAdminReturn(?string $return): string
    {
        $return = trim((string)$return);
        if ($return === '') {
            return URLROOT . '/admin/home';
        }

        if (str_starts_with($return, URLROOT . '/admin/')) {
            return $return;
        }

        // An acceptable relative form — expanded to a full URL inside the panel only
        if (str_starts_with($return, '/admin/')) {
            return URLROOT . $return;
        }

        return URLROOT . '/admin/home';
    }

    // renderStandaloneView() was removed from here: it rendered a view without a
    // layout because the inherited view() forced head+navbar+footer. That is now an
    // option on the parent class — $this->view($path, $data, 'bare') — so a local
    // copy no longer means anything. What the removal gained: the existence check
    // now precedes any output, and a real 404 page replaces the plain-text
    // "View not found: {$viewPath}".

    /**
     * Clears the store-mode flag from **whichever session is currently open**.
     *
     * The name matters: the function does not know which session it is acting on,
     * and that is deliberate — reauth calls it twice, once for the admin session and
     * once for the visitor session after session_name('PHPSESSID'). The function
     * describes the act; the caller owns the context.
     */
    private function forgetStoreMode(): void
    {
        unset($_SESSION['admin_in_store_mode']);
    }
}
