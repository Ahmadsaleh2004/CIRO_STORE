<?php

namespace App\Controllers;

// ════════════════════════════════════════════════════════════════════════════
// TODO (مرحلة قادمة — لا تُنفَّذ الآن):
//
//  1. 2FA (TOTP): ✅ نُفِّذ — انظر login() (حالة pending_2fa_admin_id)
//     ودالة verify2FALogin() + مسار POST /admin/login/2fa.
//     التفعيل/التعطيل من صفحة AdminMyInfoController (my-info).
//
//  2. إشعار الإيميل عند جهاز/IP جديد:
//     بعد نجاح تسجيل الدخول بالكامل (وبعد 2FA)، أضف:
//     - مقارنة IP الحالي مع آخر IP مسجّل للأدمن
//     - إذا كان مختلفاً → استدعاء دالة sendNewDeviceAlert($admin)
//     البنية الحالية في login() مصممة لاستقبال هذا الاستدعاء
//     بعد السطر: "// [EMAIL_ALERT_HOOK]"
//
// ملاحظة: كل Controller خاص بلوحة التحكم لاحقاً لازم يستخدم نفس
// session_name('admin_session') قبل session_start() — حتى لا تُنسى
// هذه النقطة عند بناء باقي صفحات الأدمن.
// ════════════════════════════════════════════════════════════════════════════

use App\Core\Controller;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

/**
 * AdminAuthController — تسجيل دخول الأدمن / خروجه
 *
 * مستقل تماماً عن AuthController العام.
 * يستخدم جدول admins حصراً — لا يلمس جدول users إطلاقاً.
 * يستخدم session_name('admin_session') منفصلة عن جلسة المستخدم العادي.
 */
