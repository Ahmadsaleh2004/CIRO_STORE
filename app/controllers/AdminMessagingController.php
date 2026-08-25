<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\AdminModel;
use App\Models\UserModel;
use OpenApi\Attributes as OA;

/**
 * AdminMessagingController — رسائل فردية + Broadcast، مشترك بين كل موديولات
 * الأدمن (Manage Admins الآن، Manage Users لاحقًا) عبر باراميتر target_type.
 */
class AdminMessagingController extends AdminController
{
    #[OA\Post(
        path: '/admin/messaging/notify',
        summary: 'إرسال رسالة فردية لأدمن أو يوزر (AJAX)',
        tags: ['Admin - Messaging'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['target_type', 'target_id', 'title', 'message', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'target_type', type: 'string', description: "'admin' أو 'user'"),
                        new OA\Property(property: 'target_id',   type: 'integer'),
                        new OA\Property(property: 'title',       type: 'string'),
                        new OA\Property(property: 'message',     type: 'string'),
                        new OA\Property(property: 'csrf_token',  type: 'string'),
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
    public function notify(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        // ملاحظة: صلاحية ثابتة حاليًا لأن Manage Admins فقط موجود. لما يُبنى
        // Manage Users، بدّلها لفحص ديناميكي حسب target_type.
        Middleware::requirePermission('can_manage_admins');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

        $targetType = $_POST['target_type'] ?? 'admin';
        $targetId   = (int)($_POST['target_id'] ?? 0);
        $title      = trim($_POST['title'] ?? '');
        $message    = trim($_POST['message'] ?? '');
        $senderId   = getCurrentAdminId();

        if (!$targetId || !$title || !$message) {
            $this->respond(false, 'Missing required fields.');
        }

        if ($targetType === 'admin') {
            $target = AdminModel::getByIdWithPermissions($targetId);
            if (!$target) {
                $this->respond(false, 'Admin not found.');
            }
            AdminModel::sendNotification($targetId, $title, $message, 'direct_message', null, null, $senderId);
            $this->respond(true, 'Message sent.');
        }

        if ($targetType === 'user') {
            $target = UserModel::getByIdForAdmin($targetId);
            if (!$target) {
                $this->respond(false, 'User not found.');
            }
            UserModel::sendNotification($targetId, $title, $message, $senderId);
            $this->respond(true, 'Message sent.');
        }

        $this->respond(false, 'Unsupported target type.');
    }

    #[OA\Post(
        path: '/admin/messaging/broadcast',
        summary: 'إرسال إشعار جماعي (AJAX) — أدمنية حسب الصلاحية + الرتبة، أو يوزرز حسب الحالة',
        tags: ['Admin - Messaging'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['title', 'body', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'target_type', type: 'string', enum: ['admin', 'user'], description: "'admin' (صلاحيات + رتب) أو 'user' (حالات)"),
                        new OA\Property(property: 'title',       type: 'string'),
                        new OA\Property(property: 'body',        type: 'string'),
                        new OA\Property(property: 'perms',       type: 'array', items: new OA\Items(type: 'string'), description: 'للأدمنية فقط'),
                        new OA\Property(property: 'ranks',       type: 'array', items: new OA\Items(type: 'string'), description: 'للأدمنية فقط'),
                        new OA\Property(property: 'statuses',    type: 'array', items: new OA\Items(type: 'string', enum: ['active', 'not_active', 'blocked']), description: 'لليوزرز فقط'),
                        new OA\Property(property: 'csrf_token',  type: 'string'),
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
    public function broadcast(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $targetType = $_POST['target_type'] ?? 'admin';

        // فحص صلاحية ديناميكي حسب target_type — كان مثبّت على can_manage_admins فقط
        Middleware::requirePermission($targetType === 'user' ? 'can_manage_users' : 'can_manage_admins');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

        $senderId = getCurrentAdminId();
        $title    = trim($_POST['title'] ?? '');
        $body     = trim($_POST['body'] ?? '');

        if (!$title || !$body) {
            $this->respond(false, 'Please fill in the title and message.');
        }

        if ($targetType === 'user') {
            $statuses = $_POST['statuses'] ?? [];
            $targets  = UserModel::findByStatuses($statuses);

            if (!$targets) {
                $this->respond(false, 'No matching users found for the selected filters.');
            }

            foreach ($targets as $uId) {
                UserModel::sendNotification((int)$uId, $title, $body, $senderId);
            }

            AdminModel::logAction(
                $senderId, 'broadcast_user_notification', 'system', 0,
                "Broadcast: {$title} (statuses: " . implode(',', $statuses) . ")"
            );

            $this->respond(true, '✅ Broadcast sent to ' . count($targets) . ' user(s).');
        }

        // ── المسار الأصلي للأدمنية (لا تغيّره) ──────────────────────
        $perms = $_POST['perms'] ?? [];
        $ranks = $_POST['ranks'] ?? [];

        $targets = AdminModel::findByPermsAndRanks($perms, $ranks);
        if (!$targets) {
            $this->respond(false, 'No matching admins found for the selected filters.');
        }

        foreach ($targets as $tId) {
            AdminModel::sendNotification((int)$tId, $title, $body, 'broadcast', null, null, $senderId);
        }

        AdminModel::logAction(
            $senderId, 'broadcast_admin_notification', 'system', 0,
            "Broadcast: {$title} (perms: " . implode(',', $perms) . "; ranks: " . implode(',', $ranks) . ")"
        );

        $this->respond(true, '✅ Broadcast sent to ' . count($targets) . ' admin(s).');
    }

    /** نفس نمط respond() المستخدم بـ AdminSupportController بالحرف. */
    private function respond(bool $success, string $message, array $extra = []): never
    {
        echo json_encode(
            array_merge(['success' => $success, 'message' => $message], $extra),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}
