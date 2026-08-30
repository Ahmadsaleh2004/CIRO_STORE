<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Middleware;
use App\Models\UserModel;
use App\Models\OrderModel;
use OpenApi\Attributes as OA;

/**
 * MyInfoController — the user's profile, orders and addresses.
 * Moved and converted from the old pages/my-info.php.
 * Entirely separate from the admin-facing AdminMyInfoController — they share no
 * session, no logic and no table.
 */
class MyInfoController extends Controller
{
    // ════════════════════════════════════════════════════════
    // GET /user/info — render the page
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/user/info',
        summary: 'User account page — profile, orders and addresses',
        tags: ['Store - Account'],
        security: [['userSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'HTML page'),
            new OA\Response(response: 302, description: 'Redirect home with the login modal opened'),
        ]
    )]
    public function index(): void
    {
        Middleware::requireLogin();

        $userId = (int)$_SESSION['user_id'];
        $user   = UserModel::findById($userId);

        if (!$user) {
            // Corrupt session — log out and redirect
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
            'extraScripts' => '<script src="' . URLROOT . '/js/features/account.js" defer></script>',
            'user'        => $user,
            'orders'      => $orders,
            'addresses'   => $addresses,
            'csrf'        => generateCsrfToken(),
            'userLoggedIn' => true,
            'userName'    => $user['full_name'],
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /user/info — update the profile
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/user/info',
        summary: 'Update account details',
        description: 'The current password is required for any change, even when the password '
                   . 'itself is not the thing being changed.',
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
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
    )]
    public function updateProfile(): void
    {
        $this->beginJsonPost();
        Middleware::requireLogin();

        // Support both JSON body (what account.js currently sends) and regular FormData — same pattern as admin
        $post = $this->requestData();

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
    // POST /user/addresses — add an address
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/user/addresses',
        summary: 'Add a shipping address to the account',
        tags: ['Store - Account'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['full_address', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'label', type: 'string', description: 'A name for the address, such as "Home"'),
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
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
    )]
    public function addAddress(): void
    {
        $this->beginJsonPost();
        Middleware::requireLogin();

        // Extraction used to be done by hand here: trim on one field, `?? null` on
        // four more, and `!empty(...) ? 1 : 0` on the fifth. The only rule actually
        // enforced was "the address is not empty" — no minimum and no maximum — so a
        // one-character address was accepted, and so was a string as long as the
        // column allowed.
        $input = $this->validate([
            'full_address' => 'required|string|min:5|max:255',
            'label'        => 'string|max:50|default:Home',
            'country'      => 'nullable|string|max:80',
            'city'         => 'nullable|string|max:80',
            'phone_number' => 'nullable|string|max:30',
            'is_default'   => 'bool',
        ]);

        $userId = (int)$_SESSION['user_id'];

        $newId = OrderModel::addAddress($userId, [
            'label'        => $input['label'],
            'country'      => $input['country'],
            'city'         => $input['city'],
            'full_address' => $input['full_address'],
            'phone_number' => $input['phone_number'],
            'is_default'   => $input['is_default'] ? 1 : 0,
        ]);

        if (!$newId) {
            $this->respond(false, 'Could not save address.');
        }

        $this->respond(true, 'Address saved successfully.', ['address_id' => $newId]);
    }

    // ════════════════════════════════════════════════════════
    // POST /user/addresses/delete — delete an address
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/user/addresses/delete',
        summary: 'Delete a shipping address',
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
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
    )]
    public function deleteAddress(): void
    {
        $this->beginJsonPost();
        Middleware::requireLogin();

        $post = $this->requestData();

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
