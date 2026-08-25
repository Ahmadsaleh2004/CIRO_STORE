<?php
/**
 * app/helpers/auth_helper.php
 * دوال المصادقة الأساسية المستخدمة عبر الـ Views والـ Controllers.
 *
 * ملاحظة مهمة: لا يستدعي هذا الملف session_start() على مستوى الملف.
 * كل دالة تحتاج جلسة تتولى بدءها بنفسها (startAdminSession, isUserLoggedIn...)
 * أو تفترض أن الجلسة بدأت مسبقاً بالسياق الصحيح.
 */

/**
 * هل الجلسة الحالية مستخدم عادي (PHPSESSID)؟
 * لا تعتمد على غياب admin_id لأن الجلستين منفصلتان الآن.
 */
function isUser(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['user_id']);
}

/**
 * هل الأدمن مسجّل دخول؟
 * صالحة فقط في سياق صفحات الأدمن بعد session_name('admin_session').
 * في سياق المستخدم العادي (PHPSESSID) ترجع دائماً false لأن
 * الجلستين منفصلتان تماماً ولا يمكن الوصول لبيانات الأدمن من PHPSESSID.
 */
function isAdmin(): bool
{
    if (session_name() === 'admin_session' && session_status() === PHP_SESSION_ACTIVE) {
        return isset($_SESSION['admin_id']);
    }
    return false;
}

/** معرّف المستخدم الحالي من الجلسة أو null. */
function getCurrentUserId(): ?int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * تشغيل جلسة الأدمن المنفصلة (admin_session) إن لم تكن مُشغّلة أصلاً.
 * يجب استدعاؤها أول شيء في أي Controller/صفحة خاصة بالأدمن قبل أي
 * قراءة أو كتابة على $_SESSION.
 */
function startAdminSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('admin_session');
        session_start();
    }
}

/**
 * يتأكد أن الأدمن مسجّل دخوله ضمن جلسة admin_session.
 * إذا لم يكن كذلك، يوجّهه لصفحة تسجيل الدخول ويوقف التنفيذ.
 * يجب استدعاء startAdminSession() قبل استدعاء هذه الدالة.
 */
function requireAdminLogin(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . URLROOT . '/admin/login');
        exit;
    }
}

// ════════════════════════════════════════════════════════════════════════════
// دوال نظام الصلاحيات A/B/C/D — القسم 3
// تعمل فقط في سياق جلسة admin_session بعد استدعاء startAdminSession()
// ════════════════════════════════════════════════════════════════════════════

/** رتبة الأدمن الحالي من الجلسة ('A'|'B'|'C'|'D' أو '' إذا لم يسجّل دخول). */
function getAdminRole(): string
{
    return $_SESSION['admin_role'] ?? '';
}

/** هل الأدمن الحالي من رتبة A (Super Admin)؟ */
function isRoleA(): bool
{
    return getAdminRole() === 'A';
}

/** معرّف الأدمن الحالي من الجلسة أو null. */
function getCurrentAdminId(): ?int
{
    return isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
}

/**
 * تحميل صلاحيات الأدمن من قاعدة البيانات وتخزينها بالجلسة.
 * يُستدعى مرة واحدة بعد نجاح تسجيل الدخول أو عند أول طلب محمي.
 * يعتمد على autoloader المشروع — AdminModel مُسجَّل بـ App\Models\AdminModel.
 */
function loadAdminPermissions(int $adminId): void
{
    $perms = \App\Models\AdminModel::getPermissions($adminId);
    $_SESSION['admin_permissions'] = $perms;
}

/** صلاحيات الأدمن الحالي من الجلسة (مصفوفة فاضية إذا لم تُحمَّل بعد). */
function getAdminPermissions(): array
{
    return $_SESSION['admin_permissions'] ?? [];
}

/**
 * هل للأدمن الحالي صلاحية معيّنة؟
 *
 * hasPermission() تُستخدم فقط لـ:
 *  (أ) عرض/إخفاء شرطي بالـ View (مثل navbar.php)
 *  (ب) داخل أي منطق AJAX مستقبلي
 * لا تُستخدم أبداً كحارس وحيد لصفحة كاملة — لهيك استخدم requireAdminPermission()
 * أو Middleware::requirePermission() (القسم 4) لحراسة الصفحات فعلياً.
 */
function hasPermission(string $perm): bool
{
    // رتبة A تتجاوز كل الصلاحيات — Super Admin يملك كل شيء دائماً
    if (isRoleA()) {
        return true;
    }

    $perms = getAdminPermissions();
    return !empty($perms[$perm]);
}

/**
 * حارس صفحة كاملة — يتحقق من تسجيل الدخول ثم من الصلاحية المطلوبة.
 * يوقف التنفيذ بـ redirect (401) أو 403 عند الفشل.
 */
function requireAdminPermission(string $perm): void
{
    if (!isAdmin()) {
        header('Location: ' . URLROOT . '/admin/login');
        exit;
    }

    if (!hasPermission($perm)) {
        http_response_code(403);
        echo '<div style="font-family:sans-serif;text-align:center;padding:60px">'
           . '<h2>403 — Access Denied</h2>'
           . '<a href="' . URLROOT . '/admin/home">← Back</a></div>';
        exit;
    }
}
