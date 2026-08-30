<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Models\AdminNotificationModel;
use OpenApi\Attributes as OA;

/**
 * AdminNotificationController — admin notifications (the shared navbar bell).
 * Extends AdminController, which protects every admin page automatically
 * (startAdminSession + isAdmin in the constructor).
 * Every endpoint returns JSON.
 */
class AdminNotificationController extends AdminController
{
    #[OA\Get(
        path: '/admin/notifications/list',
        summary: "The current admin's notifications plus the unread count (AJAX — JSON)",
        tags: ['Admin - Notifications'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(
                            property: 'notifications',
                            type: 'array',
                            items: new OA\Items(properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'title', type: 'string'),
                                new OA\Property(property: 'message', type: 'string'),
                                new OA\Property(property: 'type', type: 'string'),
                                new OA\Property(property: 'is_read', type: 'integer'),
                                new OA\Property(property: 'created_at', type: 'string'),
                            ])
                        ),
                        new OA\Property(property: 'unread_count', type: 'integer'),
                    ]
                )
            )
        ]
    )]
    public function list(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $adminId     = (int)$_SESSION['admin_id'];
        $items       = AdminNotificationModel::getList($adminId);
        $unreadCount = AdminNotificationModel::countUnread($adminId);

        $this->respond(true, 'OK', [
            'notifications' => $items,
            'unread_count'  => $unreadCount,
        ]);
    }

    #[OA\Post(
        path: '/admin/notifications/mark-read',
        summary: 'Mark a single notification as read (AJAX — JSON)',
        tags: ['Admin - Notifications'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['notification_id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'notification_id', type: 'integer'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'unread_count', type: 'integer'),
                    ]
                )
            )
        ]
    )]
    public function markRead(): void
    {
        $this->beginJsonPost();

        $notifId = (int)($_POST['notification_id'] ?? 0);
        $adminId = (int)$_SESSION['admin_id'];

        if (!$notifId) {
            $this->respond(false, 'Missing notification_id.');
        }

        AdminNotificationModel::markRead($notifId, $adminId);
        $this->respond(true, 'Marked as read.', [
            'unread_count' => AdminNotificationModel::countUnread($adminId),
        ]);
    }

    #[OA\Post(
        path: '/admin/notifications/mark-all-read',
        summary: "Mark all of the admin's notifications as read (AJAX — JSON)",
        tags: ['Admin - Notifications'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['csrf_token'],
                    properties: [
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            )
        ]
    )]
    public function markAllRead(): void
    {
        $this->beginJsonPost();

        $adminId = (int)$_SESSION['admin_id'];
        AdminNotificationModel::markAllRead($adminId);
        $this->respond(true, 'All marked as read.', ['unread_count' => 0]);
    }

    #[OA\Post(
        path: '/admin/notifications/delete-all',
        summary: "Delete all of the admin's notifications (AJAX — JSON)",
        tags: ['Admin - Notifications'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['csrf_token'],
                    properties: [
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            )
        ]
    )]
    public function deleteAll(): void
    {
        $this->beginJsonPost();

        $adminId = (int)$_SESSION['admin_id'];
        AdminNotificationModel::deleteAll($adminId);
        $this->respond(true, 'All notifications deleted.');
    }

    #[OA\Post(
        path: '/admin/notifications/dismiss',
        summary: 'Delete a single notification for the current admin (AJAX — JSON)',
        tags: ['Admin - Notifications'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['notification_id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'notification_id', type: 'integer'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'unread_count', type: 'integer'),
                    ]
                )
            )
        ]
    )]
    public function dismiss(): void
    {
        $this->beginJsonPost();

        $notifId = (int)($_POST['notification_id'] ?? 0);
        $adminId = (int)$_SESSION['admin_id'];

        if (!$notifId) {
            $this->respond(false, 'Missing notification_id.');
        }

        $ok = AdminNotificationModel::dismiss($notifId, $adminId);
        $this->respond($ok, $ok ? 'Dismissed.' : 'Something went wrong.', [
            'unread_count' => AdminNotificationModel::countUnread($adminId),
        ]);
    }
}
