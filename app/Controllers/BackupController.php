<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Models\BackupModel;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

/**
 * BackupController — نسخ احتياطي لقاعدة البيانات (Root admin ID=1 فقط — لا صلاحية can_*).
 * كل الـ methods تبدأ بفحص المعرّف وفق النمط المستخدم بـ
 * AdminManageAdminsController::exportCsv() بالحرف.
 */
class BackupController extends AdminController
{
    #[OA\Get(
        path: '/admin/backup',
        summary: 'صفحة إدارة النسخ الاحتياطي (Root admin ID=1 فقط)',
        tags: ['Admin - Backup'],
        security: [['adminSessionAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'صفحة HTML بالقائمة وزر إنشاء نسخة')]
    )]
    public function index(): void
    {
        if (getCurrentAdminId() !== 1) {
            http_response_code(403);
            die('Unauthorized — Root admin only (ID=1)');
        }

        $this->adminView('backup', [
            'pageTitle' => 'Backup DB',
            'backups'   => BackupModel::listBackups(),
        ]);
    }

    #[OA\Post(
        path: '/admin/backup/create',
        summary: 'إنشاء نسخة احتياطية جديدة (AJAX — JSON، Root admin ID=1 فقط)',
        tags: ['Admin - Backup'],
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
                        new OA\Property(property: 'filename', type: 'string'),
                    ]
                )
            )
        ]
    )]
    public function create(): void
    {
        if (getCurrentAdminId() !== 1) {
            http_response_code(403);
            die('Unauthorized — Root admin only (ID=1)');
        }
        $this->beginJsonPost();

        $adminId = getCurrentAdminId();
        $result  = BackupModel::createBackup();

        if (!$result['success']) {
            $this->respond(false, $result['message']);
        }

        AdminModel::logAction(
            $adminId,
            'create_backup',
            'system',
            0,
            "Created backup: {$result['filename']}"
        );
        AdminModel::sendNotification(
            $adminId,
            'Backup Created',
            "A new database backup was created: {$result['filename']}",
            'backup_created'
        );

        $this->respond(true, $result['message'], ['filename' => $result['filename']]);
    }

    #[OA\Get(
        path: '/admin/backup/download',
        summary: 'تحميل نسخة احتياطية (Root admin ID=1 فقط — منع Path Traversal)',
        tags: ['Admin - Backup'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'file', in: 'query', required: true, schema: new OA\Schema(type: 'string'), description: 'اسم الملف فقط — يُرفض أي مسار خارج مجلد النسخ'),
        ],
        responses: [new OA\Response(response: 200, description: 'ملف SQL للتحميل')]
    )]
    public function download(): void
    {
        if (getCurrentAdminId() !== 1) {
            http_response_code(403);
            die('Unauthorized — Root admin only (ID=1)');
        }

        $filename = $_GET['file'] ?? '';
        $path     = BackupModel::getBackupPath($filename);

        if ($path === null) {
            http_response_code(403);
            die('Invalid backup file.');
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    #[OA\Post(
        path: '/admin/backup/delete',
        summary: 'حذف نسخة احتياطية (AJAX — JSON، Root admin ID=1 فقط)',
        tags: ['Admin - Backup'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['file', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'file',       type: 'string'),
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
    public function delete(): void
    {
        if (getCurrentAdminId() !== 1) {
            http_response_code(403);
            die('Unauthorized — Root admin only (ID=1)');
        }
        $this->beginJsonPost();

        $filename = $_POST['file'] ?? '';
        if (!BackupModel::deleteBackup($filename)) {
            $this->respond(false, 'Backup not found or invalid name.');
        }

        AdminModel::logAction(
            getCurrentAdminId(),
            'delete_backup',
            'system',
            0,
            "Deleted backup: {$filename}"
        );

        $this->respond(true, 'Backup deleted.');
    }
}
