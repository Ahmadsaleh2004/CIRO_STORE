<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\UserModel;
use OpenApi\Attributes as OA;

/**
 * AuthController — تسجيل الدخول / التسجيل / الخروج / نسيت كلمة المرور / Google OAuth
 * منقول ومُحوَّل من handlers/auth_handler.php القديم
 * كل الطلبات ترجع JSON (تستدعيها auth.js عبر fetch)
 */
class AuthController extends Controller
{
    // ════════════════════════════════════════════════════════
    // POST /auth/login
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/auth/login',
        summary: 'تسجيل دخول مستخدم',
        description: 'محمي بحدّ محاولات (Rate limiting): بعد 5 محاولات فاشلة يُقفل الدخول '
                   . '15 دقيقة ويُرسَل تنبيه أمني بالبريد. يشترط تفعيل البريد، ويرفض الحساب '
                   . 'الموقوف بثلاث مخالفات.',
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
        // تأكد من وجود جلسة PHPSESSID قبل أي عملية CSRF أو session read/write
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

        // --- فحص المستخدم العادي ---
        $user = UserModel::findByEmail($email);

        if ($user && password_verify($pass, $user['password'])) {
            // فحص تفعيل الإيميل قبل السماح بالدخول
            if (empty($user['email_verified_at'])) {
                $this->respond(false, 'Please verify your email before logging in. Check your inbox.', [
                    'needs_verification' => true,
                ]);
            }

            // فحص الحظر (3 strikes)
            if (UserModel::getStrikesCount((int)$user['id']) >= 3) {
                $this->respond(false, 'Your account has been suspended due to multiple violations. Please contact support.');
            }

            UserModel::logLoginAttempt($email, true);
            session_regenerate_id(true);

            $_SESSION['user_id']     = (int)$user['id'];
            $_SESSION['user_name']   = $user['full_name'];
            $_SESSION['user_email']  = $user['email'];
            $_SESSION['last_active'] = time();

            UserModel::updateActivity((int)$user['id']);

            // توجيه بعد تسجيل الدخول
            $redirectAfter = $_SESSION['redirect_after_login'] ?? URLROOT;
            unset($_SESSION['redirect_after_login']);

            $this->respond(true, 'Welcome, ' . $user['full_name'], [
                'redirect' => $redirectAfter,
                'type'     => 'user',
            ]);
        }

        UserModel::logLoginAttempt($email, false);

