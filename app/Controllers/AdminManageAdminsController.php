<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\AdminModel;
use App\Models\OrderModel;
use OpenApi\Attributes as OA;

/**
 * AdminManageAdminsController — إدارة الأدمنية (قائمة/إضافة/تعديل/حذف/تفاصيل/تصدير).
 * يرث من AdminController الذي يتحقق من تسجيل دخول الأدمن تلقائياً.
 */
class AdminManageAdminsController extends AdminController
{
    #[OA\Get(
        path: '/admin/admins',
        summary: 'قائمة كل الأدمنية مع صلاحياتهم',
        tags: ['Admin - Manage Admins'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML بالجدول — يتطلب صلاحية can_manage_admins'),
            new OA\Response(response: 403, description: 'ممنوع — لا يملك can_manage_admins'),
        ]
    )]
    public function index(): void
    {
        Middleware::requirePermission('can_manage_admins');

        $admins   = AdminModel::getAllWithPermissions();
        $flashMsg = $_SESSION['flash_msg'] ?? '';
        $flashErr = $_SESSION['flash_err'] ?? '';
        unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

        $this->adminView('manage-admins/index', [
            'pageTitle'   => 'Manage Admins',
            'admins'      => $admins,
            'totalAdmins' => AdminModel::countAdmins(),
            'flashMsg'    => $flashMsg,
            'flashErr'    => $flashErr,
        ]);
    }

    #[OA\Get(
        path: '/admin/admins/add',
        summary: 'عرض فورم إضافة أدمن جديد',
        tags: ['Admin - Manage Admins'],
        security: [['adminSessionAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'صفحة HTML للفورم')]
    )]
    public function showAdd(): void
    {
        Middleware::requirePermission('can_manage_admins');

        $this->adminView('manage-admins/add', ['pageTitle' => 'Add Admin']);
    }

    #[OA\Post(
        path: '/admin/admins/add',
        summary: 'إضافة أدمن جديد (يتطلب سبب + كلمة مرور الأدمن الحالي)',
        tags: ['Admin - Manage Admins'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['new_name','new_email','new_password','new_role','add_reason','confirm_current_pass','csrf_token'],
                    properties: [
                        new OA\Property(property: 'new_name',             type: 'string'),
                        new OA\Property(property: 'new_email',            type: 'string', format: 'email', description: 'يجب أن ينتهي بـ @gmail.com'),
                        new OA\Property(property: 'new_phone',            type: 'string'),
                        new OA\Property(property: 'new_password',         type: 'string', format: 'password'),
                        new OA\Property(property: 'new_role',             type: 'string', description: 'A|B|C|D — يجب أن تكون أقل صراحة من رتبتك'),
                        new OA\Property(property: 'perm_admins',          type: 'boolean'),
                        new OA\Property(property: 'perm_products',        type: 'boolean'),
                        new OA\Property(property: 'perm_users',           type: 'boolean'),
                        new OA\Property(property: 'perm_dashboard',       type: 'boolean'),
                        new OA\Property(property: 'perm_support',         type: 'boolean'),
                        new OA\Property(property: 'perm_content',         type: 'boolean'),
                        new OA\Property(property: 'perm_branding',        type: 'boolean'),
                        new OA\Property(property: 'perm_checkout',        type: 'boolean'),
                        new OA\Property(property: 'perm_orders',          type: 'boolean'),
                        new OA\Property(property: 'add_reason',           type: 'string'),
                        new OA\Property(property: 'confirm_current_pass', type: 'string', format: 'password'),
                        new OA\Property(property: 'csrf_token',           type: 'string'),
                    ]
                )
            )]
        ),
        responses: [new OA\Response(response: 302, description: 'إعادة توجيه لصفحة القائمة مع رسالة flash')]
    )]
    public function storeAdd(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requirePermission('can_manage_admins');

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

        $adminId = getCurrentAdminId();
        $myRole  = getAdminRole();

        if (!AdminModel::verifyPassword($adminId, $_POST['confirm_current_pass'] ?? '')) {
            $this->respond(false, 'Your current password is incorrect.');
        }

        $reason = trim($_POST['add_reason'] ?? '');
        if ($reason === '') {
            $this->respond(false, 'Please provide a reason for adding this admin.');
        }

        $newRole = in_array($_POST['new_role'] ?? '', ['A','B','C','D'], true) ? $_POST['new_role'] : 'B';
        if (!AdminModel::canManageTarget($myRole, $newRole)) {
            $this->respond(false, "You cannot create an admin with rank {$newRole}.");
        }

        $email = strtolower(trim($_POST['new_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@gmail.com')) {
            $this->respond(false, 'Email must be a @gmail.com address.');
        }
        if (AdminModel::emailExists($email)) {
            $this->respond(false, 'This email is already registered.');
        }

        $pass = trim($_POST['new_password'] ?? '');
        if (!isStrongPassword($pass)) {
            $this->respond(false, 'Password must be at least 8 chars with uppercase, lowercase, number, and symbol.');
        }

        $newId = AdminModel::createAdmin(
            [
                'full_name'    => trim($_POST['new_name'] ?? ''),
                'email'        => $email,
                'password'     => $pass,
                'phone_number' => trim($_POST['new_phone'] ?? ''),
                'role'         => $newRole,
                'added_by'     => $adminId,
            ],
            [
                'can_manage_admins'            => !empty($_POST['perm_admins']),
                'can_manage_products'          => !empty($_POST['perm_products']),
                'can_manage_users'             => !empty($_POST['perm_users']),
                'can_view_dashboard'           => !empty($_POST['perm_dashboard']),
                'can_manage_support'           => !empty($_POST['perm_support']),
                'can_edit_site_content'        => !empty($_POST['perm_content']),
                'can_manage_checkout_settings' => !empty($_POST['perm_checkout']),
                'can_manage_orders'            => !empty($_POST['perm_orders']),
                'can_manage_branding'          => !empty($_POST['perm_branding']),
            ]
        );

        if (!$newId) {
            $this->respond(false, 'Failed to create admin. Please try again.');
        }

        AdminModel::logAction($adminId, 'add_admin', 'admin', $newId, "added: {$email} role:{$newRole}. Reason: {$reason}");
        AdminModel::sendNotification($adminId, 'Admin Added', "You added admin {$email} (role {$newRole}). Reason: {$reason}", 'admin_added', 'admin', $newId, $adminId);
        AdminModel::sendNotification($newId, 'Welcome', "You were added as an admin. Reason: {$reason}", 'admin_added', 'admin', $newId, $adminId);

        $rootId    = AdminModel::getRootAdminId();
        $myRank    = $myRole;
        $rankOrder = ['D' => 1, 'C' => 2, 'B' => 3, 'A' => 4];
        $myRankVal = $rankOrder[$myRank] ?? 0;
        $higherRanks = array_keys(array_filter($rankOrder, fn($v) => $v > $myRankVal));

        if ($higherRanks) {
            $recipients = AdminModel::findByPermsAndRanks(['can_manage_admins'], $higherRanks);
            foreach ($recipients as $recipientId) {
                $recipientId = (int)$recipientId;
                if ($recipientId === $adminId) continue;
                if ($recipientId === $newId)   continue;
                if ($rootId !== null && $recipientId === $rootId) continue;

                AdminModel::sendNotification(
                    $recipientId, 'Admin Added',
                    "Admin {$email} (role {$newRole}) was added by another admin. Reason: {$reason}",
                    'admin_added', 'admin', $newId, $adminId
                );
            }
        }

        $this->respond(true, "Admin ({$email}) added successfully.");
    }

    #[OA\Post(
        path: '/admin/admins/edit',
        summary: 'حفظ تعديل رتبة/صلاحيات أدمن (يتطلب سبب + كلمة مرور الأدمن الحالي)',
        tags: ['Admin - Manage Admins'],
        security: [['adminSessionAuth' => []]],
        responses: [new OA\Response(response: 302, description: 'إعادة توجيه مع رسالة flash')]
    )]
    public function storeEdit(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requirePermission('can_manage_admins');

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

        $adminId  = getCurrentAdminId();
        $targetId = (int)($_POST['target_id'] ?? 0);

        if (!AdminModel::verifyPassword($adminId, $_POST['confirm_edit_pass'] ?? '')) {
            $this->respond(false, 'Incorrect password. Changes were not saved.');
        }

        $reason  = trim($_POST['edit_reason'] ?? '');
        $target  = AdminModel::getByIdWithPermissions($targetId);
        $newRole = $_POST['edit_role'] ?? '';

        if ($reason === '') {
            $this->respond(false, 'A reason for this edit is required.');
        }
        if (!$target || !AdminModel::canManageTarget(getAdminRole(), $target['role'])) {
            $this->respond(false, 'You cannot edit an admin with an equal or higher rank than your own.');
        }
        if ($newRole && $newRole !== $target['role'] && !AdminModel::canManageTarget(getAdminRole(), $newRole)) {
            $this->respond(false, 'You cannot promote an admin to a rank equal to or higher than your own.');
        }

        AdminModel::updatePermissions($targetId, $newRole, [
            'can_manage_admins'            => !empty($_POST['perm_admins']),
            'can_manage_products'          => !empty($_POST['perm_products']),
            'can_manage_users'             => !empty($_POST['perm_users']),
            'can_view_dashboard'           => !empty($_POST['perm_dashboard']),
            'can_manage_support'           => !empty($_POST['perm_support']),
            'can_edit_site_content'        => !empty($_POST['perm_content']),
            'can_manage_checkout_settings' => !empty($_POST['perm_checkout']),
            'can_manage_orders'            => !empty($_POST['perm_orders']),
            'can_manage_branding'          => !empty($_POST['perm_branding']),
        ], $adminId);

        AdminModel::logAction($adminId, 'update_permissions', 'admin', $targetId, "role:{$newRole}. Reason: {$reason}");
        AdminModel::sendNotification($adminId, 'Admin Edited', "You edited admin {$target['email']}. Reason: {$reason}", 'admin_edited', 'admin', $targetId, $adminId);
        AdminModel::sendNotification($targetId, 'Your Account Was Edited', "Your permissions/role were edited. Reason: {$reason}", 'admin_edited', 'admin', $targetId, $adminId);

        $rootId    = AdminModel::getRootAdminId();
        $myRank    = getAdminRole();
        $rankOrder = ['D' => 1, 'C' => 2, 'B' => 3, 'A' => 4];
        $myRankVal = $rankOrder[$myRank] ?? 0;
        $higherRanks = array_keys(array_filter($rankOrder, fn($v) => $v > $myRankVal));

        if ($higherRanks) {
            $recipients = AdminModel::findByPermsAndRanks(['can_manage_admins'], $higherRanks);
            foreach ($recipients as $recipientId) {
                $recipientId = (int)$recipientId;
                if ($recipientId === $adminId)  continue;
                if ($recipientId === $targetId) continue;
                if ($rootId !== null && $recipientId === $rootId) continue;

                AdminModel::sendNotification(
                    $recipientId, 'Admin Edited',
                    "Admin {$target['email']} (role {$target['role']}) permissions/role were edited by another admin. Reason: {$reason}",
                    'admin_edited', 'admin', $targetId, $adminId
                );
            }
        }

        $this->respond(true, 'Permissions updated successfully.');
    }

    #[OA\Post(
        path: '/admin/admins/delete',
        summary: 'حذف أدمن (AJAX — يرجع JSON، يتطلب سبب + كلمة مرور)',
        tags: ['Admin - Manage Admins'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['target_id', 'delete_reason', 'confirm_del_pass', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'target_id',        type: 'integer'),
                        new OA\Property(property: 'delete_reason',    type: 'string'),
                        new OA\Property(property: 'confirm_del_pass', type: 'string', format: 'password'),
                        new OA\Property(property: 'csrf_token',       type: 'string'),
                    ]
                )
            )]
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'نجاح أو فشل',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            )
        ]
    )]
    public function delete(): void
    {
        Middleware::requirePermission('can_manage_admins');
        $this->beginJsonPost();

        $adminId  = getCurrentAdminId();
        $targetId = (int)($_POST['target_id'] ?? 0);
        $reason   = trim($_POST['delete_reason'] ?? '');

        if ($reason === '') {
            $this->respond(false, 'A reason is required.');
        }
        if (!AdminModel::verifyPassword($adminId, $_POST['confirm_del_pass'] ?? '')) {
            $this->respond(false, 'Incorrect password.');
        }
        if ($targetId === $adminId) {
            $this->respond(false, 'You cannot delete your own account.');
        }

        $target = AdminModel::getByIdWithPermissions($targetId);
        if (!$target) {
            $this->respond(false, 'Admin not found.');
        }
        if (!AdminModel::canManageTarget(getAdminRole(), $target['role'])) {
            $this->respond(false, "You cannot delete an admin with rank {$target['role']}.");
        }
        if (AdminModel::countAdmins() <= 1) {
            $this->respond(false, 'Cannot delete the last admin in the system.');
        }

        AdminModel::deleteAdmin($targetId);
        AdminModel::logAction($adminId, 'delete_admin', 'admin', $targetId, "Deleted: {$target['email']}. Reason: {$reason}");
        AdminModel::sendNotification(
            $adminId, 'Admin Deleted',
            "You deleted admin {$target['email']}. Reason: {$reason}",
            'admin_deleted', 'admin', $targetId, $adminId
        );

        $rootId    = AdminModel::getRootAdminId();
        $myRank    = getAdminRole();
        $rankOrder = ['D' => 1, 'C' => 2, 'B' => 3, 'A' => 4];
        $myRankVal = $rankOrder[$myRank] ?? 0;

        $higherRanks = array_keys(array_filter($rankOrder, fn($v) => $v > $myRankVal));

        if ($higherRanks) {
            $recipients = AdminModel::findByPermsAndRanks(['can_manage_admins'], $higherRanks);
            foreach ($recipients as $recipientId) {
                $recipientId = (int)$recipientId;
                if ($recipientId === $adminId) continue;
                if ($rootId !== null && $recipientId === $rootId) continue;

                AdminModel::sendNotification(
                    $recipientId, 'Admin Deleted',
                    "Admin {$target['email']} (role {$target['role']}) was deleted by another admin. Reason: {$reason}",
                    'admin_deleted', 'admin', $targetId, $adminId
                );
            }
        }

        $this->respond(true, 'Admin deleted successfully.');
    }

    #[OA\Get(
        path: '/admin/admins/details',
        summary: 'تفاصيل أدمن معيّن: بياناته + نشاط طلباته + سجل أفعاله',
        tags: ['Admin - Manage Admins'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true,  schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'صفحة HTML')]
    )]
    public function details(): void
    {
        Middleware::requirePermission('can_manage_admins');

        $targetId = (int)($_GET['id'] ?? 0);
        $target   = $targetId ? AdminModel::getByIdWithPermissions($targetId) : null;
        if (!$target) {
            $_SESSION['flash_err'] = 'Admin not found.';
            header('Location: ' . URLROOT . '/admin/admins');
            exit;
        }

        // طلبات تولاها هذا الأدمن (orderRows) + إجمالي أرباح الطلبات المكتملة فقط
        $orderRows   = OrderModel::getOrdersHandledByAdmin((int)$target['id']);
        $profitTotal = array_sum(array_map(
            fn($o) => (float)$o['total_amount'],
            array_filter($orderRows, fn($o) => $o['status'] === 'completed' && empty($o['was_auto_released']))
        ));

        // أفعال إضافية على الطلبات لا تظهر عبر orderRows (بلاغات مشاكل، تصدير CSV)
        $orderAuditRows = array_filter(
            AdminModel::getAuditLogByTypes((int)$target['id'], ['orders']),
            fn($log) => in_array($log['action'], ['report_order_issue', 'export_csv'], true)
        );

        $this->adminView('manage-admins/details', [
            'pageTitle'          => 'Admin Details',
            'target'             => $target,
            'orderRows'          => $orderRows,
            'orderAuditRows'     => array_values($orderAuditRows),
            'profitTotal'        => $profitTotal,
            'userActionRows'     => AdminModel::getAuditLogByTypes((int)$target['id'], ['user']),
            'productActionRows'  => AdminModel::getAuditLogByTypes((int)$target['id'], ['product', 'category']),
            'brandingActionRows' => AdminModel::getAuditLogByTypes((int)$target['id'], ['branding']),
            'supportActionRows'  => AdminModel::getAuditLogByTypes((int)$target['id'], ['support']),
            'siteConfigRows'     => AdminModel::getAuditLogByTypes((int)$target['id'], ['website_settings']),
            'auditLog'           => AdminModel::getAuditLogExcludingTypes(
                (int)$target['id'],
                ['orders', 'user', 'product', 'category', 'branding', 'support', 'website_settings']
            ),
        ]);
    }

    #[OA\Get(
        path: '/admin/admins/export-csv',
        summary: 'تصدير قائمة الأدمنية كملف CSV — رتبة A فقط',
        tags: ['Admin - Manage Admins'],
        security: [['adminSessionAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'ملف CSV للتحميل')]
    )]
    public function exportCsv(): void
    {
        Middleware::requirePermission('can_manage_admins');

        if (!isRoleA()) {
            http_response_code(403);
            die('Unauthorized — Role A only');
        }

        $data = AdminModel::getAllForCsvExport();
        AdminModel::logAction(getCurrentAdminId(), 'export_csv', 'admin', 0, "Exported " . count($data) . " admins.");

        $headers = ['ID','Full Name','Email','Phone','Role','Products','Users','Orders','Support','Dashboard','Branding','Joined'];
        $rows    = array_map(fn($r) => [
            $r['id'],
            $r['full_name'],
            $r['email'],
            $r['phone_number'] ?? '',
            $r['role'],
            $r['can_manage_products'] ? 'Yes' : 'No',
            $r['can_manage_users']    ? 'Yes' : 'No',
            $r['can_manage_orders']   ? 'Yes' : 'No',
            $r['can_manage_support']  ? 'Yes' : 'No',
            $r['can_view_dashboard']  ? 'Yes' : 'No',
            $r['can_manage_branding'] ? 'Yes' : 'No',
            $r['created_at']          ?? '',
        ], $data);

        $this->sendCsv('admins_' . date('Ymd_His') . '.csv', $headers, $rows);
    }

    // ── Helpers خاصة داخلية ───────────────────────────────────────

    private function redirectWithError(string $msg, string $path): void
    {
        $_SESSION['flash_err'] = $msg;
        header('Location: ' . URLROOT . $path);
        exit;
    }
}
