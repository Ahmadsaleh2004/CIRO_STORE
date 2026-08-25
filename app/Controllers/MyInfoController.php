<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Middleware;
use App\Models\UserModel;
use App\Models\OrderModel;
use OpenApi\Attributes as OA;

/**
 * MyInfoController — بيانات المستخدم + طلباته + عناوينه
 * منقول ومُحوَّل من pages/my-info.php القديمة
 * مستقلة تمامًا عن AdminMyInfoController الخاص بالأدمن — لا تشارك Session ولا منطق ولا جدول بيانات.
 */
class MyInfoController extends Controller
{
    // ════════════════════════════════════════════════════════
    // GET /user/info — عرض الصفحة
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/user/info',
        summary: 'صفحة حساب المستخدم — البيانات والطلبات والعناوين',
        tags: ['Store - Account'],
        security: [['userSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML'),
            new OA\Response(response: 302, description: 'تحويل للرئيسية مع فتح نافذة الدخول'),
        ]
    )]
    public function index(): void
    {
        Middleware::requireLogin();

        $userId = (int)$_SESSION['user_id'];
        $user   = UserModel::findById($userId);

        if (!$user) {
            // جلسة فاسدة — سجّل خروج وأعِد التوجيه
            session_destroy();
            header('Location: ' . URLROOT . '/?openLogin=1');
            exit;
        }

        $orders    = OrderModel::getUserOrders($userId);
        $addresses = OrderModel::getUserAddresses($userId);

        $this->view('account/my-info', [
            'title'       => 'My Account',
            'desc'        => 'Manage your account information, orders and addresses.',
            'activePage'  => '',
            'robots'      => 'noindex, nofollow',
            'extraHead'   => '<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/my-info.css">',
            'extraScripts'=> '<script src="' . URLROOT . '/js/features/account.js" defer></script>',
            'user'        => $user,
            'orders'      => $orders,
            'addresses'   => $addresses,
            'csrf'        => generateCsrfToken(),
            'userLoggedIn'=> true,
            'userName'    => $user['full_name'],
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /user/info — تحديث بيانات الملف الشخصي
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/user/info',
        summary: 'تحديث بيانات الحساب',
        description: 'كلمة المرور الحالية إلزامية لأي تعديل، حتى لو لم تكن كلمة المرور '
                   . 'نفسها هي المُعدَّلة.',
        tags: ['Store - Account'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['current_password', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'full_name', type: 'string'),
                        new OA\Property(property: 'phone', type: 'string'),
                        new OA\Property(property: 'country', type: 'string'),
                        new OA\Property(property: 'city', type: 'string'),
                        new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                        new OA\Property(property: 'new_password', type: 'string', format: 'password'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function updateProfile(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        // Support both JSON body (what account.js currently sends) and regular FormData — same pattern as admin
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $post = array_merge($_POST, $body);

        $token = $post['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

        $userId = (int)$_SESSION['user_id'];
        $user   = UserModel::findById($userId);
        if (!$user) {
            $this->respond(false, 'Session error, please log in again.');
        }

        // Current password is always required — not just for password changes, for any save
        $currentPassword = $post['current_password'] ?? '';
        if (!$currentPassword || !password_verify($currentPassword, $user['password'])) {
            $this->respond(false, 'Current password is incorrect.');
        }

        $fullName = trim($post['full_name'] ?? '');
        if (strlen($fullName) < 2) {
            $this->respond(false, 'Name must be at least 2 characters.');
        }

        // Support the composite field (phone_country_code + phone_local) — same pattern as admin
        $phone = trim($post['phone_number'] ?? '');
        if ($phone === '' && isset($post['phone_local'])) {
            $code  = trim($post['phone_country_code'] ?? '');
            $local = trim($post['phone_local'] ?? '');
            $phone = $code . $local;
        }

        $country = trim($post['country'] ?? '');
        $city    = trim($post['city']    ?? '');

        $updated = UserModel::updateProfile($userId, [
            'full_name'    => $fullName,
            'phone_number' => $phone ?: null,
            'country'      => $country ?: null,
            'city'         => $city    ?: null,
        ]);

        if (!$updated) {
            $this->respond(false, 'Could not update profile. Please try again.');
        }

        // New password — optional
        $newPassword = $post['new_password'] ?? '';
        if ($newPassword !== '') {
            if (!isStrongPassword($newPassword)) {
                $this->respond(false, 'New password must be at least 8 characters and include letters and numbers.');
            }
            if (password_verify($newPassword, $user['password'])) {
                $this->respond(false, 'New password must be different from the current password.');
            }
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            UserModel::updatePassword($userId, $newHash);
        }

        // Update the username in the session
        $_SESSION['user_name'] = $fullName;

        $this->respond(true, 'Profile updated successfully.');
    }

    // ════════════════════════════════════════════════════════
    // POST /user/addresses — إضافة عنوان
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/user/addresses',
        summary: 'إضافة عنوان شحن للحساب',
        tags: ['Store - Account'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['full_address', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'label', type: 'string', description: 'اسم العنوان مثل "المنزل"'),
                        new OA\Property(property: 'full_address', type: 'string'),
                        new OA\Property(property: 'city', type: 'string'),
                        new OA\Property(property: 'country', type: 'string'),
                        new OA\Property(property: 'phone', type: 'string'),
                        new OA\Property(property: 'is_default', type: 'boolean'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function addAddress(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $post = array_merge($_POST, $body);

        $token = $post['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            $this->respond(false, 'Invalid CSRF token.');
        }

        $userId = (int)$_SESSION['user_id'];
        $full   = trim($post['full_address'] ?? '');

        if (!$full) {
            $this->respond(false, 'Full address is required.');
        }

        $newId = OrderModel::addAddress($userId, [
            'label'        => $post['label']        ?? 'Home',
            'country'      => $post['country']      ?? null,
            'city'         => $post['city']         ?? null,
            'full_address' => $full,
            'phone_number' => $post['phone_number'] ?? null,
            'is_default'   => !empty($post['is_default']) ? 1 : 0,
        ]);

        if (!$newId) {
            $this->respond(false, 'Could not save address.');
        }

        $this->respond(true, 'Address saved successfully.', ['address_id' => $newId]);
    }

    // ════════════════════════════════════════════════════════
    // POST /user/addresses/delete — حذف عنوان
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/user/addresses/delete',
        summary: 'حذف عنوان شحن',
        tags: ['Store - Account'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['address_id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'address_id', type: 'integer'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function deleteAddress(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $post = array_merge($_POST, $body);

        $token = $post['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            $this->respond(false, 'Invalid CSRF token.');
        }

        $userId    = (int)$_SESSION['user_id'];
        $addressId = (int)($post['address_id'] ?? 0);

        if (!$addressId) {
            $this->respond(false, 'Missing address ID.');
        }

        $deleted = OrderModel::deleteAddress($addressId, $userId);

        if (!$deleted) {
            $this->respond(false, 'Could not delete address.');
        }

        $this->respond(true, 'Address deleted.');
    }
}
