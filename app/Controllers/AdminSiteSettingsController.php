<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\SettingsModel;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

/**
 * AdminSiteSettingsController — صفحة إعدادات الموقع للأدمن.
 * يرث من AdminController الذي يتحقق من تسجيل دخول الأدمن تلقائياً.
 */
#[OA\PathItem(path: '/admin/settings')]
#[OA\Post(
    path: '/admin/settings',
    summary: 'حفظ إعدادات الموقع (AJAX) — الحقول المالية/العملة تُقبل فقط لصاحب can_manage_checkout_settings',
    tags: ['Admin Site Settings'],
    security: [['adminSessionAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/x-www-form-urlencoded',
            schema: new OA\Schema(
                required: ['csrf_token'],
                properties: [
                    new OA\Property(property: 'csrf_token',           type: 'string'),
                    new OA\Property(property: 'footer_text',          type: 'string'),
                    new OA\Property(property: 'facebook_url',         type: 'string'),
                    new OA\Property(property: 'instagram_url',        type: 'string'),
                    new OA\Property(property: 'snapchat_url',         type: 'string'),
                    new OA\Property(property: 'whatsapp_number',      type: 'string'),
                    new OA\Property(property: 'tiktok_url',           type: 'string'),
                    new OA\Property(property: 'twitter_x_url',        type: 'string'),
                    new OA\Property(property: 'google_maps_url',      type: 'string'),
                    new OA\Property(property: 'copyright_text',       type: 'string'),
                    new OA\Property(property: 'phone_number',         type: 'string'),
                    new OA\Property(property: 'working_hours',        type: 'string'),
                    new OA\Property(property: 'employees_count',      type: 'integer'),
                    new OA\Property(property: 'site_url',             type: 'string'),
                    new OA\Property(property: 'return_policy',        type: 'string'),
                    new OA\Property(property: 'privacy_policy',       type: 'string'),
                    new OA\Property(property: 'terms_and_conditions', type: 'string'),
                    new OA\Property(property: 'default_currency', type: 'string', description: 'فقط لصاحب can_manage_checkout_settings'),
                    new OA\Property(property: 'default_language',  type: 'string', description: 'فقط لصاحب can_manage_checkout_settings'),
                ]
            )
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'نجاح أو فشل الحفظ',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'message', type: 'string'),
                ]
            )
        ),
    ]
)]
class AdminSiteSettingsController extends AdminController
{
    #[OA\Get(
        path: '/admin/settings',
        summary: 'عرض صفحة إعدادات الموقع الحالية',
        tags: ['Admin Site Settings'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML — يتطلب صلاحية can_edit_site_content'),
            new OA\Response(response: 302, description: 'إعادة توجيه لـ /admin/login'),
            new OA\Response(response: 403, description: 'ممنوع — لا يملك can_edit_site_content'),
        ]
    )]
    public function index(): void
    {
        Middleware::requirePermission('can_edit_site_content');

        $settings = SettingsModel::get();

        $this->adminView('settings', [
            'pageTitle'       => 'Site Configuration',
            'settings'        => $settings,
            'canEditCheckout' => hasPermission('can_manage_checkout_settings'),
        ]);
    }

    public function save(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requirePermission('can_edit_site_content');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            $this->respond(false, 'Invalid CSRF token, please refresh and try again.');
        }

        // ── جلب القيم الحالية قبل التحديث (للمقارنة) ─────────────────────
        $before = SettingsModel::get();

        // ── بناء قائمة الحقول المسموحة حسب صلاحيات الأدمن ────────────────
        // التحقق الأمني يصير هنا في الكونترولر، وليس فقط بالـ View.
        // حتى لو زوّر المستخدم الفورم وأرسل default_currency مباشرة،
        // الكونترولر لن يقرأها إذا لم تكن ضمن $fields المسموحة.
        $fields = SettingsModel::GENERAL_FIELDS;
        if (hasPermission('can_manage_checkout_settings')) {
            $fields = array_merge($fields, SettingsModel::CHECKOUT_FIELDS);
        }

        $data = [];
        foreach ($fields as $f) {
            $data[$f] = trim($_POST[$f] ?? '');
        }

        if (!SettingsModel::update($data)) {
            $this->respond(false, 'Could not save settings. Please try again.');
        }

        $adminId = (int) $_SESSION['admin_id'];

        // ── حصر الحقول اللي تغيّرت فعليًا ──────────────────────────────
        $changedFields = [];
        foreach ($data as $key => $newVal) {
            $oldVal = $before[$key] ?? null;
            if ((string)$oldVal !== (string)$newVal) {
                $changedFields[] = $key;
            }
        }
        $detailsText = $changedFields
            ? 'Changed fields: ' . implode(', ', $changedFields)
            : 'No field values actually changed.';

        AdminModel::logAction($adminId, 'update_site_settings', 'website_settings', 1, $detailsText);

        // ── إشعار جماعي لكل أدمن رتبته أعلى ويملك can_edit_site_content (باستثناء الجذر) ──────────
        $rankOrder = ['D' => 1, 'C' => 2, 'B' => 3, 'A' => 4];
        $myRank    = getAdminRole();
        $myRankVal = $rankOrder[$myRank] ?? 0;
        $higherRanks = array_keys(array_filter($rankOrder, fn($v) => $v > $myRankVal));
        $rootId      = AdminModel::getRootAdminId();

        if ($higherRanks) {
            $recipients = AdminModel::findByPermsAndRanks(['can_edit_site_content'], $higherRanks);
            foreach ($recipients as $recipientId) {
                $recipientId = (int)$recipientId;
                if ($recipientId === $adminId) continue;
                if ($rootId !== null && $recipientId === $rootId) continue;

                AdminModel::sendNotification(
                    $recipientId, 'Site Settings Updated',
                    "Site configuration was updated. {$detailsText}",
                    'settings_updated', 'website_settings', 1, $adminId
                );
            }
        }

        $this->respond(true, 'Site Configuration saved successfully.');
    }
}
