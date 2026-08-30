<?php

namespace App\Core;

/**
 * Middleware — guarding the routes that require a sign-in.
 */
class Middleware
{
    /**
     * Verifies the user is signed in.
     *
     * It returns JSON for AJAX/POST requests, or redirects to the sign-in page for
     * full page requests — exactly as requireAdmin has done from the start.
     *
     * ⚠️ That distinction did not exist, and it was a latent fault that never
     * surfaced: the function was called from **inside** action bodies, that is, after
     * beginJsonPost had already set the JSON header, answered a CSRF failure and ended
     * the request. So nothing ever reached the redirect line from a JSON endpoint.
     *
     * The moment the guarding moved to the route definition, the fault appeared at
     * once: the guard now precedes the controller, so five JSON endpoints (/checkout,
     * /user/info and their siblings) began answering a signed-out user with a 302
     * redirect to an HTML page — and fetch in the browser follows it and tries to read
     * a full page as JSON.
     *
     * The CSRF contract test caught the regression, which is what it was written for.
     *
     * And the outcome is more correct than before the move as well: a signed-out user
     * used to receive "invalid CSRF token" — a message describing a symptom rather than
     * a cause. Now they receive a 401 telling them they need to sign in.
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
     * Does this request expect a JSON response?
     *
     * This check was copied verbatim into requireAdmin and denyAccess in two slightly
     * different wordings — one of them inspecting Accept and the other not. Unifying it
     * here stops two guards behaving differently in front of the same request.
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
     * Verifies the user is an admin.
     * If not: returns JSON for AJAX requests, or redirects to the home page.
     */
    public static function requireAdmin(): void
    {
        // ⚠️ Starting the session here is **required**, not a precaution.
        //
        // isAdmin() returns false unless an admin_session is active under that name —
        // the presence of an admin_id is not enough. And this function used to assume
        // somebody had started it beforehand; the only thing that did was
        // AdminController::__construct.
        //
        // That worked as long as the guard was called from **inside** the action body,
        // that is, after the controller was constructed. The moment guarding moved to
        // the route definition — which is the correct move, since the guard then comes
        // before the constructor rather than after — the order would have inverted:
        // isAdmin() asked before the session exists, always returning false, turning
        // **every** admin panel page into an endless redirect to the sign-in page.
        //
        // startAdminSession() guards itself with session_status(), so calling it here
        // and then again in the constructor does not do anything twice.
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
     * Verifies the admin is signed in first, then checks a specific permission.
     * Rank A (super admin) always bypasses the permission check.
     * Used as the first line in any admin controller needing a specific permission:
     *   Middleware::requirePermission('can_manage_products');
     */
    public static function requirePermission(string $perm): void
    {
        self::requireAdmin();

        // There used to be a require_once for auth_helper.php here — redundant: the
        // helpers are all loaded from composer's autoload.files before any route starts.
        if (!hasPermission($perm)) {
            self::denyAccess();
        }
    }

    /**
     * Verifies the current admin is root — that is, rank A.
     *
     * It exists because the project carried **three** competing definitions of "root":
     *
     *   1. BackupController  → getCurrentAdminId() !== 1   (root = id 1)
     *   2. AdminModel::getRootAdminId()  → WHERE role='A'  (root = the first A row)
     *   3. AdminModel::canManageTarget() → the rank hierarchy (A above everyone)
     *
     * And they can disagree. Most dangerously, the first ties the right to download
     * the entire database to a **position** in the id sequence rather than to a person —
     * while deleteAdmin renumbered ids on every delete. Which means deleting a row was
     * enough to move that right to somebody else, silently.
     *
     * The definition here is singular and matches what the code calls it elsewhere:
     * "root admin (role 'A')". The rank is read from the session rather than the
     * database — loadAdminPermissions puts it there at sign-in, so there is no query on
     * every request.
     */
    public static function requireRoot(): void
    {
        self::requireAdmin();

        // The existing isRoleA() in auth_helper, not a new copy of it: two names for one
        // concept is exactly what produced the three competing definitions this phase is
        // resolving.
        if (!isRoleA()) {
            self::denyAccess();
        }
    }

    /**
     * Throttles an entry point: refuses with a 429 when the source exceeds the
     * allowance within the window.
     *
     * The guard records the attempt **before** the controller runs, meaning it counts
     * requests rather than failures. That is a fundamental difference from the existing
     * isRateLimited: that one counts failed sign-in attempts, so it does not see
     * somebody calling /auth/forgot a thousand times at all — every one of those is
     * "successful" from its point of view, while being a thousand emails.
     *
     * Counting before execution also means the guard works even when the action ends
     * in an early exit, which is what most JSON endpoints here do.
     *
     * @param string $bucket        The bucket name — it separates one endpoint's counter from another's
     * @param int    $max           The most requests allowed within the window
     * @param int    $windowMinutes The window length in minutes
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
     * Refuses a throttled request: JSON for AJAX endpoints, and a complete 429 page
     * for page requests.
     *
     * The distinction follows the same expectsJson() that requireLogin uses — not a
     * third check in a fourth wording, which is exactly what that function unified.
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
            'Throttled bucket [' . $bucket . '] from ' . Throttle::clientIp()
        );
    }

    /**
     * Returns JSON if the request is AJAX or POST, or a normal HTML page for a full
     * page request. Called only when a permission is refused (403).
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

        // There used to be a bare <div> here with no <!DOCTYPE>, no <head> and no
        // layout — precisely the pattern ErrorPage exists to end ("the single renderer
        // for error pages"), but this site had stayed outside it.
        //
        // The back destination is the admin panel rather than the site root: nothing
        // reaches this line except through requireAdmin() above, so the visitor is
        // certainly an admin — and dropping them into the store front loses them.
        ErrorPage::forbidden(
            'Authorisation refused at ' . ($_SERVER['REQUEST_URI'] ?? '?') . ' for admin #' . (getCurrentAdminId() ?? 0),
            URLROOT . '/admin/home',
            'Back to dashboard'
        );
    }
}
