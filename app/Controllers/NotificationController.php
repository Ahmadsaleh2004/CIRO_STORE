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
            new OA\Response(
                response: 200,
                description: 'قائمة إشعارات المستخدم وعدد غير المقروء منها.',
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
                                    description: 'عدد غير المقروء — يُستعمل لشارة الجرس.',
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
        // لا beginJsonPost هنا: هذه قراءة على GET ولا تعدّل حالة، فلا
        // معنى لفحص POST ولا لتوكن CSRF.
        //
        // ⚠️ ملاحظة تاريخية تخصّ بقية هذا الملف: النقاط الأربع المعدِّلة
        // للحالة (markRead · markAllRead · dismiss · deleteAll) كانت
        // تستدعي requireAuth + requirePost **بلا أي فحص CSRF إطلاقاً**،
        // رغم أن js/features/notifications.js يرسل التوكن في كل منها.
        // أي أن `/notifications/delete-all` — حذف كل إشعارات المستخدم —
        // كان قابلاً للتنفيذ من أي موقع خارجي. أُثبت ذلك بطلب بلا توكن
        // رجع {"success":true} قبل الإصلاح. الأربع تمرّ الآن بالبوابة.
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
        responses: [
            new OA\Response(
                response: 200,
                description: 'نتيجة العملية. الحقل success يفصل النجاح عن الفشل — كود HTTP يبقى 200 في الحالتين. وعند فشل CSRF يحمل الجسم error_code=csrf_invalid.',
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
        summary: 'تعليم كل إشعارات المستخدم كمقروءة',
        tags: ['Store - Notifications'],
        security: [['userSessionAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'نتيجة العملية. الحقل success يفصل النجاح عن الفشل — كود HTTP يبقى 200 في الحالتين. وعند فشل CSRF يحمل الجسم error_code=csrf_invalid.',
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
        responses: [
            new OA\Response(
                response: 200,
                description: 'نتيجة العملية. الحقل success يفصل النجاح عن الفشل — كود HTTP يبقى 200 في الحالتين. وعند فشل CSRF يحمل الجسم error_code=csrf_invalid.',
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
        summary: 'حذف كل إشعارات المستخدم',
        tags: ['Store - Notifications'],
        security: [['userSessionAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'نتيجة العملية. الحقل success يفصل النجاح عن الفشل — كود HTTP يبقى 200 في الحالتين. وعند فشل CSRF يحمل الجسم error_code=csrf_invalid.',
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

    /** التحقق من تسجيل الدخول */
    private function requireAuth(): void
    {
        if (isUserLoggedIn()) {
            return;
        }

        // 401 لا 200. كانت ترد 200 بجسم `success:false` — أي أن الطبقة
        // التي فوق HTTP تقول «مرفوض» بينما HTTP نفسه يقول «تمّ». الأثر
        // ليس نظرياً: مواصفة OpenAPI الخاصة بالمشروع تعلن 401 على هذه
        // النقاط، وأي مراقبة تعدّ الأخطاء برمز الحالة ترى صفراً بينما
        // ترتدّ الطلبات فعلاً.
        //
        // Middleware::requireLogin ترد 401 على النقاط المحروسة بـauth
        // منذ المرحلة السابقة؛ هذه النقاط الخمس كانت الاستثناء الباقي.
        if (!headers_sent()) {
            http_response_code(401);
        }

        $this->respond(false, 'Unauthorized.');
    }

    // حُذفت requirePost(): كانت تفحص الطريقة وحدها، وهذا ما تفعله
    // beginJsonPost() ضمن ثلاثة فحوص. وجودها بجانبها كان يوحي بأن
    // النقاط محميّة بينما كان **فحص CSRF غائباً تماماً** عن هذا الملف —
    // انظر تعليق list() أدناه.
}
