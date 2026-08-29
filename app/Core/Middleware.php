<?php

namespace App\Core;

/**
 * Middleware — حماية المسارات التي تتطلب تسجيل دخول
 */
class Middleware
{
    /**
     * يتحقق من أن المستخدم مسجّل دخوله.
     *
     * يرجع JSON لطلبات AJAX/POST، أو يحوّل لصفحة الدخول لطلبات الصفحات
     * الكاملة — تماماً كما تفعل requireAdmin منذ البداية.
     *
     * ⚠️ التفريق بين الشكلين لم يكن موجوداً، وكان عطلاً كامناً لا يظهر:
     * الدالة كانت تُستدعى من **داخل** أجسام الأفعال، أي بعد أن تكون
     * beginJsonPost قد ضبطت رأس JSON وردّت على فشل CSRF وأنهت الطلب.
     * فلم يكن أحد يبلغ سطر التحويل من نقطة JSON أصلاً.
     *
     * ولحظة نقل الحراسة إلى تعريف المسار ظهر العطل فوراً: صار الحارس
     * يسبق الكنترولر، فبدأت خمس نقاط JSON (/checkout و/user/info
     * وأخواتها) تردّ على مستخدم غير مسجّل بتحويل 302 إلى صفحة HTML —
     * وfetch في المتصفح يتبعه ويحاول قراءة صفحة كاملة كـJSON.
     *
     * أمسك الانحدارَ اختبارُ عقد CSRF، وهو ما كُتب له.
     *
     * والنتيجة أصحّ ممّا كان قبل النقل أيضاً: مستخدم غير مسجّل كان
     * يتلقّى «توكن CSRF غير صالح» — رسالة تصف عرضاً لا سبباً. الآن
     * يتلقّى 401 تقول له إن عليه تسجيل الدخول.
     */
    public static function requireLogin(): void
    {
        if (isUserLoggedIn()) {
            return;
        }

        if (self::expectsJson()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Please log in to continue.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? URLROOT;
        header('Location: ' . URLROOT . '/?openLogin=1');
        exit;
    }

    /**
     * هل يتوقّع هذا الطلب استجابة JSON؟
     *
     * كان هذا الفحص منسوخاً حرفياً في requireAdmin و denyAccess بصياغتين
     * مختلفتين قليلاً — إحداهما تفحص Accept والأخرى لا. توحيده هنا يمنع
     * أن يتصرّف حارسان بشكلين مختلفين أمام الطلب نفسه.
     */
    private static function expectsJson(): bool
    {
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $accept        = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType   = $_SERVER['CONTENT_TYPE'] ?? '';

        return strtolower($requestedWith) === 'xmlhttprequest'
            || str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * يتحقق من أن المستخدم هو أدمن.
     * إذا لم يكن أدمن: يرجع JSON لطلبات AJAX، أو يعيد التوجيه للصفحة الرئيسية.
     */
    public static function requireAdmin(): void
    {
        // ⚠️ بدء الجلسة هنا **لازم**، وليس احتياطاً.
        //
        // isAdmin() تُرجع false ما لم تكن جلسة admin_session نشطة
        // بالاسم — لا يكفي وجود admin_id. وكانت هذه الدالة تفترض أن
        // أحداً بدأها قبلها، والفاعل الوحيد هو
        // AdminController::__construct.
        //
        // ذلك كان يعمل ما دام الحارس يُستدعى من **داخل** جسم الفعل، أي
        // بعد بناء الكنترولر. ولحظة نقل الحراسة إلى تعريف المسار — وهي
        // النقلة الصحيحة، إذ يصير الحارس قبل الباني لا بعده — كان
        // الترتيب سينقلب: يُسأل isAdmin() قبل أن توجد الجلسة، فتُرجع
        // false دائماً، فتتحوّل **كل** صفحات لوحة التحكم إلى إعادة
        // توجيه أبدية إلى صفحة الدخول.
        //
        // startAdminSession() تحرس نفسها بـsession_status()، فاستدعاؤها
        // هنا ثم في الباني لا يفعل شيئاً مرّتين.
        startAdminSession();

        if (!isAdmin()) {
            $isAjax = (
                (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || ($_SERVER['REQUEST_METHOD'] === 'POST')
            );

            if ($isAjax) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Session expired. Please log in again.',
                ]);
                exit;
            }

            header('Location: ' . URLROOT);
            exit;
        }
    }

    /**
     * يتحقق من أن الأدمن مسجّل دخوله أولاً، ثم يتحقق من صلاحية محددة.
     * رتبة A (Super Admin) تتجاوز التحقق من الصلاحية دائماً.
     * يُستخدم كأول سطر في أي Admin Controller يحتاج صلاحية محددة:
     *   Middleware::requirePermission('can_manage_products');
     */
    public static function requirePermission(string $perm): void
    {
        self::requireAdmin();

        // كان هنا require_once لـauth_helper.php — زائد: الهيلبرز تُحمَّل
        // كلها من composer autoload.files قبل أن يبدأ أي راوت.
        if (!hasPermission($perm)) {
            self::denyAccess();
        }
    }

