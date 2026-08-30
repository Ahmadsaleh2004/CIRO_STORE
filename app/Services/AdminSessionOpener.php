<?php

namespace App\Services;

use App\Core\Mailer;
use App\Core\Throttle;
use App\Models\AdminModel;

/**
 * AdminSessionOpener — everything that happens the moment an admin sign-in completes.
 *
 * It exists because these lines were written **twice, word for word**: in
 * AdminAuthController::login (for an account without two-factor) and in
 * verify2FALogin (after a correct code). The comment above the second said so
 * outright: "the same code used after the password succeeds".
 *
 * Duplication is more dangerous here than anywhere else, because what gets forgotten
 * in one of the two copies does not surface as an error but as a gap: a sign-in path
 * that does not rotate the session id, or does not load the permissions, or does not
 * write to the audit log. All three are silent.
 *
 * And it nearly happened in this very round: clearing the throttle counter was added
 * to both paths by hand, and forgetting one of them would have been enough to make
 * anyone signing in through two-factor pay for their attempts the next time.
 *
 * ⚠️ The service knows nothing about HTTP: it does not respond, does not end the
 * request, and does not touch the CSRF token. The controller stays the owner of the
 * response; this opens the session and nothing more.
 */
final class AdminSessionOpener
{
    /**
     * Opens a full admin session, records it, and notifies its owner.
     *
     * The order of the steps is deliberate:
     *   1. rotate the session id **before** writing anything into it — otherwise the
     *      identity is written into the old id, which an attacker may know (session
     *      fixation);
     *   2. the permissions after the identity, because they are read by the id;
     *   3. the email last, and through the queue — whoever is signing in does not wait
     *      for it.
     *
     * @param array<string, mixed> $admin The admin row as AdminModel returns it
     */
    public static function open(array $admin): void
    {
        $adminId = (int) $admin['id'];

        session_regenerate_id(true);

        // The token follows the id. regenerate_id keeps the session contents — csrf_token
        // among them — so the token from the admin sign-in page (a public page anyone can
        // reach) stayed valid, character for character, inside a fully privileged admin
        // session. This is the most dangerous place in the project for that inheritance.
        rotateCsrfToken();

        $_SESSION['admin_id']    = $adminId;
        $_SESSION['admin_name']  = $admin['full_name'] ?? $admin['name'] ?? 'Admin';
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_role']  = $admin['role'] ?? 'B';
        $_SESSION['last_active'] = time();

        loadAdminPermissions($adminId);
        AdminModel::updateActivity($adminId);
        AdminModel::logAction($adminId, 'login');

        self::clearThrottleBuckets();
        self::sendLoginAlert($admin);
    }

    /**
     * Clears the throttle counters this sign-in passed through.
     *
     * The sign-in completed, so there is no sense in its owner paying for their failed
     * attempts next time — and whoever we are guarding against never reaches here at all.
     *
     * Always both buckets: somebody signing in without two-factor never touched the 2FA
     * bucket, and clearing it does no harm; whereas forgetting one of them leaves half
     * the trace behind.
     */
    private static function clearThrottleBuckets(): void
    {
        $ip = Throttle::clientIp();
        Throttle::clear('admin-login', $ip);
        Throttle::clear('admin-2fa', $ip);
    }

    /**
     * A new-sign-in alert email.
     *
     * The values are placeholders rather than interpolated text: HTTP_USER_AGENT is a
     * header entirely under the sender's control, and interpolating it directly delivered
     * HTML written by an attacker into the admin's inbox. Mailer::template escapes every
     * placeholder.
     *
     * @param array<string, mixed> $admin
     */
    private static function sendLoginAlert(array $admin): void
    {
        Mailer::queue(
            $admin['email'],
            $admin['full_name'] ?? 'Admin',
            'A new sign-in to your account',
            Mailer::template(
                'New sign-in',
                'A new sign-in to your admin account was recorded.<br><br>'
                . '<b>Time:</b> {time}<br>'
                . '<b>IP address:</b> {ip}<br>'
                . '<b>Device / browser:</b> {ua}<br><br>'
                . 'If this was not you, change your password immediately and contact support.',
                self::requestFingerprint()
            )
        );
    }

    /**
     * The request's fingerprint: the time, the IP address and the browser.
     *
     * @return array<string, string>
     */
    private static function requestFingerprint(): array
    {
        return [
            'time' => date('Y-m-d H:i:s'),
            'ip'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ];
    }
}
