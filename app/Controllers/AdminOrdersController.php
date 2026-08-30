<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\OrderModel;
use App\Models\UserModel;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

/**
 * AdminOrdersController — order management (list / details / take / deliver /
 * cancel / release / report / export / delete).
 * Deletion is allowed only for orders in the completed or cancelled state (the
 * Delete Order button in Manage Orders) — which guarantees active and in-flight
 * orders (not_taken / taken) can never be deleted, and the audit trail survives
 * for everything else.
 * Extends AdminController, which verifies the admin login automatically.
 */
class AdminOrdersController extends AdminController
{
    #[OA\Get(
        path: '/admin/orders',
        summary: 'Order list with status filtering, search and pagination',
        tags: ['Admin - Manage Orders'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['not_taken','taken','completed','cancelled'])),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'HTML page with the table — requires the can_manage_orders permission'),
            new OA\Response(response: 403, description: 'Forbidden — the admin lacks can_manage_orders'),
        ]
    )]
    public function index(): void
    {
        Middleware::requirePermission('can_manage_orders');

        // Lazy check — automatic release before any query runs (the 4-hour deadline)
        $this->notifyAutoReleasedOrders(OrderModel::releaseExpiredTakenOrders());

        $status = in_array($_GET['status'] ?? '', ['not_taken', 'taken', 'completed', 'cancelled'], true)
            ? $_GET['status'] : '';
        $search = trim($_GET['q'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1) ?: 1);

        $result = OrderModel::getAdminOrdersList(['status' => $status, 'search' => $search], $page);

        // Every order shown is now marked read for this admin
        OrderModel::markAllOrdersNotified();

        $flashMsg = $_SESSION['flash_msg'] ?? '';
        $flashErr = $_SESSION['flash_err'] ?? '';
        unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

        $this->adminView('orders/index', [
            'pageTitle'   => 'Manage Orders',
            'orders'      => $result['orders'],
            'totalOrders' => $result['total'],
            'totalPages'  => $result['totalPages'],
            'currentPage' => $page,
            'filter'      => $status,
            'search'      => $search,
            'flashMsg'    => $flashMsg,
            'flashErr'    => $flashErr,
        ]);
    }

    #[OA\Get(
        path: '/admin/orders/details',
        summary: 'Details for one order: customer, items, and the remaining-time counter',
        tags: ['Admin - Manage Orders'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order details HTML page — requires the can_manage_orders permission'),
            new OA\Response(response: 403, description: 'Forbidden — the admin lacks can_manage_orders'),
        ]
    )]
    public function details(): void
    {
        Middleware::requirePermission('can_manage_orders');

        // The same lazy check — this very order may have just passed its deadline
        $this->notifyAutoReleasedOrders(OrderModel::releaseExpiredTakenOrders());

        $orderId = (int)($_GET['id'] ?? 0);
        $order   = $orderId ? OrderModel::getAdminOrderDetails($orderId) : null;
        if (!$order) {
            $_SESSION['flash_err'] = 'Order not found.';
            header('Location: ' . URLROOT . '/admin/orders');
            exit;
        }

        $items        = OrderModel::getOrderItemsWithProduct($orderId);
        $productNames = implode(', ', array_column($items, 'product_name'));

        $remSeconds = 0;
        if ($order['status'] === 'taken' && $order['taken_at']) {
            $remSeconds = max(0, strtotime($order['taken_at']) + (4 * 3600) - time());
        }

        $this->adminView('orders/details', [
            'pageTitle'    => 'Order Details',
            'order'        => $order,
            'items'        => $items,
            'productNames' => $productNames,
            'userStrikes'  => UserModel::getStrikesCount((int)$order['user_id']),
            'remSeconds'   => $remSeconds,
        ]);
    }

    #[OA\Post(
        path: '/admin/orders/take',
        summary: 'Take an order off the not_taken list (AJAX — returns JSON)',
        tags: ['Admin - Manage Orders'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['order_id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'order_id', type: 'integer'),
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
    public function take(): void
    {
        Middleware::requirePermission('can_manage_orders');
        $this->beginJsonPost();

        $adminId = getCurrentAdminId();
        $orderId = (int)($_POST['order_id'] ?? 0);

        if (!$orderId) {
            $this->respond(false, 'Invalid order ID.');
        }

        $result = OrderModel::adminTakeOrder($orderId, $adminId);

        if ($result['success'] && $result['targetUserId']) {
            UserModel::sendNotification(
                $result['targetUserId'],
                'Order Status Update',
                "Your order #{$orderId} has been picked up and is being prepared.",
                $adminId
            );
            AdminModel::logAction($adminId, 'take_order', 'orders', $orderId, 'Status: taken');

            AdminModel::notifyHigherRanksOnAction(
                actorAdminId:  $adminId,
                permission:    'can_manage_orders',
                title:         'Order Taken',
                selfMessage:   "You took order #{$orderId}.",
                othersMessage: "Order #{$orderId} was taken and is being prepared.",
                type:          'order_taken',
                relatedType:   'order',
                relatedId:     $orderId
            );
        }

        $this->respond($result['success'], $result['message']);
    }

    #[OA\Post(
        path: '/admin/orders/mark-delivered',
        summary: 'Complete an order delivery (AJAX — returns JSON)',
        tags: ['Admin - Manage Orders'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['order_id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'order_id', type: 'integer'),
                        new OA\Property(property: 'notif_msg', type: 'string'),
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
    public function markDelivered(): void
    {
        Middleware::requirePermission('can_manage_orders');
        $this->beginJsonPost();

        $adminId = getCurrentAdminId();
        $orderId = (int)($_POST['order_id'] ?? 0);

        if (!$orderId) {
            $this->respond(false, 'Invalid order ID.');
        }

        $result = OrderModel::adminMarkDelivered($orderId, $adminId);

        if ($result['success'] && $result['targetUserId']) {
            $notifMsg = trim($_POST['notif_msg'] ?? '') ?: "Your order #{$orderId} has been delivered. Thank you!";
            UserModel::sendNotification($result['targetUserId'], 'Order Delivered ✅', $notifMsg, $adminId);
            AdminModel::logAction($adminId, 'mark_delivered', 'orders', $orderId, 'Status: completed');

            AdminModel::sendNotification(
                $adminId,
                'Order Delivered',
                "You marked order #{$orderId} as delivered.",
                'order_delivered',
                'order',
                $orderId,
                $adminId
            );
        }

        $this->respond($result['success'], $result['message']);
    }

    #[OA\Post(
        path: '/admin/orders/cancel-delivery',
        summary: 'Cancel a delivery — sets cancelled and restores stock (AJAX — returns JSON)',
        tags: ['Admin - Manage Orders'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['order_id', 'reason', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'order_id', type: 'integer'),
                        new OA\Property(property: 'reason', type: 'string'),
                        new OA\Property(property: 'notif_msg', type: 'string'),
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
    public function cancelDelivery(): void
    {
        Middleware::requirePermission('can_manage_orders');
        $this->beginJsonPost();

        $adminId = getCurrentAdminId();
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason  = trim($_POST['reason'] ?? '');

        if (!$orderId) {
            $this->respond(false, 'Invalid order ID.');
        }
        if ($reason === '') {
            $this->respond(false, 'A cancellation reason is required.');
        }

        $result = OrderModel::adminCancelDelivery($orderId, $adminId);

        if ($result['success'] && $result['targetUserId']) {
            $notifMsg = trim($_POST['notif_msg'] ?? '') ?: "Your order #{$orderId} delivery has been cancelled.";
            UserModel::sendNotification($result['targetUserId'], 'Delivery Cancelled ❌', $notifMsg, $adminId);
            AdminModel::logAction($adminId, 'cancel_delivery', 'orders', $orderId, "Status: cancelled. Reason: {$reason}");

            AdminModel::notifyHigherRanksOnAction(
                actorAdminId:  $adminId,
                permission:    'can_manage_orders',
                title:         'Order Cancelled',
                selfMessage:   "You cancelled order #{$orderId}. Reason: {$reason}",
                othersMessage: "Order #{$orderId} was cancelled. Reason: {$reason}",
                type:          'order_cancelled',
                relatedType:   'order',
                relatedId:     $orderId
            );
        }

        $this->respond($result['success'], $result['message']);
    }

    #[OA\Post(
        path: '/admin/orders/release',
        summary: 'Voluntarily release a taken order back to not_taken (only the current holder can do this) — AJAX, returns JSON',
        tags: ['Admin - Manage Orders'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['order_id', 'reason', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'order_id', type: 'integer'),
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
    public function release(): void
    {
        Middleware::requirePermission('can_manage_orders');
        $this->beginJsonPost();

        $adminId = getCurrentAdminId();
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason  = trim($_POST['reason'] ?? '');

        if (!$orderId) {
            $this->respond(false, 'Invalid order ID.');
        }
        if ($reason === '') {
            $this->respond(false, 'A reason is required to release this order.');
        }

        $result = OrderModel::adminReleaseOrder($orderId, $adminId);

        if ($result['success']) {
            AdminModel::logAction($adminId, 'release_order', 'orders', $orderId, "Order released back to not_taken. Reason: {$reason}");

            if ($result['targetUserId']) {
                UserModel::sendNotification(
                    $result['targetUserId'],
                    'Order Status Update',
                    "Your order #{$orderId} has been released and is waiting to be picked up again.",
                    $adminId
                );
            }

            AdminModel::notifyHigherRanksOnAction(
                actorAdminId:  $adminId,
                permission:    'can_manage_orders',
                title:         'Order Released',
                selfMessage:   "You released order #{$orderId} back to Not Taken. Reason: {$reason}",
                othersMessage: "Order #{$orderId} was released back to Not Taken. Reason: {$reason}",
                type:          'order_released',
                relatedType:   'order',
                relatedId:     $orderId
            );
        }

        $this->respond($result['success'], $result['message']);
    }

    #[OA\Post(
        path: '/admin/orders/report-issue',
        summary: "Report a problem on an order and notify the user (does not change the order's status — AJAX, returns JSON)",
        tags: ['Admin - Manage Orders'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['order_id', 'reason', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'order_id', type: 'integer'),
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
    public function reportIssue(): void
    {
        Middleware::requirePermission('can_manage_orders');
        $this->beginJsonPost();

        $adminId = getCurrentAdminId();
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason  = trim($_POST['reason'] ?? '');

        if (!$orderId) {
            $this->respond(false, 'Invalid order ID.');
        }
        if ($reason === '') {
            $this->respond(false, 'Please provide a reason for the report.');
        }

        $targetUserId = OrderModel::getOrderUserId($orderId);
        if ($targetUserId === null) {
            $this->respond(false, 'Order not found.');
        }

        UserModel::sendNotification(
            $targetUserId,
            "Order Issue Reported — Order #{$orderId}",
            "An issue was reported on your order #{$orderId}:\n{$reason}\nPlease contact support if you have questions.",
            $adminId
        );
        AdminModel::logAction($adminId, 'report_order_issue', 'orders', $orderId, "Reason: {$reason}");

        $this->respond(true, 'Issue reported and user notified.');
    }

    #[OA\Post(
        path: '/admin/orders/delete',
        summary: 'Hard Delete an order with status completed or cancelled (AJAX — returns JSON)',
        tags: ['Admin - Manage Orders'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['order_id', 'reason', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'order_id', type: 'integer'),
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
        Middleware::requirePermission('can_manage_orders');
        $this->beginJsonPost();

        $adminId = getCurrentAdminId();
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason  = trim($_POST['reason'] ?? '');

        if (!$orderId) {
            $this->respond(false, 'Invalid order ID.');
        }
        if ($reason === '') {
            $this->respond(false, 'A reason is required to delete this order.');
        }

        $result = OrderModel::adminDeleteOrder($orderId);

        if ($result['success']) {
            AdminModel::logAction($adminId, 'delete_order', 'orders', $orderId, "Order permanently deleted. Reason: {$reason}");

            AdminModel::notifyHigherRanksOnAction(
                actorAdminId:  $adminId,
                permission:    'can_manage_orders',
                title:         'Order Deleted',
                selfMessage:   "You permanently deleted order #{$orderId}. Reason: {$reason}",
                othersMessage: "Order #{$orderId} was permanently deleted. Reason: {$reason}",
                type:          'order_deleted',
                relatedType:   'order',
                relatedId:     $orderId
            );
        }

        $this->respond($result['success'], $result['message']);
    }

    #[OA\Get(
        path: '/admin/orders/export-csv',
        summary: 'Export the order list, under the same filters, as a CSV file',
        tags: ['Admin - Manage Orders'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['not_taken','taken','completed','cancelled'])),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/CsvDownload'),
            new OA\Response(response: 401, ref: '#/components/responses/SessionExpired'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
        ]
    )]
    public function exportCsv(): void
    {
        Middleware::requirePermission('can_manage_orders');

        $status = in_array($_GET['status'] ?? '', ['not_taken', 'taken', 'completed', 'cancelled'], true)
            ? $_GET['status'] : '';
        $search = trim($_GET['q'] ?? '');

        $data = OrderModel::getAllForCsvExport(['status' => $status, 'search' => $search]);
        AdminModel::logAction(getCurrentAdminId(), 'export_csv', 'orders', 0, "Exported " . count($data) . " orders.");

        $headers = ['Order ID', 'Customer', 'Email', 'Total', 'Payment', 'Status', 'Handled By', 'Date'];
        $rows    = array_map(fn($r) => [
            $r['order_id'],
            $r['full_name'],
            $r['email'],
            $r['total_amount'],
            $r['payment_method'],
            $r['status'],
            $r['handled_by_name'] ?? '',
            $r['created_at'],
        ], $data);

        $this->sendCsv('orders_' . date('Ymd_His') . '.csv', $headers, $rows);
    }

    // ── Internal private helpers ──────────────────────────────────

    // Note: there used to be a private notifyHigherRanks() here with no caller.
    // The controller calls AdminModel::notifyHigherRanksOnAction directly in six
    // places, so the wrapper was left dead after the migration. Removing it is not
    // cosmetic tidying: a live copy of it still exists in AdminUsersController,
    // and having two — one of them dead — makes a change to the notification rule
    // look finished when it is only half finished.

    /**
     * For every order that releaseExpiredTakenOrders() just reverted: log the action
     * against the PREVIOUS holder (so it appears in their Admin Details), and send
     * the escalation-set notification using the previous holder's rank as the baseline
     * (not the current page viewer's rank — the previous holder may not even be the
     * one whose request triggered this lazy check).
     *
     * @param array<int, array{order_id:int, previous_admin_id:?int}> $reverted
     */
    private function notifyAutoReleasedOrders(array $reverted): void
    {
        foreach ($reverted as $r) {
            $orderId    = $r['order_id'];
            $previousId = $r['previous_admin_id'];

            if ($previousId === null) {
                continue; // no admin to attribute this to (shouldn't normally happen, but guard anyway)
            }

            AdminModel::logAction(
                $previousId,
                'order_auto_released',
                'orders',
                $orderId,
                'Order automatically returned to not_taken after the 4-hour timeout.'
            );

            AdminModel::notifyHigherRanksOnAction(
                actorAdminId:  $previousId,
                permission:    'can_manage_orders',
                title:         'Order Auto-Released',
                selfMessage:   "Your order #{$orderId} was automatically returned to Not Taken after the timeout.",
                othersMessage: "Order #{$orderId} was automatically returned to Not Taken after the timeout.",
                type:          'order_auto_released',
                relatedType:   'order',
                relatedId:     $orderId
            );
        }
    }
}
