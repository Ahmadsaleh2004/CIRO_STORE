<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Totp;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

/**
 * AdminMyInfoController — صفحة "معلوماتي" الخاصة بالأدمن نفسه فقط.
 * مستقلة تمامًا عن MyInfoController الخاص باليوزر (Session مختلفة، جدول مختلف،
 * لا يوجد أي استدعاء أو import مشترك بين الاثنين).
 */
class AdminMyInfoController extends AdminController
{
    #[OA\Get(
        path: '/admin/my-info',
        summary: 'عرض صفحة معلوماتي الخاصة بالأدمن',
        tags: ['Admin My Info'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML لمعلومات الأدمن — يتطلب جلسة admin_session صالحة'),
            new OA\Response(response: 302, description: 'إعادة توجيه لـ /admin/login إذا لم تكن الجلسة صالحة'),
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
            'pageTitle' => 'My Info',
            'extraHead' => '<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/my-info.css">',
            'profile'   => $admin,
        ]);
    }

    #[OA\Post(
        path: '/admin/my-info',
        summary: 'تحديث بيانات حساب الأدمن (الاسم / الهاتف / كلمة المرور)',
        tags: ['Admin My Info'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['csrf_token', 'full_name', 'current_password'],
                    properties: [
                        new OA\Property(property: 'csrf_token',       type: 'string'),
                        new OA\Property(property: 'full_name',        type: 'string'),
                        new OA\Property(property: 'phone_number',     type: 'string'),
                        new OA\Property(property: 'new_password',     type: 'string', format: 'password', description: 'اختياري — اتركه فارغًا للإبقاء على كلمة المرور الحالية'),
                        new OA\Property(property: 'current_password', type: 'string', format: 'password', description: 'إلزامي دائمًا للتأكيد قبل الحفظ'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'نجاح أو فشل التحديث',
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
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $post = array_merge($_POST, $body);

        $token = $post['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

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
        // دعم الحقل المركّب (phone_country_code + phone_local) — الفورم الجديد
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
    // التحقق الثنائي (2FA / TOTP) — اختياري لكل أدمن
    // ════════════════════════════════════════════════════════

    /**
     * توليد secret جديد وتخزينه مؤقتًا بالجلسة (لم يُفعَّل بعد) + إرجاع رابط QR.
     */
    public function generate2FASecret(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $token = $post['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

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
     * تأكيد تفعيل 2FA — يتحقق من أول كود TRUE قبل الحفظ (طريقة آمنة).
     */
    public function confirm2FA(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $post = array_merge($_POST, $body);

        $token = $post['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

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
     * تعطيل 2FA — يطلب كلمة المرور الحالية كتأكيد قبل التنفيذ.
     */
    public function disable2FA(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $post = array_merge($_POST, $body);

        $token = $post['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

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