    /**
     * يتحقق من أن الأدمن الحالي هو الروت — أي رتبة A.
     *
     * وُجدت لأن المشروع كان يحمل **ثلاثة** تعريفات متنافسة لـ«الروت»:
     *
     *   1. BackupController  → getCurrentAdminId() !== 1   (الروت = المعرّف 1)
     *   2. AdminModel::getRootAdminId()  → WHERE role='A'  (الروت = أوّل صفّ A)
     *   3. AdminModel::canManageTarget() → هرم الرتب        (A أعلى الجميع)
     *
     * وهي قد تتخالف. الأخطر أن الأول يربط حقّ تنزيل قاعدة البيانات
     * كاملةً بـ**موضع** في طابور المعرّفات لا بشخص — بينما deleteAdmin
     * كانت تزحف بالمعرّفات عند كل حذف. أي أن حذف صفّ كان كفيلاً بأن
     * ينقل الحقّ إلى شخص آخر بصمت.
     *
     * التعريف هنا واحد ويطابق ما يسمّيه الكود نفسه في مواضعه:
     * «root admin (role 'A')». والرتبة تُقرأ من الجلسة لا من القاعدة —
     * loadAdminPermissions تضعها عند الدخول، فلا استعلام في كل طلب.
     */
    public static function requireRoot(): void
    {
        self::requireAdmin();

        // isRoleA() الموجودة في auth_helper لا نسخة جديدة منها: اسمان
        // لمفهوم واحد هما بالضبط ما أنتج التعريفات الثلاثة المتنافسة
        // التي تحلّها هذه المرحلة.
        if (!isRoleA()) {
            self::denyAccess();
        }
    }

    /**
     * يخنق نقطة دخول: يرفض بـ429 حين يتجاوز المصدر الحدَّ خلال النافذة.
     *
     * الحارس يسجّل المحاولة **قبل** أن ينفّذ الكنترولر، أي أنه يعدّ
     * الطلبات لا الإخفاقات. هذا فرق جوهري عن isRateLimited القائمة:
     * تلك تعدّ محاولات الدخول الفاشلة، فلا ترى أصلاً من يستدعي
     * /auth/forgot ألف مرّة — كل استدعاء منها «ناجح» من زاويتها بينما
     * هو ألف رسالة بريد.
     *
     * والعدّ قبل التنفيذ يجعل الحارس يعمل حتى لو انتهى الفعل بـexit
     * مبكّر، وهو ما تفعله معظم نقاط JSON هنا.
     *
     * @param string $bucket        اسم الدلو — يفصل عدّاد نقطة عن أخرى
     * @param int    $max           أقصى عدد طلبات مسموح خلال النافذة
     * @param int    $windowMinutes طول النافذة بالدقائق
     */
    public static function throttle(string $bucket, int $max, int $windowMinutes): void
    {
        $identifier = Throttle::clientIp();

        if (Throttle::tooMany($bucket, $identifier, $max, $windowMinutes)) {
            self::denyThrottled($bucket, $windowMinutes);
        }

        Throttle::record($bucket, $identifier);
    }

    /**
     * يرفض الطلب المخنوق: JSON لنقاط الـAJAX، وصفحة 429 كاملة للصفحات.
     *
     * التفريق يتبع expectsJson() نفسها التي يستعملها requireLogin — لا
     * فحصاً ثالثاً بصياغة رابعة، فقد كان ذلك بالضبط ما وحّدته تلك الدالة.
     */
    private static function denyThrottled(string $bucket, int $windowMinutes): void
    {
        $retryAfter = $windowMinutes * 60;

        if (self::expectsJson()) {
            if (!headers_sent()) {
                http_response_code(429);
                header('Retry-After: ' . $retryAfter);
                header('Cache-Control: no-store');
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Too many requests in a short time. Please wait a moment and try again.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        ErrorPage::tooManyRequests(
            $retryAfter,
            'خنق الدلو [' . $bucket . '] من ' . Throttle::clientIp()
        );
    }

    /**
     * يرجع JSON لو الطلب AJAX أو POST، أو صفحة HTML عادية لو طلب صفحة كامل.
     * يُستدعى فقط عند رفض الصلاحية (403).
     */
    private static function denyAccess(): void
    {
        $isAjax = (
            (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (!empty($_SERVER['HTTP_ACCEPT'])
                && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
            || ($_SERVER['REQUEST_METHOD'] === 'POST')
        );

        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. You do not have permission for this action.',
            ]);
            exit;
        }

        // كان هنا <div> خام بلا <!DOCTYPE> ولا <head> ولا لايوت — وهو
        // بالضبط النمط الذي وُجد ErrorPage ليُنهيه («المُصيّر الوحيد
        // لصفحات الخطأ»)، لكن هذا الموضع بقي خارجه.
        //
        // وجهة الرجوع لوحة التحكم لا جذر الموقع: لا يصل هذا السطر إلا
        // من عبر requireAdmin() أعلاه، أي أن الزائر أدمن بالتأكيد —
        // وإلقاؤه في واجهة المتجر يضيّعه.
        ErrorPage::forbidden(
            'تخويل مرفوض على ' . ($_SERVER['REQUEST_URI'] ?? '?') . ' لأدمن #' . (getCurrentAdminId() ?? 0),
            URLROOT . '/admin/home',
            'Back to dashboard'
        );
    }
}
