<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\NotificationModel;
use OpenApi\Attributes as OA;

/**
 * NotificationController — user notification management.
 * Moved out of the old handlers/notifications_handler.php.
 * Every endpoint returns JSON.
 */
class NotificationController extends Controller
{
    // ════════════════════════════════════════════════════════
    // GET /notifications/list
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/notifications/list',
        summary: "The current user's notifications",
        tags: ['Store - Notifications'],
        security: [['userSessionAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "The user's notification list and their unread count.",
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                        new OA\Schema(
                            properties: [
                                new OA\Property(
                                    property: 'notifications',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/Notification')
                                ),
                                new OA\Property(
                                    property: 'unread',
                                    type: 'integer',
                                    description: 'Unread count — used for the bell badge.',
                                    example: 3
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
        ]
    )]
    public function list(): void
    {
        // No beginJsonPost here: this is a GET read that changes no state, so there
        // is nothing for a POST check or a CSRF token to protect.
        //
        // ⚠️ Historical note covering the rest of this file: the four state-changing
        // endpoints (markRead · markAllRead · dismiss · deleteAll) called requireAuth
        // + requirePost **with no CSRF check whatsoever**, even though
        // js/features/notifications.js sends the token to every one of them. Which
        // meant `/notifications/delete-all` — deleting all of a user's notifications
        // — was executable from any external site. That was demonstrated with a
        // token-less request that returned {"success":true} before the fix. All four
        // now go through the gate.
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
        summary: 'Mark a single notification as read',
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
    public function markRead(): void
    {
        $this->beginJsonPost();
        $this->requireAuth();

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
        summary: "Mark all of the user's notifications as read",
        tags: ['Store - Notifications'],
        security: [['userSessionAuth' => []]],
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
    public function markAllRead(): void
    {
        $this->beginJsonPost();
        $this->requireAuth();

        $userId = (int)$_SESSION['user_id'];
        NotificationModel::markAllRead($userId);
        $this->respond(true, 'All marked as read.', ['unread_count' => 0]);
    }

    // ════════════════════════════════════════════════════════
    // POST /notifications/dismiss
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/notifications/dismiss',
        summary: 'Delete a single notification',
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
    public function dismiss(): void
    {
        $this->beginJsonPost();
        $this->requireAuth();

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
        summary: "Delete all of the user's notifications",
        tags: ['Store - Notifications'],
        security: [['userSessionAuth' => []]],
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
    public function deleteAll(): void
    {
        $this->beginJsonPost();
        $this->requireAuth();

        $userId = (int)$_SESSION['user_id'];
        NotificationModel::deleteAll($userId);
        $this->respond(true, 'All notifications deleted.');
    }

    // ════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════

    /** Verify the user is logged in. */
    private function requireAuth(): void
    {
        if (isUserLoggedIn()) {
            return;
        }

        // 401, not 200. This used to answer 200 with a `success:false` body — the
        // layer above HTTP saying "denied" while HTTP itself said "done". The effect
        // is not theoretical: the project's own OpenAPI spec declares 401 on these
        // endpoints, and any monitoring that counts errors by status code sees zero
        // while requests are in fact being turned away.
        //
        // Middleware::requireLogin has answered 401 on auth-guarded endpoints since
        // the previous phase; these five were the remaining exception.
        if (!headers_sent()) {
            http_response_code(401);
        }

        $this->respond(false, 'Unauthorized.');
    }

    // requirePost() was removed: it checked the method and nothing else, which is
    // one of the three checks beginJsonPost() already performs. Having it sitting
    // alongside suggested these endpoints were protected while **the CSRF check was
    // entirely absent** from this file — see the comment on list() above.
}
