<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\UserModel;
use OpenApi\Attributes as OA;

/**
 * AuthController — login / registration / logout / password reset / Google OAuth.
 * Moved and converted from the old handlers/auth_handler.php.
 * Every request returns JSON (auth.js calls them through fetch).
 */
class AuthController extends Controller
{
    // ════════════════════════════════════════════════════════
    // POST /auth/login
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/auth/login',
        summary: 'Log a user in',
        description: 'Rate limited: after five failed attempts, login is locked for 15 minutes '
                   . 'and a security alert is emailed. A verified email address is required, and '
                   . 'an account suspended on three strikes is refused.',
        tags: ['Store - Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['email', 'password', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'email', type: 'string', format: 'email'),
                        new OA\Property(property: 'password', type: 'string', format: 'password'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON — {success, message, redirect?, type?, needs_verification?}'
            ),
        ]
    )]
    public function login(): void
    {
        // Make sure a PHPSESSID session exists before any CSRF work or session read/write
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->beginJsonPost();

        $email = trim(strtolower($_POST['email']    ?? ''));
        $pass  = $_POST['password'] ?? '';

        if (!$email || !$pass) {
            $this->respond(false, 'Please enter your email and password.');
        }

        // Rate limiting
        if (UserModel::isRateLimited($email)) {
            $this->respond(false, 'Too many failed attempts. Please wait 15 minutes and try again.');
        }

        // --- Regular user check ---
        $user = UserModel::findByEmail($email);

        if ($user && password_verify($pass, $user['password'])) {
            // Check email verification before allowing the login
            if (empty($user['email_verified_at'])) {
                $this->respond(false, 'Please verify your email before logging in. Check your inbox.', [
                    'needs_verification' => true,
                ]);
            }

            // Check the block (3 strikes)
            if (UserModel::getStrikesCount((int)$user['id']) >= 3) {
                $this->respond(false, 'Your account has been suspended due to multiple violations. Please contact support.');
            }

            UserModel::logLoginAttempt($email, true);
            // The login succeeded — its owner should not pay for earlier failed attempts.
            \App\Core\Throttle::clear('store-login', \App\Core\Throttle::clientIp());
            session_regenerate_id(true);

            // The token follows the id. regenerate_id keeps the session contents —
            // csrf_token among them — so a pre-authentication token would stay valid for
            // an authenticated session. See rotateCsrfToken in csrf_helper.php.
            $freshCsrf = rotateCsrfToken();

            $_SESSION['user_id']     = (int)$user['id'];
            $_SESSION['user_name']   = $user['full_name'];
            $_SESSION['user_email']  = $user['email'];
            $_SESSION['last_active'] = time();

            UserModel::updateActivity((int)$user['id']);

            // Redirect after login
            $redirectAfter = $_SESSION['redirect_after_login'] ?? URLROOT;
            unset($_SESSION['redirect_after_login']);

            $this->respond(true, 'Welcome, ' . $user['full_name'], [
                'redirect' => $redirectAfter,
                'type'     => 'user',
                // js/core/csrf.js picks csrf_token out of any response and updates every
                // field on the page with it. Sending it here makes the rotation free:
                // without it the client discovers its token is stale through a failed
                // request and then retries — two requests instead of one after every
                // login.
                'csrf_token' => $freshCsrf,
            ]);
        }

        UserModel::logLoginAttempt($email, false);

        $attemptsNow = UserModel::getFailedAttemptsCount($email);
        if ($attemptsNow === 5) {
            $userRow = UserModel::findByEmail($email);
            if ($userRow) {
                \App\Core\Mailer::queue(
                    $userRow['email'],
                    $userRow['full_name'] ?? 'User',
                    'Alert: repeated failed sign-in attempts',
                    \App\Core\Mailer::template('Security alert', "
                        Five consecutive failed sign-in attempts were detected on your account.<br>
                        Sign-in has been locked for 15 minutes as a protective measure.<br><br>
                        If this was not you, we recommend changing your password immediately
                        through the \"Forgot password\" option.
                    ")
                );
            }
        }

        $this->respond(false, 'Email or password is incorrect.');
    }

    // ════════════════════════════════════════════════════════
    // POST /auth/register
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/auth/register',
        summary: 'Create a new user account',
        description: 'Validation rules: the email must end in @gmail.com; the password must be at '
                   . 'least 8 characters with an upper-case letter, a lower-case letter, a digit '
                   . 'and a symbol; the user must be 13 or older; the email and phone must not '
                   . 'already be registered; and agreeing to the privacy policy is mandatory.',
        tags: ['Store - Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['full_name', 'email', 'password', 'confirm_password', 'phone',
                               'gender', 'birth_date', 'privacy_policy_accepted', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'full_name', type: 'string', minLength: 2),
                        new OA\Property(property: 'email', type: 'string', format: 'email'),
                        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                        new OA\Property(property: 'confirm_password', type: 'string', format: 'password'),
                        new OA\Property(property: 'phone', type: 'string'),
                        new OA\Property(property: 'country', type: 'string'),
                        new OA\Property(property: 'city', type: 'string'),
                        new OA\Property(property: 'gender', type: 'string', enum: ['male', 'female']),
                        new OA\Property(property: 'birth_date', type: 'string', format: 'date'),
                        new OA\Property(property: 'privacy_policy_accepted', type: 'boolean'),
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
        ]
    )]
    public function register(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->beginJsonPost();

        // Gather the input
        $fullName    = trim($_POST['full_name']       ?? '');
        $email       = trim(strtolower($_POST['email'] ?? ''));
        $pass        = $_POST['password']              ?? '';
        $confirmPass = $_POST['confirm_password']      ?? '';
        $phone       = trim($_POST['phone']            ?? '');
        $country     = trim($_POST['country']          ?? '');
        $city        = trim($_POST['city']             ?? '');
        $gender      = $_POST['gender']                ?? '';
        $birthDate   = $_POST['birth_date']            ?? '';
        $ppAccepted  = !empty($_POST['privacy_policy_accepted']);

        // Validation
        if (strlen($fullName) < 2) {
            $this->respond(false, 'Full name must be at least 2 characters.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respond(false, 'Please enter a valid email address.');
        }

        if (!str_ends_with($email, '@gmail.com')) {
            $this->respond(false, 'Email must be a @gmail.com address.');
        }

        if (
            strlen($pass) < 8 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) ||
            !preg_match('/[0-9]/', $pass) || !preg_match('/[\W_]/', $pass)
        ) {
            $this->respond(false, 'Password must be at least 8 characters with uppercase, lowercase, number, and symbol.');
        }

        if ($pass !== $confirmPass) {
            $this->respond(false, 'Passwords do not match.');
        }

        if (!in_array($gender, ['male', 'female'], true)) {
            $this->respond(false, 'Please select your gender.');
        }

        if (!$ppAccepted) {
            $this->respond(false, 'You must agree to the Privacy Policy.');
        }

        // Validate the phone number
        if (empty($phone)) {
            $this->respond(false, 'Phone number is required.');
        }

        // Validate the age (13+)
        if (!$birthDate) {
            $this->respond(false, 'Birth date is required.');
        }
        $birth = new \DateTime($birthDate);
        $today = new \DateTime();
        if ($today->diff($birth)->y < 13) {
            $this->respond(false, 'You must be at least 13 years old to register.');
        }

        // Check for duplicates
        if (UserModel::findByEmail($email)) {
            $this->respond(false, 'This email is already registered. Please sign in.');
        }

        if (UserModel::phoneExists($phone)) {
            $this->respond(false, 'This phone number is already registered with another account.');
        }

        // Create the account
        $hash    = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $newUserId = UserModel::create([
            'full_name'  => $fullName,
            'email'      => $email,
            'password'   => $hash,
            'phone'      => $phone,
            'country'    => $country ?: null,
            'city'       => $city    ?: null,
            'gender'     => $gender,
            'birth_date' => $birthDate,
        ]);

        if (!$newUserId) {
            $this->respond(false, 'Something went wrong, please try again.');
        }

        // Send the email verification link
        $verifyToken = UserModel::createEmailVerification($newUserId);
        if ($verifyToken) {
            $verifyLink = URLROOT . '/auth/verify?token=' . $verifyToken;
            \App\Core\Mailer::queue(
                $email,
                $fullName,
                'Verify your email address',
                \App\Core\Mailer::template(
                    'Welcome',
                    'Thanks for signing up! Follow this link to activate your account (valid for 24 hours):<br><br>'
                    . '<a href="{link}">{link}</a>',
                    ['link' => $verifyLink]
                )
            );
        }

        $this->respond(true, 'Account created! Check your email to verify your account before logging in.');
    }

    // ════════════════════════════════════════════════════════
    // POST /auth/logout
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/auth/logout',
        summary: 'Log the user out and end the session',
        tags: ['Store - Auth'],
        security: [['userSessionAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
    )]
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Verify before destroying — for a security reason, not a tidiness one:
        // without it any external site could sign your visitor out with nothing more
        // than `<img src=".../auth/logout">` or a hidden form, because the browser
        // sends the session cookie automatically. The impact is annoyance rather than
        // data theft, but it is a real CSRF hole with no justification.
        //
        // beginJsonPost reads the token through requestData() before touching the
        // session at all, so the call order here is not incidental.
        $this->beginJsonPost();

        // Clear the session entirely
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

        $this->respond(true, 'Logged out successfully.', ['redirect' => URLROOT]);
    }

    // ════════════════════════════════════════════════════════
    // POST /auth/forgot
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/auth/forgot',
        summary: 'Request a password reset link',
        description: 'The message returned is the same whether the email exists or not, so the '
                   . 'endpoint does not disclose which addresses are registered.',
        tags: ['Store - Auth'],
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
        ]
    )]
    public function forgot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $this->beginJsonPost();

        $email = trim(strtolower($_POST['email'] ?? ''));
        $user = UserModel::findByEmail($email);

        if ($user) {
            $resetToken = UserModel::createPasswordReset($email, 'user');
            if ($resetToken) {
                $resetLink = URLROOT . '/auth/reset?token=' . $resetToken . '&email=' . urlencode($email) . '&type=user';
                \App\Core\Mailer::queue(
                    $email,
                    $user['full_name'] ?? 'User',
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

        // Always report success, to prevent enumeration of registered addresses
        $this->respond(true, 'If this email is registered, you will receive a reset link shortly.');
    }

    // GET /auth/reset — render the password change form (a page, not JSON)
    #[OA\Get(
        path: '/auth/reset',
        summary: 'Password reset page',
        description: 'A standalone page that does not load the store layout. It serves both '
                   . 'users and admins, according to the token type.',
        tags: ['Store - Auth'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function resetForm(): void
    {
        $token = $_GET['token'] ?? '';
        $email = $_GET['email'] ?? '';
        $type  = $_GET['type']  ?? 'user';

        $valid = $type === 'admin'
            ? \App\Models\AdminModel::validatePasswordResetToken($email, $token, 'admin')
            : UserModel::validatePasswordResetToken($email, $token, 'user');

        // 'bare': a standalone page with no store navbar and no store footer — the
        // full store assets are not loaded here, because this is reached without a
        // session, on a token alone.
        $this->view('auth/reset-password', [
            'valid' => $valid,
            'token' => $token,
            'email' => $email,
            'type'  => $type,
        ], 'bare');
    }

    // POST /auth/reset — perform the password change
    #[OA\Post(
        path: '/auth/reset',
        summary: 'Save the new password once the token checks out',
        tags: ['Store - Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['token', 'password', 'confirm_password', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'password', type: 'string', format: 'password'),
                        new OA\Property(property: 'confirm_password', type: 'string', format: 'password'),
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
        ]
    )]
    public function resetSubmit(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->beginJsonPost();

        $email = trim(strtolower($_POST['email'] ?? ''));
        $token = $_POST['token'] ?? '';
        $type  = $_POST['type']  ?? 'user';
        $newPassword = $_POST['password'] ?? '';

        if (strlen($newPassword) < 8) {
            $this->respond(false, 'Password must be at least 8 characters.');
        }

        $isAdmin = $type === 'admin';
        $valid = $isAdmin
            ? \App\Models\AdminModel::validatePasswordResetToken($email, $token, 'admin')
            : UserModel::validatePasswordResetToken($email, $token, 'user');

        if (!$valid) {
            $this->respond(false, 'This link is invalid or expired. Please request a new one.');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        // The account may have been deleted between requesting the link and using it —
        // the token stays valid in password_resets after its owner is gone. Without
        // this check, (int)$user['id'] read from null and PHP printed a warning
        // **before** the response body, so the front end received an error page rather
        // than JSON and fell over at json() with a generic "Something went wrong" that
        // described nothing.
        if ($isAdmin) {
            $admin = \App\Models\AdminModel::findByEmail($email);
            if (!$admin) {
                $this->respond(false, 'This link is invalid or expired. Please request a new one.');
            }
            \App\Models\AdminModel::updatePassword((int)$admin['id'], $hash);
            \App\Models\AdminModel::consumePasswordResetToken($email, $token, 'admin');
        } else {
            $user = UserModel::findByEmail($email);
            if (!$user) {
                $this->respond(false, 'This link is invalid or expired. Please request a new one.');
            }
            UserModel::updatePassword((int)$user['id'], $hash);
            UserModel::consumePasswordResetToken($email, $token, 'user');
        }

        $this->respond(true, 'Password updated successfully. You can now log in.');
    }

    // ════════════════════════════════════════════════════════
    // GET /auth/verify — verify the email address through the link
    #[OA\Get(
        path: '/auth/verify',
        summary: 'Verify the email address using the link sent after registration',
        tags: ['Store - Auth'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirect home with a success or error message'),
        ]
    )]
    public function verifyEmail(): void
    {
        $token = $_GET['token'] ?? '';
        $ok = UserModel::verifyEmailToken($token);
        if ($ok) {
            header('Location: ' . URLROOT . '/?openLogin=1&verified=1');
        } else {
            header('Location: ' . URLROOT . '/?verify_error=1');
        }
        exit;
    }

    // GET /auth/csrf — issue a fresh CSRF token (for the automatic retry)
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/auth/csrf',
        summary: 'Fetch a fresh CSRF token for the store forms',
        description: 'The one endpoint that requires no token — it is where the token comes from.',
        tags: ['Store - Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
    )]
    public function getCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        header('Content-Type: application/json; charset=utf-8');
        $this->respond(true, 'OK', ['token' => generateCsrfToken()]);
    }

    // ════════════════════════════════════════════════════════
    // GET /auth/google — redirect to Google's consent screen
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/auth/google',
        summary: 'Begin sign-in through Google OAuth',
        tags: ['Store - Auth'],
        responses: [
            new OA\Response(response: 302, description: "Redirect to Google's consent screen"),
        ]
    )]
    public function googleLogin(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $clientId    = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $redirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? (URLROOT . '/auth/google/callback');

        if (!$clientId) {
            header('Location: ' . URLROOT . '/?openLogin=1&error=google_unavailable');
            exit;
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;

        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ]);

        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
        exit;
    }

    // GET /auth/google/callback — receive Google's code
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/auth/google/callback',
        summary: "Receive Google's response and establish the session",
        description: 'Creates a new account when the email is not registered; otherwise links the existing one.',
        tags: ['Store - Auth'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirect home after the sign-in succeeds or fails'),
        ]
    )]
    public function googleCallback(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_GET['error'])) {
            header('Location: ' . URLROOT . '/?openLogin=1&error=google_cancelled');
            exit;
        }

        $code  = $_GET['code']  ?? '';
        $state = $_GET['state'] ?? '';

        if (!$code || !$state || !isset($_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], $state)) {
            header('Location: ' . URLROOT . '/?openLogin=1&error=google_error');
            exit;
        }
        unset($_SESSION['google_oauth_state']);

        try {
            // 1) Exchange the code for an access_token
            $tokenResponse = $this->httpPost('https://oauth2.googleapis.com/token', [
                'code'          => $code,
                'client_id'     => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
                'redirect_uri'  => $_ENV['GOOGLE_REDIRECT_URI'] ?? (URLROOT . '/auth/google/callback'),
                'grant_type'    => 'authorization_code',
            ]);

            $tokenData = json_decode($tokenResponse, true);
            if (empty($tokenData['access_token'])) {
                throw new \Exception('No access token returned');
            }

            // 2) Fetch the user's profile
            $userInfoJson = $this->httpGet('https://www.googleapis.com/oauth2/v3/userinfo', $tokenData['access_token']);
            $userInfo = json_decode($userInfoJson, true);

            $googleId = $userInfo['sub']   ?? null;
            $email    = $userInfo['email'] ?? null;
            $name     = $userInfo['name']  ?? ($userInfo['given_name'] ?? 'Google User');

            if (!$googleId || !$email) {
                header('Location: ' . URLROOT . '/?openLogin=1&error=google_no_email');
                exit;
            }

            $user = UserModel::findByEmail($email);

            if ($user) {
                if (empty($user['google_id'])) {
                    UserModel::updateGoogleId((int)$user['id'], $googleId);
                }
                $userId = (int)$user['id'];
            } else {
                $userId = UserModel::createFromGoogle($googleId, $email, $name);
                if (!$userId) {
                    header('Location: ' . URLROOT . '/?openLogin=1&error=google_create_failed');
                    exit;
                }
                $user = UserModel::findById($userId);
            }

            session_regenerate_id(true);

            // As on the password login path — the token follows the id. It is not
            // returned in a response body here: this path ends in a redirect, and the
            // next page is rendered with the new token anyway.
            rotateCsrfToken();

            $_SESSION['user_id']     = $userId;
            $_SESSION['user_name']   = $user['full_name'] ?? $name;
            $_SESSION['user_email']  = $email;
            $_SESSION['last_active'] = time();

            $redirectAfter = $_SESSION['redirect_after_login'] ?? URLROOT;
            unset($_SESSION['redirect_after_login']);

            header('Location: ' . $redirectAfter);
            exit;
        } catch (\Exception $e) {
            error_log('Google OAuth Error: ' . $e->getMessage());
            header('Location: ' . URLROOT . '/?openLogin=1&error=google_error');
            exit;
        }
    }

    /** A minimal POST over cURL, with no external library. */
    /**
     * @param array<string, string> $fields
     */
    private function httpPost(string $url, array $fields): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new \Exception('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);
        return $response;
    }

    /** A minimal GET over cURL with an Authorization Bearer header. */
    private function httpGet(string $url, string $bearerToken): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $bearerToken]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new \Exception('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);
        return $response;
    }
}
