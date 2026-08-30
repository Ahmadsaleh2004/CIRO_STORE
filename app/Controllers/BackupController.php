<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\ErrorPage;
use App\Core\Middleware;
use App\Models\BackupModel;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

/**
 * BackupController — database backups (root only — no can_* permission).
 *
 * ⚠️ The condition `getCurrentAdminId() !== 1` used to be hand-written in four
 * places — meaning the right to download the entire database hung on a
 * **position** in the id sequence rather than on a person. And deleteAdmin
 * renumbered ids on every delete, so removing a single row was enough to hand
 * that right to somebody else, silently.
 *
 * The renumbering is gone, and the condition is now Middleware::requireRoot(),
 * which keys off rank A — one definition of "root" across the whole project
 * instead of three competing ones.
 */
class BackupController extends AdminController
{
    #[OA\Get(
        path: '/admin/backup',
        summary: 'Backup management page (root admin only)',
        tags: ['Admin - Backup'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 302, ref: '#/components/responses/RedirectToLogin'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function index(): void
    {
        Middleware::requireRoot();

        $this->adminView('backup', [
            'pageTitle' => 'Backup DB',
            'backups'   => BackupModel::listBackups(),
        ]);
    }

    #[OA\Post(
        path: '/admin/backup/create',
        summary: 'Create a new backup (AJAX — JSON, root admin only)',
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
        // beginJsonPost comes first deliberately: it is what sets the JSON header,
        // and without it a permission refusal left as raw text, which backup.js
        // reported as "Network error" instead of the real reason. Checking CSRF
        // before permission weakens nothing — both are required either way.
        $this->beginJsonPost();

        Middleware::requireRoot();

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
        summary: 'Download a backup (root admin only — path traversal blocked)',
        tags: ['Admin - Backup'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'file', in: 'query', required: true, schema: new OA\Schema(type: 'string'), description: 'File name only — any path outside the backup directory is rejected'),
        ],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/CsvDownload'),
            new OA\Response(response: 401, ref: '#/components/responses/SessionExpired'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
        ]
    )]
    public function download(): void
    {
        Middleware::requireRoot();

        $filename = $_GET['file'] ?? '';
        $path     = BackupModel::getBackupPath($filename);

        // getBackupPath returns null in three cases: a name containing a path · a
        // name that does not match the pattern · a file that does not exist. From
        // the admin's side all three read as "the requested file is not there", and
        // a 404 reveals less about the validation rule than a 403 would.
        if ($path === null) {
            ErrorPage::notFound('backup/download: invalid or missing file');
        }

        // $path is the validated output of getBackupPath (basename + strict
        // pattern + is_file), and a null was already rejected above this line. What
        // reaches here is never a user-supplied name but a path resolved inside the
        // backup directory.
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
        header('Content-Length: ' . filesize($path));
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
        readfile($path);
        exit;
    }

    #[OA\Post(
        path: '/admin/backup/delete',
        summary: 'Delete a backup (AJAX — JSON, root admin only)',
        tags: ['Admin - Backup'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['file', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string'),
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
    public function delete(): void
    {
        // As above: the JSON header before any response, so backup.js never gets raw text.
        $this->beginJsonPost();

        Middleware::requireRoot();

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