class AdminAuthController extends Controller
{
    // ════════════════════════════════════════════════════════
    // GET /admin/login — عرض صفحة تسجيل الدخول
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/admin/login',
        summary: 'عرض صفحة تسجيل دخول الأدمن',
        tags: ['Admin Auth'],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML لتسجيل الدخول')
        ]
    )]
    public function showLogin(): void
    {
        startAdminSession();

        // لو الأدمن مسجّل دخول أصلاً وجه لـ admin/home
        if (!empty($_SESSION['admin_id'])) {
            header('Location: ' . URLROOT . '/admin/home');
            exit;
        }

        // منع التخزين المؤقت لصفحة تسجيل الدخول
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $this->view('admin/login', [], 'bare');
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/login — معالجة تسجيل الدخول
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/login',
        summary: 'تسجيل دخول الأدمن',
        tags: ['Admin Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['email', 'password', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'email',              type: 'string', format: 'email',    description: 'البريد الإلكتروني للأدمن'),
                        new OA\Property(property: 'password',           type: 'string', format: 'password', description: 'كلمة المرور'),
                        new OA\Property(property: 'csrf_token',         type: 'string', description: 'CSRF token — مطلوب دائماً'),
                        new OA\Property(property: 'h-captcha-response', type: 'string', description: 'hCaptcha response — مطلوب بعد أول محاولة فاشلة'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'نجاح أو فشل تسجيل الدخول',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success',         type: 'boolean'),
                        new OA\Property(property: 'message',         type: 'string'),
                        new OA\Property(property: 'redirect',        type: 'string',  description: 'موجود عند النجاح فقط'),
                        new OA\Property(property: 'show_captcha',    type: 'boolean', description: 'موجود عند الفشل — يعني يجب إظهار hCaptcha'),
                        new OA\Property(property: 'failed_attempts', type: 'integer', description: 'عدد المحاولات الفاشلة'),
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
            unset($_SESSION['csrf_token']);
            generateCsrfToken();
            $this->respond(false, 'Method not allowed.');
        }

        // CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            unset($_SESSION['csrf_token']);
            generateCsrfToken();
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

        $email = trim(strtolower($_POST['email']    ?? ''));
        $pass  = $_POST['password'] ?? '';

        if (!$email || !$pass) {
            unset($_SESSION['csrf_token']);
            generateCsrfToken();
            $this->respond(false, 'Please enter your email and password.');
        }

        // ── Rate Limiting (3 محاولات / 30 دقيقة) ────────────────
        if (AdminModel::isRateLimited($email)) {
            unset($_SESSION['csrf_token']);
            generateCsrfToken();
            $this->respond(false, 'Too many failed attempts. Access is locked for 30 minutes.');
        }

        // ── CAPTCHA (بعد أول محاولة فاشلة) ──────────────────────
        $failedAttempts = AdminModel::getFailedAttempts($email);
        if ($failedAttempts >= 1) {
            $captchaResponse = $_POST['h-captcha-response'] ?? '';
            if (!$this->verifyCaptcha($captchaResponse)) {
                unset($_SESSION['csrf_token']);
                generateCsrfToken();
                $this->respond(false, 'Captcha verification failed. Please try again.');
            }
        }

        // ── التحقق من الأدمن (جدول admins فقط) ──────────────────
        $admin = AdminModel::findByEmail($email);

        if ($admin && password_verify($pass, $admin['password'])) {

            AdminModel::logLoginAttempt($email, true);

            // ── 2FA (TOTP) — خطوة ثانية اختيارية لكل أدمن ────────────
            // بعد نجاح كلمة المرور مباشرةً: لا نفتح الجلسة الكاملة بعد،
            // نخزّن الـ id بجلسة مؤقتة "pending" حتى يدخل الكود الصحيح.
            if (!empty($admin['totp_enabled']) && !empty($admin['totp_secret'])) {
                $_SESSION['pending_2fa_admin_id'] = (int)$admin['id'];
                unset($_SESSION['csrf_token']);
                generateCsrfToken();
                $this->respond(true, 'Enter your 2FA code.', ['requires_2fa' => true]);
            }
            // إذا كانت 2FA غير مفعّلة نكمل بفتح الجلسة العادي أسفل هذا السطر

            session_regenerate_id(true);

            $_SESSION['admin_id']    = (int)$admin['id'];
            $_SESSION['admin_name']  = $admin['full_name'] ?? $admin['name'] ?? 'Admin';
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_role']  = $admin['role'] ?? 'B';
            $_SESSION['last_active'] = time();

            // تحميل صلاحيات الأدمن وحفظها بالجلسة (نظام A/B/C/D)
            loadAdminPermissions((int)$admin['id']);

            AdminModel::updateActivity((int)$admin['id']);

            // تسجيل عملية الدخول بـ audit log
            AdminModel::logAction((int)$admin['id'], 'login');

            // [EMAIL_ALERT_HOOK] — هنا سيُضاف إرسال إيميل تنبيه عند IP/جهاز جديد

            // إرسال إيميل تنبيه دخول للأدمن
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $time = date('Y-m-d H:i:s');

            \App\Core\Mailer::send(
                $admin['email'],
                $admin['full_name'] ?? 'Admin',
                'تسجيل دخول جديد لحسابك',
                \App\Core\Mailer::template('تسجيل دخول جديد', "
                    تم تسجيل دخول جديد لحساب الأدمن الخاص بك.<br><br>
                    <b>الوقت:</b> {$time}<br>
                    <b>عنوان IP:</b> {$ip}<br>
                    <b>الجهاز/المتصفح:</b> {$ua}<br><br>
                    إذا لم تكن أنت، غيّر كلمة المرور فورًا وتواصل مع الدعم.
                ")
            );

            unset($_SESSION['csrf_token']);
            generateCsrfToken();
            $this->respond(true, 'Welcome, ' . $_SESSION['admin_name'], [
                'redirect' => URLROOT . '/admin/home',
            ]);
        }

        // فاشل
        AdminModel::logLoginAttempt($email, false);

        // إعادة حساب المحاولات بعد التسجيل
        $attemptsNow = AdminModel::getFailedAttempts($email);

        if ($attemptsNow === 3) {
            $adminRow = AdminModel::findByEmail($email);
            if ($adminRow) {
                \App\Core\Mailer::send(
                    $adminRow['email'],
                    $adminRow['full_name'] ?? 'Admin',
                    'تنبيه: محاولات دخول فاشلة متكررة',
                    \App\Core\Mailer::template('تنبيه أمني', "
                        تم رصد 3 محاولات دخول فاشلة متتالية على حسابك.<br>
                        تم قفل الدخول مؤقتًا لمدة 30 دقيقة كإجراء حماية.<br><br>
                        إذا لم تكن أنت من حاول الدخول، ننصحك بتغيير كلمة المرور فورًا.
                    ")
                );
            }
        }

        unset($_SESSION['csrf_token']);
        generateCsrfToken();
        $this->respond(false, 'Email or password is incorrect.', [
            'show_captcha'    => $attemptsNow >= 1,
            'failed_attempts' => $attemptsNow,
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/login/2fa — التحقق من كود TOTP بعد نجاح كلمة المرور
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/login/2fa',
        summary: 'الخطوة الثانية من دخول الأدمن — التحقق من رمز TOTP',
        description: 'تُستدعى بعد أن يُرجع /admin/login الحقل requires_2fa. '
                   . 'الرمز من تطبيق مصادقة (Google Authenticator أو ما يماثله).',
        tags: ['Admin Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['code', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'code', type: 'string', description: 'رمز TOTP من ستة أرقام'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message, redirect?}')]
    )]
    public function verify2FALogin(): void
    {
        startAdminSession();

        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            unset($_SESSION['csrf_token']);
            generateCsrfToken();
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

        $pendingId = (int)($_SESSION['pending_2fa_admin_id'] ?? 0);
        if ($pendingId <= 0) {
            unset($_SESSION['csrf_token']);
            generateCsrfToken();
            $this->respond(false, 'Session expired. Please log in again.');
        }

        $admin = AdminModel::findById($pendingId);
        if (!$admin || empty($admin['totp_enabled']) || empty($admin['totp_secret'])) {
            unset($_SESSION['pending_2fa_admin_id']);
            $this->respond(false, '2FA is not enabled for this account. Please log in again.');
        }

        $code = $_POST['code'] ?? '';
        if (!\App\Core\Totp::verifyCode($admin['totp_secret'], $code)) {
            $this->respond(false, 'Invalid code. Please try again.');
        }

        // نجاح — فتح الجلسة الكاملة (نفس الكود المستخدم بعد نجاح كلمة المرور)
        session_regenerate_id(true);

        $_SESSION['admin_id']    = (int)$admin['id'];
        $_SESSION['admin_name']  = $admin['full_name'] ?? $admin['name'] ?? 'Admin';
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_role']  = $admin['role'] ?? 'B';
        $_SESSION['last_active'] = time();

        loadAdminPermissions((int)$admin['id']);
        AdminModel::updateActivity((int)$admin['id']);
        AdminModel::logAction((int)$admin['id'], 'login');

        // إرسال إيميل تنبيه دخول للأدمن
        $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $time = date('Y-m-d H:i:s');

        \App\Core\Mailer::send(
            $admin['email'],
            $admin['full_name'] ?? 'Admin',
            'تسجيل دخول جديد لحسابك',
            \App\Core\Mailer::template('تسجيل دخول جديد', "
                تم تسجيل دخول جديد لحساب الأدمن الخاص بك.<br><br>
                <b>الوقت:</b> {$time}<br>
                <b>عنوان IP:</b> {$ip}<br>
                <b>الجهاز/المتصفح:</b> {$ua}<br><br>
                إذا لم تكن أنت، غيّر كلمة المرور فورًا وتواصل مع الدعم.
            ")
        );

        unset($_SESSION['pending_2fa_admin_id']);
        unset($_SESSION['csrf_token']);
        generateCsrfToken();
        $this->respond(true, 'Welcome, ' . $_SESSION['admin_name'], [
            'redirect' => URLROOT . '/admin/home',
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/logout — تسجيل خروج الأدمن فقط
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/logout',
        summary: 'تسجيل خروج الأدمن',
        tags: ['Admin Auth'],
        responses: [
            new OA\Response(response: 302, description: 'إعادة توجيه لـ /admin/login بعد تدمير الجلسة')
        ]
    )]
    public function logout(): void
    {
        startAdminSession();

        // تدمير جلسة الأدمن فقط — لا تلمس جلسة المستخدم العادي (PHPSESSID)
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();

        header('Location: ' . URLROOT . '/admin/login');
        exit;
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/forgot — طلب رابط إعادة تعيين كلمة مرور الأدمن
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/forgot',
        summary: 'طلب رابط إعادة تعيين كلمة مرور الأدمن',
        description: 'الرسالة المُرجَعة واحدة سواء وُجد البريد أم لا — كي لا تكشف النقطة '
                   . 'أي البُرد مسجَّلة كأدمن.',
        tags: ['Admin Auth'],
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
    public function forgotPassword(): void
    {
        startAdminSession();

        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            unset($_SESSION['csrf_token']);
            generateCsrfToken();
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

        $email = trim(strtolower($_POST['email'] ?? ''));
        $admin = AdminModel::findByEmail($email);

        if ($admin) {
            $resetToken = AdminModel::createPasswordReset($email, 'admin');
            if ($resetToken) {
                $resetLink = URLROOT . '/auth/reset?token=' . $resetToken . '&email=' . urlencode($email) . '&type=admin';
                \App\Core\Mailer::send(
                    $admin['email'],
                    $admin['full_name'] ?? 'Admin',
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

        // نُظهر نجاح دائمًا لمنع تعداد إيميلات الأدمن المسجّلة
        $this->respond(true, 'If this email is registered, you will receive a reset link shortly.');
    }

    // ════════════════════════════════════════════════════════
    // POST /admin/store-mode/enter — دخول وضع تصفح المتجر كزائر
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/store-mode/enter',
        summary: 'دخول الأدمن لوضع تصفح المتجر كزائر (Store Mode)',
        tags: ['Admin Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['csrf_token'],
                    properties: [
                        new OA\Property(property: 'csrf_token', type: 'string', description: 'CSRF token — مطلوب دائماً'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 302, description: 'إعادة توجيه للمتجر / بعد تعيين وضع المتجر'),
            new OA\Response(response: 401, description: 'غير مسجّل دخول (AJAX/POST)')
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
        if (!verifyCsrfToken($token)) {
            header('Location: ' . URLROOT . '/admin/home');
            exit;
        }

        // علم Store Mode في جلسة الأدمن (لأي فحص مستقبلي داخل اللوحة)
        $_SESSION['admin_in_store_mode'] = true;

        // ⚠️ جلستا الأدمن (admin_session) والزائر (PHPSESSID) منفصلتان تماماً،
        // والمتجر يقرأ علم Store Mode فقط من جلسة الزائر (PHPSESSID).
        // لذلك تُكتب نسخة العلم في كلتيهما بتبديل مأمون لاسم الجلسة.
        session_write_close();

        session_name('PHPSESSID');
        session_start();
        $_SESSION['admin_in_store_mode'] = true;
        session_write_close();

        // استعادة سياق جلسة الأدمن للرد الحالي
        session_name('admin_session');
        session_start();

        header('Location: ' . URLROOT . '/');
        exit;
    }

    // ════════════════════════════════════════════════════════
    // GET /admin/store-mode/reauth — صفحة إعادة التحقق بكلمة السر
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/admin/store-mode/reauth',
        summary: 'عرض صفحة إعادة التحقق بكلمة السر قبل الرجوع للوحة',
        tags: ['Admin Auth'],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML مستقلة لإعادة التحقق'),
            new OA\Response(response: 302, description: 'إعادة توجيه لـ /admin/home إن لم يكن الأدمن في وضع المتجر')
        ]
    )]
    public function showReauth(): void
    {
        startAdminSession();

        if (!isAdmin()) {
            header('Location: ' . URLROOT . '/admin/login');
            exit;
        }

        // إن لم يكن الأدمن في وضع المتجر — لا حاجة لهذه الصفحة أصلاً
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
    // POST /admin/store-mode/reauth — إعادة التحقق بكلمة السر (JSON)
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/admin/store-mode/reauth',
        summary: 'إعادة تحقق الأدمن بكلمة السر قبل الرجوع للوحة (JSON)',
        tags: ['Admin Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['password', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'password',   type: 'string', format: 'password', description: 'كلمة مرور الأدمن'),
                        new OA\Property(property: 'csrf_token', type: 'string', description: 'CSRF token — مطلوب دائماً'),
                        new OA\Property(property: 'return',      type: 'string', description: 'وجهة العودة (اختياري) — تُحمى ضد Open Redirect'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'نجاح أو فشل التحقق',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success',  type: 'boolean'),
                        new OA\Property(property: 'message',  type: 'string'),
                        new OA\Property(property: 'redirect', type: 'string', description: 'موجود عند النجاح فقط'),
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
        if (!verifyCsrfToken($token)) {
            unset($_SESSION['csrf_token']);
            generateCsrfToken();
            $this->respond(false, 'Invalid CSRF token, please try again.', [
                'csrf_token' => $_SESSION['csrf_token'],
            ]);
        }

        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $pass    = $_POST['password'] ?? '';

        if ($adminId <= 0 || $pass === '') {
            $this->respond(false, 'Please enter your password.');
        }

        if (!AdminModel::verifyPassword($adminId, $pass)) {
            unset($_SESSION['csrf_token']);
            generateCsrfToken();
            AdminModel::logAction($adminId, 'store_mode_reauth_failed');
            $this->respond(false, 'Incorrect password.', [
                'csrf_token' => $_SESSION['csrf_token'],
            ]);
        }

        // نجاح — إزالة وضع المتجر من جلستي الأدمن والزائر معاً
        unset($_SESSION['admin_in_store_mode']);
        session_write_close();

        session_name('PHPSESSID');
        session_start();
        unset($_SESSION['admin_in_store_mode']);
        session_write_close();

        session_name('admin_session');
        session_start();

        AdminModel::logAction($adminId, 'store_mode_exit');

        $this->respond(true, 'Welcome back, ' . ($_SESSION['admin_name'] ?? 'Admin'), [
            'redirect' => $this->safeAdminReturn($_POST['return'] ?? ''),
        ]);
    }

    // ════════════════════════════════════════════════════════
    // GET /admin/csrf — توليد CSRF Token جديد لفورم الأدمن
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/admin/csrf',
        summary: 'توليد CSRF token جديد لفورم الأدمن',
        tags: ['Admin Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'توكن CSRF جديد',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'token',   type: 'string',  description: 'CSRF token hex string'),
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
    // Helpers خاصة
    // ════════════════════════════════════════════════════════

    /**
     * التحقق من hCaptcha server-side
     */
    private function verifyCaptcha(string $captchaResponse): bool
    {
        $secretKey = trim($_ENV['HCAPTCHA_SECRET_KEY'] ?? '');

        // قيمة placeholder (مثل YOUR_HCAPTCHA_SECRET_KEY_HERE) تُعامل كأنها غير
        // مضبوطة. بدون هذا الفحص كانت empty() تمرّ لأن النص غير فارغ، فيُرسَل
        // الـ placeholder إلى hCaptcha ويُرفض دائمًا — أي فشل دائم بلا سبب ظاهر
        // بدل تفعيل تجاوز التطوير المقصود أدناه.
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
            // يحدث عند انقطاع الإنترنت، أو حجب HTTPS الصادر، أو allow_url_fopen=Off
            error_log("AdminAuthController: hCaptcha siteverify request failed (network/allow_url_fopen?)");
            return false;
        }

        $data = json_decode($result, true);

        // hCaptcha يرجّع error-codes تشرح سبب الرفض (مثل invalid-or-already-seen-response
        // أو sitekey-secret-mismatch). بدون تسجيلها يصبح أي فشل صامتًا تمامًا.
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
     * يحمي معامل العودة من Open Redirect في صفحات Store Mode:
     * يقبل فقط وجهة داخلية تبدأ بـ URLROOT.'/admin/' أو نسبية /admin/.
     * أي قيمة خارج هذا النطاق تُستبدل بوجهة افتراضية آمنة.
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

        // صيغة نسبية مقبولة — تُحوّل لرابط كامل داخل اللوحة فقط
        if (str_starts_with($return, '/admin/')) {
            return URLROOT . $return;
        }

        return URLROOT . '/admin/home';
    }

    // حُذفت renderStandaloneView() هنا: كانت تعرض view بلا layout لأن
    // view() المورثة كانت تفرض head+navbar+footer. صار ذلك خياراً في
    // الكلاس الأب — $this->view($path, $data, 'bare') — فلم يعد لنسخة
    // محلية معنى. الفروق التي كسبناها بالحذف: فحص الوجود يسبق أي إخراج،
    // وصفحة 404 حقيقية بدل "View not found: {$viewPath}" النصية.
}
