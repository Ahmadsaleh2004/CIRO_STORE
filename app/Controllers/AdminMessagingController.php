<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\AdminModel;
use App\Models\UserModel;
use OpenApi\Attributes as OA;

/**
 * AdminMessagingController — direct messages and broadcasts, shared across every
 * admin module (Manage Admins today, Manage Users later) via the target_type
 * parameter.
 */
class AdminMessagingController extends AdminController
{
    #[OA\Post(
        path: '/admin/messaging/notify',
        summary: 'Send a direct message to an admin or a user (AJAX)',
        tags: ['Admin - Messaging'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['target_type', 'target_id', 'title', 'message', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'target_type', type: 'string', description: "'admin' or 'user'"),
                        new OA\Property(property: 'target_id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'message', type: 'string'),
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
    public function notify(): void
    {
        $this->beginJsonPost();
        // Note: the permission is fixed for now because only Manage Admins exists.
        // Once Manage Users is built, switch this to a dynamic check on target_type.
        Middleware::requirePermission('can_manage_admins');

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
        summary: 'Send a broadcast (AJAX) — admins by permission and rank, or users by status',
        tags: ['Admin - Messaging'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['title', 'body', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'target_type', type: 'string', enum: ['admin', 'user'], description: "'admin' (permissions + ranks) or 'user' (statuses)"),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'body', type: 'string'),
                        new OA\Property(property: 'perms', type: 'array', items: new OA\Items(type: 'string'), description: 'Admins only'),
                        new OA\Property(property: 'ranks', type: 'array', items: new OA\Items(type: 'string'), description: 'Admins only'),
                        new OA\Property(property: 'statuses', type: 'array', items: new OA\Items(type: 'string', enum: ['active', 'not_active', 'blocked']), description: 'Users only'),
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
    public function broadcast(): void
    {
        // Validation first, permission second — the order is deliberate, and it
        // used to be the other way round. The permission required here is derived
        // from $_POST['target_type'], that is, from input not yet validated. It was
        // not exploitable (the request fails at CSRF regardless), but deriving a
        // permission decision from untrusted data before validating it is not an
        // order worth building on.
        $this->beginJsonPost();

        $targetType = $_POST['target_type'] ?? 'admin';

        // Dynamic permission check driven by target_type — this used to be pinned to can_manage_admins alone
        Middleware::requirePermission($targetType === 'user' ? 'can_manage_users' : 'can_manage_admins');

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
                $senderId,
                'broadcast_user_notification',
                'system',
                0,
                "Broadcast: {$title} (statuses: " . implode(',', $statuses) . ")"
            );

            $this->respond(true, '✅ Broadcast sent to ' . count($targets) . ' user(s).');
        }

        // ── The original admin path (leave it as it is) ─────────────
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
            $senderId,
            'broadcast_admin_notification',
            'system',
            0,
            "Broadcast: {$title} (perms: " . implode(',', $perms) . "; ranks: " . implode(',', $ranks) . ")"
        );

        $this->respond(true, '✅ Broadcast sent to ' . count($targets) . ' admin(s).');
    }
}