        $attemptsNow = UserModel::getFailedAttemptsCount($email);
        if ($attemptsNow === 5) {
            $userRow = UserModel::findByEmail($email);
            if ($userRow) {
                \App\Core\Mailer::send(
                    $userRow['email'],
                    $userRow['full_name'] ?? 'User',
                    'تنبيه: محاولات دخول فاشلة متكررة',
                    \App\Core\Mailer::template('تنبيه أمني', "
                        تم رصد 5 محاولات دخول فاشلة متتالية على حسابك.<br>
                        تم قفل الدخول مؤقتًا لمدة 15 دقيقة كإجراء حماية.<br><br>
                        إذا لم تكن أنت، ننصحك بتغيير كلمة المرور فورًا من خيار
                        \"نسيت كلمة المرور\".
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
        summary: 'إنشاء حساب مستخدم جديد',
        description: 'قيود التحقق: البريد يجب أن ينتهي بـ@gmail.com، وكلمة المرور 8 محارف '
                   . 'على الأقل بحرف كبير وصغير ورقم ورمز، والعمر 13 سنة فأكثر، والبريد '
                   . 'والهاتف غير مسجَّلين مسبقاً، والموافقة على سياسة الخصوصية إلزامية.',
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
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function register(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->beginJsonPost();

        // جمع البيانات
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
        if (strlen($fullName) < 2)
            $this->respond(false, 'Full name must be at least 2 characters.');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            $this->respond(false, 'Please enter a valid email address.');

        if (!str_ends_with($email, '@gmail.com'))
            $this->respond(false, 'Email must be a @gmail.com address.');

        if (strlen($pass) < 8 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) ||
            !preg_match('/[0-9]/', $pass) || !preg_match('/[\W_]/', $pass)) {
            $this->respond(false, 'Password must be at least 8 characters with uppercase, lowercase, number, and symbol.');
        }

        if ($pass !== $confirmPass)
            $this->respond(false, 'Passwords do not match.');

        if (!in_array($gender, ['male', 'female'], true))
            $this->respond(false, 'Please select your gender.');

        if (!$ppAccepted)
            $this->respond(false, 'You must agree to the Privacy Policy.');

        // التحقق من رقم الهاتف
        if (empty($phone)) {
            $this->respond(false, 'Phone number is required.');
        }

        // التحقق من العمر 13+
        if (!$birthDate) {
            $this->respond(false, 'Birth date is required.');
        }
        $birth = new \DateTime($birthDate);
        $today = new \DateTime();
        if ($today->diff($birth)->y < 13) {
            $this->respond(false, 'You must be at least 13 years old to register.');
        }

        // التحقق من التكرار
        if (UserModel::findByEmail($email)) {
            $this->respond(false, 'This email is already registered. Please sign in.');
        }

        if (UserModel::phoneExists($phone)) {
            $this->respond(false, 'This phone number is already registered with another account.');
        }

        // إنشاء الحساب
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

        // إرسال رابط تفعيل الإيميل
        $verifyToken = UserModel::createEmailVerification($newUserId);
        if ($verifyToken) {
            $verifyLink = URLROOT . '/auth/verify?token=' . $verifyToken;
            \App\Core\Mailer::send(
                $email,
                $fullName,
                'فعّل بريدك الإلكتروني',
                \App\Core\Mailer::template('أهلًا بك', "
                    شكرًا لتسجيلك! اضغط على الرابط لتفعيل حسابك (صالح 24 ساعة):<br><br>
                    <a href='{$verifyLink}'>{$verifyLink}</a>
                ")
            );
        }

        $this->respond(true, 'Account created! Check your email to verify your account before logging in.');
    }

    // ════════════════════════════════════════════════════════
    // POST /auth/logout
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/auth/logout',
        summary: 'تسجيل خروج المستخدم وإنهاء الجلسة',
        tags: ['Store - Auth'],
        security: [['userSessionAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message, redirect?}')]
    )]
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // التحقق قبل التدمير — ولسبب أمني لا تنظيمي: بدونه كان أي موقع
        // خارجي يستطيع تسجيل خروج زائرك بمجرد `<img src=".../auth/logout">`
        // أو فورم مخفي، لأن المتصفح يرسل كوكي الجلسة تلقائياً. الأثر
        // إزعاج لا سرقة بيانات، لكنه CSRF قائم بلا مبرّر.
        //
        // beginJsonPost تقرأ التوكن عبر requestData() قبل أي مساس
        // بالجلسة، فترتيب الاستدعاء هنا ليس تفصيلاً.
        $this->beginJsonPost();

        // مسح الجلسة بالكامل
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
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
        summary: 'طلب رابط إعادة تعيين كلمة المرور',
        description: 'الرسالة المُرجَعة واحدة سواء وُجد البريد أم لا — كي لا تكشف النقطة '
                   . 'أي البُرد مسجَّلة.',
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
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function forgot(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
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
                \App\Core\Mailer::send(
                    $email,
                    $user['full_name'] ?? 'User',
                    'إعادة تعيين كلمة المرور',
                    \App\Core\Mailer::template('إعادة تعيين كلمة المرور', "
                        اضغط على الرابط التالي لإعادة تعيين كلمة المرور
                        (صالح لمدة 60 دقيقة فقط):<br><br>
                        <a href='{$resetLink}'>{$resetLink}</a><br><br>
                        إذا لم تطلب هذا، تجاهل هذا الإيميل.
                    ")
                );
            }
        }

        // نُظهر نجاح دائمًا لمنع تعداد الإيميلات المسجّلة
        $this->respond(true, 'If this email is registered, you will receive a reset link shortly.');
    }

    // GET /auth/reset — عرض فورم تغيير كلمة المرور (صفحة، مو JSON)
    #[OA\Get(
        path: '/auth/reset',
        summary: 'صفحة إعادة تعيين كلمة المرور',
        description: 'صفحة مستقلة لا تحمّل layout المتجر. تخدم المستخدم والأدمن معاً '
                   . 'حسب نوع التوكن.',
        tags: ['Store - Auth'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'صفحة HTML')]
    )]
    public function resetForm(): void
    {
        $token = $_GET['token'] ?? '';
        $email = $_GET['email'] ?? '';
        $type  = $_GET['type']  ?? 'user';

        $valid = $type === 'admin'
            ? \App\Models\AdminModel::validatePasswordResetToken($email, $token, 'admin')
            : UserModel::validatePasswordResetToken($email, $token, 'user');

        // 'bare': صفحة مستقلة بلا navbar ولا footer المتجر — لا تُحمَّل
        // هنا أصول المتجر كاملة لأن الوصول إليها بلا جلسة وبتوكن فقط.
        $this->view('auth/reset-password', [
            'valid' => $valid,
            'token' => $token,
            'email' => $email,
            'type'  => $type,
        ], 'bare');
    }

    // POST /auth/reset — تنفيذ تغيير كلمة المرور
    #[OA\Post(
        path: '/auth/reset',
        summary: 'حفظ كلمة المرور الجديدة بعد التحقق من التوكن',
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
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function resetSubmit(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        header('Content-Type: application/json; charset=utf-8');

        $csrf = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($csrf)) {
            $this->respond(false, 'Invalid CSRF token.');
        }

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

        // الحساب قد يكون حُذف بين طلب الرابط واستعماله — الرمز يبقى صالحاً
        // في password_resets بعد حذف صاحبه. بلا هذا الفحص كان
        // (int)$user['id'] يقرأ من null فيطبع PHP تحذيراً **قبل** جسم
        // الاستجابة، فتصل الواجهة صفحة خطأ لا JSON وتسقط عند json()
        // برسالة عامة «Something went wrong» لا تصف شيئاً.
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
    // GET /auth/verify — تفعيل الإيميل عبر الرابط
    #[OA\Get(
        path: '/auth/verify',
        summary: 'تفعيل البريد عبر الرابط المُرسَل بعد التسجيل',
        tags: ['Store - Auth'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 302, description: 'تحويل للرئيسية مع رسالة نجاح أو خطأ'),
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

    // GET /auth/csrf — توليد CSRF Token جديد (للـ retry التلقائي)
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/auth/csrf',
        summary: 'جلب توكن CSRF جديد لنماذج المتجر',
        description: 'النقطة الوحيدة التي لا تتطلّب توكناً — منها يُجلب.',
        tags: ['Store - Auth'],
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message, token}')]
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
    // GET /auth/google — توجيه لصفحة موافقة جوجل
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/auth/google',
        summary: 'بدء تسجيل الدخول عبر Google OAuth',
        tags: ['Store - Auth'],
        responses: [
            new OA\Response(response: 302, description: 'تحويل إلى شاشة موافقة Google'),
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

    // GET /auth/google/callback — استقبال كود جوجل
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/auth/google/callback',
        summary: 'استقبال رد Google وإنشاء الجلسة',
        description: 'ينشئ حساباً جديداً إن لم يكن البريد مسجَّلاً، وإلا يربط الحساب القائم.',
        tags: ['Store - Auth'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 302, description: 'تحويل للرئيسية بعد نجاح الدخول أو فشله'),
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
            // 1) تبديل الـ code بـ access_token
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

            // 2) جلب بيانات المستخدم
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

    /** POST بسيط باستخدام cURL (بدون أي مكتبة خارجية) */
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

    /** GET بسيط باستخدام cURL مع Authorization Bearer */
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
