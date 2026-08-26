<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\SupportModel;
use App\Models\NotificationModel;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

/**
 * AdminSupportController — صفحة رسائل الدعم الفني للأدمن.
 * يرث من AdminController الذي يتحقق من تسجيل دخول الأدمن تلقائياً.
 */
// ملاحظة: لا #[OA\PathItem] هنا. كانت ثلاثة منها تعلن المسارات مجرّدة
// بلا عملية، فكان swagger-php يدمج أول #[OA\Post] في كل واحدة — فيخرج
// المسارات الثلاثة بنفس الوصف ونفس operationId. سمات Get/Post أدناه
// وعلى الميثودات تعلن المسارات بنفسها فلا حاجة لها.
#[OA\Post(
    path: '/admin/support/reply',
    summary: 'إرسال رد على رسالة دعم كإشعار للمستخدم صاحب الرسالة',
    tags: ['Admin Support'],
    security: [['adminSessionAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/x-www-form-urlencoded',
            schema: new OA\Schema(
                required: ['csrf_token', 'user_id', 'reply_text'],
                properties: [
                    new OA\Property(property: 'csrf_token', type: 'string'),
                    new OA\Property(property: 'user_id',    type: 'integer'),
                    new OA\Property(property: 'reply_text', type: 'string'),
                ]
            )
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'نجاح أو فشل الإرسال',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'message', type: 'string'),
                ]
            )
        ),
    ]
)]
#[OA\Post(
    path: '/admin/support/delete',
    summary: 'حذف رسالة دعم فني نهائياً',
    tags: ['Admin Support'],
    security: [['adminSessionAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/x-www-form-urlencoded',
            schema: new OA\Schema(
                required: ['csrf_token', 'message_id'],
                properties: [
                    new OA\Property(property: 'csrf_token',  type: 'string'),
                    new OA\Property(property: 'message_id',  type: 'integer'),
                ]
            )
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'نجاح أو فشل الحذف',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'message', type: 'string'),
                ]
            )
        ),
    ]
)]
class AdminSupportController extends AdminController
{
    #[OA\Get(
        path: '/admin/support',
        summary: 'قائمة رسائل الدعم الفني (بحث + Pagination)، تُحدّد كل الرسائل كمقروءة تلقائياً عند الفتح',
        tags: ['Admin Support'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'q',
                description: 'بحث بالاسم / الإيميل / نص الرسالة',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'page',
                description: 'رقم الصفحة (Pagination)',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 1)
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML — يتطلب صلاحية can_manage_support'),
            new OA\Response(response: 302, description: 'إعادة توجيه لـ /admin/login'),
            new OA\Response(response: 403, description: 'ممنوع — لا يملك can_manage_support'),
        ]
    )]
    public function index(): void
    {
        Middleware::requirePermission('can_manage_support');

        // تحديد كل الرسائل كمقروءة عند فتح الصفحة — نفس سلوك القديم بالحرف
        SupportModel::markAllNotified();

        $search      = trim($_GET['q'] ?? '');
        $perPage     = 15;
        $currentPage = max(1, (int) ($_GET['page'] ?? 1));
        $offset      = ($currentPage - 1) * $perPage;

        $totalMessages = SupportModel::countAll($search);
        $totalPages    = max(1, (int) ceil($totalMessages / $perPage));
        $messages      = SupportModel::getPage($search, $perPage, $offset);

        $this->adminView('support', [
            'pageTitle'     => 'Support Messages',
            'messages'      => $messages,
            'search'        => $search,
            'totalMessages' => $totalMessages,
            'currentPage'   => $currentPage,
            'totalPages'    => $totalPages,
        ]);
    }

    public function reply(): void
    {
        $this->beginJsonPost();
        Middleware::requirePermission('can_manage_support');

        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $replyText    = trim($_POST['reply_text'] ?? '');

        if (!$targetUserId || !$replyText) {
            $this->respond(false, 'Missing fields.');
        }

        if (!SupportModel::userExists($targetUserId)) {
            $this->respond(false, 'User not found.');
        }

        $adminId = (int) $_SESSION['admin_id'];

        // TODO: Rate limiting على عملية الرد — تذكرة منفصلة (غير موجود بالمشروع الجديد حالياً)

        NotificationModel::insert($targetUserId, 'Support Response', $replyText, $adminId);
        AdminModel::logAction($adminId, 'reply_support_message', 'support', $targetUserId, "Replied: {$replyText}");

        // ── إشعار جماعي لكل أدمن رتبته أعلى ويملك can_manage_support (باستثناء الجذر) ──────────
        $rankOrder = ['D' => 1, 'C' => 2, 'B' => 3, 'A' => 4];
        $myRank    = getAdminRole();
        $myRankVal = $rankOrder[$myRank] ?? 0;
        $higherRanks = array_keys(array_filter($rankOrder, fn($v) => $v > $myRankVal));
        $rootId      = AdminModel::getRootAdminId();

        if ($higherRanks) {
            $recipients = AdminModel::findByPermsAndRanks(['can_manage_support'], $higherRanks);
            foreach ($recipients as $recipientId) {
                $recipientId = (int)$recipientId;
                if ($recipientId === $adminId) continue;
                if ($rootId !== null && $recipientId === $rootId) continue;

                AdminModel::sendNotification(
                    $recipientId, 'Support Message Replied',
                    "A support message was replied to. Reply: {$replyText}",
                    'support_replied', 'user', $targetUserId, $adminId
                );
            }
        }

        $this->respond(true, 'Reply sent successfully.');
    }

    public function delete(): void
    {
        $this->beginJsonPost();
        Middleware::requirePermission('can_manage_support');

        $msgId = (int) ($_POST['message_id'] ?? 0);
        if (!$msgId) {
            $this->respond(false, 'Invalid ID.');
        }

        // ── جلب محتوى الرسالة قبل الحذف للتفاصيل ──────────────────────
        $msgText = SupportModel::getMessageText($msgId) ?? 'N/A';

        if (!SupportModel::delete($msgId)) {
            $this->respond(false, 'Could not delete message.');
        }

        $adminId = (int) $_SESSION['admin_id'];
        AdminModel::logAction($adminId, 'delete_support_message', 'support', $msgId, "Deleted message: {$msgText}");

        // ── إشعار جماعي لكل أدمن رتبته أعلى ويملك can_manage_support (باستثناء الجذر) ──────────
        $rankOrder = ['D' => 1, 'C' => 2, 'B' => 3, 'A' => 4];
        $myRank    = getAdminRole();
        $myRankVal = $rankOrder[$myRank] ?? 0;
        $higherRanks = array_keys(array_filter($rankOrder, fn($v) => $v > $myRankVal));
        $rootId      = AdminModel::getRootAdminId();

        if ($higherRanks) {
            $recipients = AdminModel::findByPermsAndRanks(['can_manage_support'], $higherRanks);
            foreach ($recipients as $recipientId) {
                $recipientId = (int)$recipientId;
                if ($recipientId === $adminId) continue;
                if ($rootId !== null && $recipientId === $rootId) continue;

                AdminModel::sendNotification(
                    $recipientId, 'Support Message Deleted',
                    "A support message was deleted. Content: {$msgText}",
                    'support_deleted', 'contact_messages', $msgId, $adminId
                );
            }
        }

        $this->respond(true, 'Message deleted.');
    }
}
