<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\UserModel;
use App\Models\AdminModel;
use App\Models\OrderModel;
use App\Models\SupportModel;
use OpenApi\Attributes as OA;

/**
 * AdminUsersController — user management (list / details / delete / strikes / export).
 * Extends AdminController, which verifies the admin login automatically.
 */
class AdminUsersController extends AdminController
{
    #[OA\Get(
        path: '/admin/users',
        summary: 'User list with search, filtering and pagination',
        tags: ['Admin - Manage Users'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Search by name or email'),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['all','active','not_active','blocked']), description: 'Filter by status'),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Page number'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'HTML page with the table — requires the can_manage_users permission'),
            new OA\Response(response: 403, description: 'Forbidden — the admin lacks can_manage_users'),
        ]
    )]
    public function index(): void
    {
        Middleware::requirePermission('can_manage_users');

        $q       = trim($_GET['q'] ?? '');
        $status  = in_array($_GET['status'] ?? '', ['all', 'active', 'not_active', 'blocked'], true) ? $_GET['status'] : 'all';
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;

        $result    = UserModel::getAllForAdmin($q, $status, $page, $perPage);
        $flashMsg  = $_SESSION['flash_msg'] ?? '';
        $flashErr  = $_SESSION['flash_err'] ?? '';
        unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

        $this->adminView('users/index', [
            'pageTitle'  => 'Manage Users',
            'users'      => $result['rows'],
            'total'      => $result['total'],
            'totalUsers' => UserModel::countAll(),
            'page'       => $page,
            'perPage'    => $perPage,
            'search'     => $q,
            'status'     => $status,
            'flashMsg'   => $flashMsg,
            'flashErr'   => $flashErr,
        ]);
    }

    #[OA\Get(
        path: '/admin/users/details',
        summary: 'Details for one user: profile, strikes, orders, and the log of admin actions taken on them',
        tags: ['Admin - Manage Users'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 302, ref: '#/components/responses/RedirectToLogin'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function details(): void
    {
        Middleware::requirePermission('can_manage_users');

        $targetId = (int)($_GET['id'] ?? 0);
        $target   = $targetId ? UserModel::getByIdForAdmin($targetId) : null;
        if (!$target) {
            $_SESSION['flash_err'] = 'User not found.';
            header('Location: ' . URLROOT . '/admin/users');
            exit;
        }

        $this->adminView('users/details', [
            'pageTitle'   => 'User Details',
            'target'      => $target,
            'addresses'   => OrderModel::getUserAddresses($targetId),
            'orders'      => OrderModel::getOrdersForUser($targetId),
            'strikes'     => UserModel::getStrikes($targetId),
            'auditLog'    => AdminModel::getAuditLogForUser($targetId),
            'messages'    => SupportModel::getMessagesForUser($targetId, $target['email']),
            'strikesCount' => (int)$target['strikes_count'],
        ]);
    }

    #[OA\Post(
        path: '/admin/users/delete',
        summary: 'Delete a user (AJAX — returns JSON, a reason is required)',
        tags: ['Admin - Manage Users'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['user_id', 'reason', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'user_id', type: 'integer'),
                        new OA\Property(property: 'reason', type: 'string'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )]
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Success or failure',
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
        Middleware::requirePermission('can_manage_users');
        $this->beginJsonPost();

        $adminId  = getCurrentAdminId();
        $targetId = (int)($_POST['user_id'] ?? 0);
        $reason   = trim($_POST['reason'] ?? '');

        if ($reason === '') {
            $this->respond(false, 'A reason is required.');
        }
        if (!$targetId) {
            $this->respond(false, 'Invalid user ID.');
        }

        $target = UserModel::getByIdForAdmin($targetId);
        if (!$target) {
            $this->respond(false, 'User not found.');
        }

        // The user's status at the moment of deletion — "blocked" is derived (strikes >= 3), not a column on the table
        $strikesCount = UserModel::getStrikesCount($targetId);
        $statusLabel  = $strikesCount >= 3 ? 'Blocked' : 'Active';

        $deleteResult = UserModel::deleteUser($targetId);
        if (!$deleteResult['success']) {
            $this->respond(false, 'Failed to delete user. Please try again.');
        }

        $ordersSummary = sprintf(
            '%d order(s) deleted (Not Taken: %d, Taken: %d, Completed: %d, Cancelled: %d)',
            $deleteResult['ordersDeletedCount'],
            $deleteResult['ordersByStatus']['not_taken'],
            $deleteResult['ordersByStatus']['taken'],
            $deleteResult['ordersByStatus']['completed'],
            $deleteResult['ordersByStatus']['cancelled']
        );

        $auditDetails = "Deleted: {$target['email']}. Status at deletion: {$statusLabel} ({$strikesCount} strike(s)). {$ordersSummary}. Reason: {$reason}";

        AdminModel::logAction($adminId, 'delete_user', 'user', $targetId, $auditDetails);
        AdminModel::sendNotification($adminId, 'User Deleted', "You deleted user {$target['email']}. Reason: {$reason}", 'user_deleted', 'user', $targetId, $adminId);

        $this->notifyHigherRanks($adminId, 'User Deleted', "Admin deleted user {$target['email']}. Reason: {$reason}", 'user_deleted', $targetId);

        $this->respond(true, 'User deleted successfully.');
    }

    #[OA\Post(
        path: '/admin/users/strikes/add',
        summary: 'Add a strike to a user (AJAX — returns JSON)',
        tags: ['Admin - Manage Users'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['user_id', 'reason', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'user_id', type: 'integer'),
                        new OA\Property(property: 'reason', type: 'string'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )]
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Success or failure',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'strikes_count', type: 'integer'),
                    ]
                )
            )
        ]
    )]
    public function addStrike(): void
    {
        Middleware::requirePermission('can_manage_users');
        $this->beginJsonPost();

        $adminId  = getCurrentAdminId();
        $targetId = (int)($_POST['user_id'] ?? 0);
        $reason   = trim($_POST['reason'] ?? '');

        if (!$targetId) {
            $this->respond(false, 'Invalid user ID.');
        }
        if ($reason === '') {
            $this->respond(false, 'A reason is required for the strike.');
        }
        $target = UserModel::getByIdForAdmin($targetId);
        if (!$target) {
            $this->respond(false, 'User not found.');
        }
        if (count(UserModel::getStrikes($targetId)) >= 3) {
            $this->respond(false, 'This user is already blocked (3/3 strikes).');
        }

        if (!UserModel::addStrike($targetId, $adminId, $reason)) {
            $this->respond(false, 'Failed to add strike. Please try again.');
        }

        AdminModel::logAction($adminId, 'add_strike', 'user', $targetId, "Strike added. Reason: {$reason}");
        UserModel::sendNotification($targetId, 'Warning Issued', "You received an official warning:\n{$reason}", $adminId);

        AdminModel::sendNotification($adminId, 'Strike Added', "You added a strike to user {$target['email']}. Reason: {$reason}", 'strike_added', 'user', $targetId, $adminId);
        $this->notifyHigherRanks($adminId, 'Strike Added', "A strike was added to user {$target['email']}. Reason: {$reason}", 'strike_added', $targetId);

        $newCount = UserModel::getStrikesCount($targetId);

        if ($newCount === 3) {
            $this->notifyUserBlocked($target['email'], $targetId);
            $this->respond(true, 'Strike added. User is now blocked (3/3) — pending orders were cancelled automatically.', ['strikes_count' => $newCount]);
        }

        $this->respond(true, 'Strike added and user notified.', ['strikes_count' => $newCount]);
    }

    #[OA\Post(
        path: '/admin/users/strikes/remove',
        summary: 'Remove a strike from a user (AJAX — returns JSON)',
        tags: ['Admin - Manage Users'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['strike_id', 'user_id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'strike_id', type: 'integer'),
                        new OA\Property(property: 'user_id', type: 'integer'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )]
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Success or failure',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            )
        ]
    )]
    public function removeStrike(): void
    {
        Middleware::requirePermission('can_manage_users');
        $this->beginJsonPost();

        $adminId  = getCurrentAdminId();
        $targetId = (int)($_POST['user_id'] ?? 0);
        $strikeId = (int)($_POST['strike_id'] ?? 0);

        if (!$targetId || !$strikeId) {
            $this->respond(false, 'Both strike ID and user ID are required.');
        }

        if (!UserModel::removeStrike($strikeId, $targetId)) {
            $this->respond(false, 'Strike not found or does not belong to this user.');
        }

        $target      = UserModel::getByIdForAdmin($targetId);
        $targetEmail = $target['email'] ?? "user #{$targetId}";

        AdminModel::logAction($adminId, 'remove_strike', 'user', $targetId, "Removed strike #{$strikeId}");
        UserModel::sendNotification($targetId, 'Strike Removed', "One of your warnings (#{$strikeId}) was removed.", $adminId);

        AdminModel::sendNotification($adminId, 'Strike Removed', "You removed strike #{$strikeId} from user {$targetEmail}.", 'strike_removed', 'user', $targetId, $adminId);
        $this->notifyHigherRanks($adminId, 'Strike Removed', "Strike #{$strikeId} was removed from user {$targetEmail}.", 'strike_removed', $targetId);

        $this->respond(true, 'Strike removed successfully.', ['strikes_count' => UserModel::getStrikesCount($targetId)]);
    }

    #[OA\Get(
        path: '/admin/users/export-csv',
        summary: 'Export the user list as a CSV file',
        tags: ['Admin - Manage Users'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/CsvDownload'),
            new OA\Response(response: 401, ref: '#/components/responses/SessionExpired'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
        ]
    )]
    public function exportCsv(): void
    {
        Middleware::requirePermission('can_manage_users');

        $data = UserModel::getAllForCsvExport();
        AdminModel::logAction(getCurrentAdminId(), 'export_csv', 'user', 0, "Exported " . count($data) . " users.");

        $headers = ['ID', 'Full Name', 'Email', 'Phone', 'Strikes', 'Status', 'Joined'];
        $rows    = array_map(function (array $r): array {
            $strikes = (int)($r['strikes_count'] ?? 0);
            if ($strikes >= 3) {
                $status = 'blocked';
            } elseif (empty($r['last_activity']) || strtotime($r['last_activity']) < strtotime('-90 days')) {
                $status = 'not_active';
            } else {
                $status = 'active';
            }

            return [
                $r['id'],
                $r['full_name'],
                $r['email'],
                $r['phone_number'] ?? '',
                $strikes,
                $status,
                $r['created_at'] ?? '',
            ];
        }, $data);

        $this->sendCsv('users_' . date('Ymd_His') . '.csv', $headers, $rows);
    }

    // ── Internal private helpers ──────────────────────────────────

    /**
     * Notify admins with a strictly higher rank than the actor who hold
     * can_manage_users, always excluding rank A.
     */
    private function notifyHigherRanks(int $actorAdminId, string $title, string $message, string $type, int $targetUserId): void
    {
        $actorRole = getAdminRole();
        $targets   = AdminModel::findHigherRankWithPermission('can_manage_users', $actorRole);

        foreach ($targets as $targetAdminId) {
            $targetAdminId = (int)$targetAdminId;
            if ($targetAdminId === $actorAdminId) {
                continue;
            }
            AdminModel::sendNotification(
                $targetAdminId,
                $title,
                $message,
                $type,
                'user',
                $targetUserId,
                $actorAdminId
            );
        }
    }

    /**
     * A user just reached 3/3 strikes and got auto-blocked — notify every admin
     * with can_manage_users, all ranks included (A too), no exceptions.
     */
    private function notifyUserBlocked(string $targetEmail, int $targetUserId): void
    {
        $targets = AdminModel::findByPermsAndRanks(['can_manage_users'], ['A', 'B', 'C', 'D']);
        foreach ($targets as $targetAdminId) {
            AdminModel::sendNotification(
                (int)$targetAdminId,
                'User Blocked (3/3 Strikes) 🚫',
                "User {$targetEmail} reached 3/3 strikes and is now blocked automatically. Pending orders were cancelled.",
                'user_blocked',
                'user',
                $targetUserId,
                null
            );
        }
    }
}
