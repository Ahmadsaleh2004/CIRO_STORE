<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\ErrorPage;
use App\Core\Middleware;
use App\Models\BackupModel;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

/**
 * BackupController — نسخ احتياطي لقاعدة البيانات (الروت وحده — لا صلاحية can_*).
 *
 * ⚠️ كان الشرط `getCurrentAdminId() !== 1` مكتوباً بيده أربع مرّات —
 * أي أن حقّ تنزيل قاعدة البيانات كاملةً كان معلَّقاً بـ**موضع** في طابور
 * المعرّفات لا بشخص. وdeleteAdmin كانت تزحف بالمعرّفات عند كل حذف، فحذف
 * صفٍّ واحد كان كفيلاً بنقل الحقّ إلى شخص آخر بصمت.
 *
 * الزحف حُذف، والشرط صار Middleware::requireRoot() المعتمدة على رتبة A —
 * تعريف واحد للروت في المشروع كله بدل ثلاثة متنافسة.
 */
class BackupController extends AdminController
{
    #[OA\Get(
        path: '/admin/backup',
        summary: 'صفحة إدارة النسخ الاحتياطي (Root admin ID=1 فقط)',
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
        // beginJsonPost أولاً عن قصد: هي التي تضبط ترويسة JSON، وبدونها
        // كان رفض الصلاحية يخرج نصّاً خاماً إلى backup.js فيراه «Network
        // error» بدل السبب الحقيقي. وفحص الـCSVF قبل الصلاحية لا يضعف
        // شيئاً — كلاهما شرط لازم.
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
        summary: 'تحميل نسخة احتياطية (Root admin ID=1 فقط — منع Path Traversal)',
        tags: ['Admin - Backup'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'file', in: 'query', required: true, schema: new OA\Schema(type: 'string'), description: 'اسم الملف فقط — يُرفض أي مسار خارج مجلد النسخ'),
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

        // getBackupPath تُرجع null لثلاث حالات: اسم فيه مسار · اسم لا
        // يطابق النمط · ملف غير موجود. من زاوية الأدمن كلها «الملف
        // المطلوب غير موجود»، و404 يكشف عن قاعدة التحقق أقل من 403.
        if ($path === null) {
            ErrorPage::notFound('backup/download: ملف غير صالح أو غير موجود');
        }

        // $path ناتج getBackupPath المُتحقَّق منه (basename + نمط صارم +
        // is_file)، ورُفض قبل هذا السطر إن كان null. لا يصل هنا اسم من
        // المستخدم بل مسار مفهرس داخل مجلد النسخ.
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
        // كسابقتها: ترويسة JSON قبل أي ردّ، فلا يصل backup.js نصّ خام.
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
