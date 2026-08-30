<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Totp;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

/**
 * AdminMyInfoController — the "my info" page, for the admin themselves only.
 * Entirely separate from the user-facing MyInfoController: a different session, a
 * different table, and not one shared call or import between the two.
 */
class AdminMyInfoController extends AdminController
{
    #[OA\Get(
        path: '/admin/my-info',
        summary: "Show the admin's own account page",
        tags: ['Admin - My Info'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Admin account HTML page — requires a valid admin_session'),
            new OA\Response(response: 302, description: 'Redirect to /admin/login when the session is not valid'),
        ]
    )]
    public function index(): void
    {
        $adminId = (int)$_SESSION['admin_id'];
        $admin   = AdminModel::findById($adminId);

        if (!$admin) {
            session_destroy();
            header('Location: ' . URLROOT . '/admin/login');
            exit;
        }

        $this->adminView('my-info', [
            'pageTitle'    => 'My Info',
            'extraHead'    => '<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/my-info.css">',
            // This page's own file — the admin footer already loads thirteen scripts on
            // every page, and there is no reason to add a fourteenth that only this one
            // needs.
            'extraScripts' => '<script src="' . URLROOT . '/js/admin/my-info.js"></script>',
            'profile'      => $admin,
        ]);
    }

    #[OA\Post(
        path: '/admin/my-info',
        summary: "Update the admin's account details (name / phone / password)",
        tags: ['Admin - My Info'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['csrf_token', 'full_name', 'current_password'],
                    properties: [
                        new OA\Property(property: 'csrf_token', type: 'string'),
                        new OA\Property(property: 'full_name', type: 'string'),
                        new OA\Property(property: 'phone_number', type: 'string'),
                        new OA\Property(property: 'new_password', type: 'string', format: 'password', description: 'Optional — leave it empty to keep the current password'),
                        new OA\Property(property: 'current_password', type: 'string', format: 'password', description: 'Always required, as confirmation before saving'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Whether the update succeeded',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            )
        ]
    )]
    public function updateProfile(): void
    {
        $this->beginJsonPost();

        $post = $this->requestData();

        $adminId = (int)$_SESSION['admin_id'];
        $admin   = AdminModel::findById($adminId);
        if (!$admin) {
            $this->respond(false, 'Session error, please log in again.');
        }

        $currentPassword = $post['current_password'] ?? '';
        if (!$currentPassword || !password_verify($currentPassword, $admin['password'])) {
            $this->respond(false, 'Current password is incorrect.');
        }

        $fullName = trim($post['full_name'] ?? '');
        if (strlen($fullName) < 2) {
            $this->respond(false, 'Name must be at least 2 characters.');
        }

        $phone = trim($post['phone_number'] ?? '');
        // Support the composite field (phone_country_code + phone_local) — the new form
        if ($phone === '' && isset($post['phone_local'])) {
            $code  = trim($post['phone_country_code'] ?? '');
            $local = trim($post['phone_local'] ?? '');
            $phone = $code . $local;
        }

        $updateData = [
            'full_name'    => $fullName,
            'phone_number' => $phone ?: null,
        ];

        $newPassword = $post['new_password'] ?? '';
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                $this->respond(false, 'New password must be at least 8 characters.');
            }
            $updateData['password'] = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $updated = AdminModel::updateProfile($adminId, $updateData);
        if (!$updated) {
            $this->respond(false, 'Could not update profile. Please try again.');
        }

        $_SESSION['admin_name'] = $fullName;
        AdminModel::logAction($adminId, 'update_profile');

        $this->respond(true, 'Profile updated successfully.');
    }

    // ════════════════════════════════════════════════════════
    // Two-factor authentication (2FA / TOTP) — optional per admin
    // ════════════════════════════════════════════════════════

    /**
     * Generate a new secret, hold it in the session (not yet enabled), and return a QR URL.
     */
    #[OA\Post(
        path: '/admin/my-info/2fa/generate',
        summary: 'Generate a new TOTP secret and QR code for enabling two-factor authentication',
        description: 'This does not enable 2FA yet — enabling happens at /admin/my-info/2fa/confirm '
                   . 'once the authenticator app has proved it produces the right code.',
        tags: ['Admin - My Info'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['csrf_token'],
                    properties: [new OA\Property(property: 'csrf_token', type: 'string')]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'JSON — {success, message, secret, qr}'),
        ]
    )]
    public function generate2FASecret(): void
    {
        // Historical note: merging the JSON body was missing here, and here alone,
        // out of the four methods in this file — so $post was undefined and $token
        // always empty, which meant the "Enable 2FA" button was rejected with
        // "Invalid CSRF token" every single time. The request arrives as a JSON body
        // from js/admin/my-info.js, so it never populates $_POST. That cannot recur
        // now: beginJsonPost reads the token through requestData(), which always
        // merges $_POST with the JSON body.
        $this->beginJsonPost();

        $adminId = (int)$_SESSION['admin_id'];
        $admin   = AdminModel::findById($adminId);
        if (!$admin) {
            $this->respond(false, 'Session error, please log in again.');
        }

        $secret = Totp::generateSecret();
        $_SESSION['pending_2fa_secret'] = $secret;

        $this->respond(true, 'Scan the QR code with your authenticator app.', [
            'qrcode_url' => Totp::getQrCodeUrl($secret, $admin['email']),
            'secret'     => $secret,
        ]);
    }

    /**
     * Confirm enabling 2FA — verifies a first valid code before saving, which is the safe order.
     */
    #[OA\Post(
        path: '/admin/my-info/2fa/confirm',
        summary: 'Confirm and enable two-factor authentication with a code from the app',
        tags: ['Admin - My Info'],
        security: [['adminSessionAuth' => []]],
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
    public function confirm2FA(): void
    {
        $this->beginJsonPost();

        $post = $this->requestData();

        $adminId = (int)$_SESSION['admin_id'];
        $admin   = AdminModel::findById($adminId);
        if (!$admin) {
            $this->respond(false, 'Session error, please log in again.');
        }

        $secret = $_SESSION['pending_2fa_secret'] ?? '';
        if ($secret === '') {
            $this->respond(false, 'No pending 2FA setup found. Please scan the QR code again.');
        }

        $code = $post['code'] ?? '';
        if (!Totp::verifyCode($secret, $code)) {
            $this->respond(false, 'Invalid code. Please try again.');
        }

        if (!AdminModel::enable2FA($adminId, $secret)) {
            $this->respond(false, 'Could not enable 2FA. Please try again.');
        }

        unset($_SESSION['pending_2fa_secret']);
        AdminModel::logAction($adminId, 'enable_2fa');

        $this->respond(true, '2FA enabled successfully. You will need an authentication code on your next login.');
    }

    /**
     * Disable 2FA — asks for the current password as confirmation before acting.
     */
    #[OA\Post(
        path: '/admin/my-info/2fa/disable',
        summary: 'Disable two-factor authentication',
        description: 'Requires the current password — an open session is not enough.',
        tags: ['Admin - My Info'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['password', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'password', type: 'string', format: 'password'),
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
    public function disable2FA(): void
    {
        $this->beginJsonPost();

        $post = $this->requestData();

        $adminId = (int)$_SESSION['admin_id'];
        $admin   = AdminModel::findById($adminId);
        if (!$admin) {
            $this->respond(false, 'Session error, please log in again.');
        }

        $currentPassword = $post['current_password'] ?? '';
        if (!$currentPassword || !password_verify($currentPassword, $admin['password'])) {
            $this->respond(false, 'Current password is incorrect.');
        }

        if (!AdminModel::disable2FA($adminId)) {
            $this->respond(false, 'Could not disable 2FA. Please try again.');
        }

        unset($_SESSION['pending_2fa_secret']);
        AdminModel::logAction($adminId, 'disable_2fa');

        $this->respond(true, '2FA disabled successfully.');
    }
}
