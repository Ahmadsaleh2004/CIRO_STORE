<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\NotificationModel;
use OpenApi\Attributes as OA;

/**
 * NotificationController — إدارة إشعارات المستخدمين
 * منقول من handlers/notifications_handler.php القديم
 * كل الـ endpoints ترجع JSON
 */
class NotificationController extends Controller
{
    // ════════════════════════════════════════════════════════
    // GET /notifications/list
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/notifications/list',
        summary: 'إشعارات المستخدم الحالي',
        tags: ['Store - Notifications'],
        security: [['userSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'JSON — {success, message, notifications[], unread}'),
        ]
    )]
    public function list(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->requireAuth();

        $userId      = (int)$_SESSION['user_id'];
        $items       = NotificationModel::getList($userId);
        $unreadCount = NotificationModel::countUnread($userId);

        $this->respond(true, 'OK', [
            'notifications' => $items,
            'unread_count'  => $unreadCount,
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /notifications/mark-read
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/notifications/mark-read',
        summary: 'تعليم إشعار واحد كمقروء',
        tags: ['Store - Notifications'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function markRead(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->requireAuth();
        $this->requirePost();

        $notifId = (int)($_POST['notification_id'] ?? 0);
        $userId  = (int)$_SESSION['user_id'];

        if (!$notifId) {
            $this->respond(false, 'Missing notification_id.');
        }

        NotificationModel::markRead($notifId, $userId);
        $this->respond(true, 'Marked as read.', [
            'unread_count' => NotificationModel::countUnread($userId),
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /notifications/mark-all-read
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/notifications/mark-all-read',
        summary: 'تعليم كل إشعارات المستخدم كمقروءة',
        tags: ['Store - Notifications'],
        security: [['userSessionAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function markAllRead(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->requireAuth();
        $this->requirePost();

        $userId = (int)$_SESSION['user_id'];
        NotificationModel::markAllRead($userId);
        $this->respond(true, 'All marked as read.', ['unread_count' => 0]);
    }

    // ════════════════════════════════════════════════════════
    // POST /notifications/dismiss
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/notifications/dismiss',
        summary: 'حذف إشعار واحد',
        tags: ['Store - Notifications'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function dismiss(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->requireAuth();
        $this->requirePost();

        $notifId = (int)($_POST['notification_id'] ?? 0);
        $userId  = (int)$_SESSION['user_id'];

        if (!$notifId) {
            $this->respond(false, 'Missing notification_id.');
        }

        NotificationModel::dismiss($notifId, $userId);
        $this->respond(true, 'Dismissed.', [
            'unread_count' => NotificationModel::countUnread($userId),
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /notifications/delete-all
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/notifications/delete-all',
        summary: 'حذف كل إشعارات المستخدم',
        tags: ['Store - Notifications'],
        security: [['userSessionAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function deleteAll(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->requireAuth();
        $this->requirePost();

        $userId = (int)$_SESSION['user_id'];
        NotificationModel::deleteAll($userId);
        $this->respond(true, 'All notifications deleted.');
    }

    // ════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════

    /** التحقق من تسجيل الدخول */
    private function requireAuth(): void
    {
        if (!isUserLoggedIn()) {
            $this->respond(false, 'Unauthorized.');
        }
    }

    /** التحقق من POST */
    private function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }
    }
}
