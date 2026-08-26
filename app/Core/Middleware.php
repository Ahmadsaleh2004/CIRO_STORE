<?php

namespace App\Core;

/**
 * Middleware — حماية المسارات التي تتطلب تسجيل دخول
 */
class Middleware
{
    /**
     * يتحقق من أن المستخدم مسجّل دخوله.
     * إذا لم يكن مسجّلاً، يحفظ الرابط المطلوب ويوجّهه لصفحة Login.
     */
    public static function requireLogin(): void
    {
        if (!isUserLoggedIn()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? URLROOT;
            header('Location: ' . URLROOT . '/?openLogin=1');
            exit;
        }
    }

    /**
     * يتحقق من أن المستخدم هو أدمن.
     * إذا لم يكن أدمن: يرجع JSON لطلبات AJAX، أو يعيد التوجيه للصفحة الرئيسية.
     */
    public static function requireAdmin(): void
    {
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

        http_response_code(403);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. You do not have permission for this action.',
            ]);
            exit;
        }

        echo '<div style="font-family:sans-serif;text-align:center;padding:60px">'
           . '<h2>403 — Access Denied</h2>'
           . '<a href="' . URLROOT . '/admin/home">← Back</a></div>';
        exit;
    }
}
