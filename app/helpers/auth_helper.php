<?php

/**
 * app/helpers/auth_helper.php
 * The core authentication functions used across the views and controllers.
 *
 * An important note: this file does not call session_start() at file level. Every
 * function needing a session starts it itself (startAdminSession, isUserLoggedIn…) or
 * assumes the session was started beforehand in the correct context.
 */

/**
 * Is the current session a regular user's (PHPSESSID)?
 * It does not rely on the absence of admin_id, because the two sessions are separate now.
 */
function isUser(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['user_id']);
}

/**
 * Is an admin signed in?
 * Only valid inside the admin pages' context, after session_name('admin_session').
 * In the regular user context (PHPSESSID) it always returns false, because the two
 * sessions are entirely separate and admin data cannot be reached from PHPSESSID.
 */
function isAdmin(): bool
{
    if (session_name() === 'admin_session' && session_status() === PHP_SESSION_ACTIVE) {
        return isset($_SESSION['admin_id']);
    }
    return false;
}

/** The current user's id from the session, or null. */
function getCurrentUserId(): ?int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Start the separate admin session (admin_session) if it is not already running.
 * It must be called as the first thing in any admin controller or page, before any read
 * from or write to $_SESSION.
 */
function startAdminSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('admin_session');
        session_start();
    }
}

/**
 * Confirms an admin is signed in within an admin_session.
 * If not, it redirects them to the sign-in page and halts.
 * startAdminSession() must be called before this function.
 */
function requireAdminLogin(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . URLROOT . '/admin/login');
        exit;
    }
}

// ════════════════════════════════════════════════════════════════════════════
// The A/B/C/D permission system's functions — section 3
// They only work inside an admin_session context, after startAdminSession() is called
// ════════════════════════════════════════════════════════════════════════════

/** The current admin's rank from the session ('A'|'B'|'C'|'D', or '' when not signed in). */
function getAdminRole(): string
{
    return $_SESSION['admin_role'] ?? '';
}

/** Is the current admin rank A (super admin)? */
function isRoleA(): bool
{
    return getAdminRole() === 'A';
}

/** The current admin's id from the session, or null. */
function getCurrentAdminId(): ?int
{
    return isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
}

/**
 * Load the admin's permissions from the database and store them in the session.
 * Called once after a successful sign-in, or on the first protected request.
 * It relies on the project's autoloader — AdminModel is registered as
 * App\Models\AdminModel.
 */
function loadAdminPermissions(int $adminId): void
{
    $perms = \App\Models\AdminModel::getPermissions($adminId);
    $_SESSION['admin_permissions'] = $perms;
}

/** The current admin's permissions from the session (an empty array if not loaded yet). */
/**
 * @return array<string, mixed>
 */
function getAdminPermissions(): array
{
    return $_SESSION['admin_permissions'] ?? [];
}

/**
 * Does the current admin hold a given permission?
 *
 * hasPermission() is used only for:
 *  (a) conditionally showing or hiding things in a view (navbar.php, for instance);
 *  (b) inside any future AJAX logic.
 * It is never the sole guard on a whole page — for that, use requireAdminPermission()
 * or Middleware::requirePermission() (section 4), which genuinely guard pages.
 */
function hasPermission(string $perm): bool
{
    // Rank A overrides every permission — a super admin always holds everything
    if (isRoleA()) {
        return true;
    }

    $perms = getAdminPermissions();
    return !empty($perms[$perm]);
}

/**
 * A whole-page guard — it checks the sign-in first, then the required permission.
 * It halts with a redirect (401) or a 403 on failure.
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

/**
 * How many seconds must pass between writes to users.last_activity for one user.
 * Fifteen minutes: far more precision than the dashboard's "active within 90 days"
 * indicator needs, at the cost of one write per quarter hour rather than one per page
 * view.
 */
const USER_ACTIVITY_THROTTLE_SECONDS = 900;

/**
 * Updates users.last_activity for the current user, at most once every
 * USER_ACTIVITY_THROTTLE_SECONDS.
 *
 * Why does this function exist?
 * The column used to be updated only at sign-in (AuthController::login), so a user
 * browsing the store daily without signing in again counted as "inactive" after 90 days
 * in AdminDashboardModel::getUsersBreakdown. ProductController held a call to
 * updateUserActivity(), a function that was never defined at all, guarded by
 * function_exists — so it never ran once.
 *
 * Where is it called? From Controller::view() alone — that is, on every store page
 * render. It is not called from the bootstrap, because that would start a PHPSESSID
 * session before startAdminSession() could set session_name('admin_session'), which
 * breaks admin authentication. Nor from AdminController::adminView(), because admin
 * activity is tracked separately in AdminModel.
 *
 * The throttle is stored in the session rather than the database, so it costs no read
 * query.
 */
function touchUserActivity(): void
{
    if (!isUser()) {
        return;
    }

    $now  = time();
    $last = (int)($_SESSION['last_activity_write'] ?? 0);

    if ($now - $last < USER_ACTIVITY_THROTTLE_SECONDS) {
        return;
    }

    $userId = getCurrentUserId();
    if ($userId === null) {
        return;
    }

    \App\Models\UserModel::updateActivity($userId);
    $_SESSION['last_activity_write'] = $now;
}
