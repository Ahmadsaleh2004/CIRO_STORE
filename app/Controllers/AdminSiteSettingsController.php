<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\SettingsModel;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

/**
 * AdminSiteSettingsController — the admin's site settings page.
 * Extends AdminController, which verifies the admin login automatically.
 */
#[OA\PathItem(path: '/admin/settings')]
#[OA\Post(
    path: '/admin/settings',
    summary: 'Save site settings (AJAX) — the financial and currency fields are accepted only from a holder of can_manage_checkout_settings',
    tags: ['Admin - Site Settings'],
    security: [['adminSessionAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/x-www-form-urlencoded',
            schema: new OA\Schema(
                required: ['csrf_token'],
                properties: [
                    new OA\Property(property: 'csrf_token', type: 'string'),
                    new OA\Property(property: 'footer_text', type: 'string'),
                    new OA\Property(property: 'facebook_url', type: 'string'),
                    new OA\Property(property: 'instagram_url', type: 'string'),
                    new OA\Property(property: 'snapchat_url', type: 'string'),
                    new OA\Property(property: 'whatsapp_number', type: 'string'),
                    new OA\Property(property: 'tiktok_url', type: 'string'),
                    new OA\Property(property: 'twitter_x_url', type: 'string'),
                    new OA\Property(property: 'google_maps_url', type: 'string'),
                    new OA\Property(property: 'copyright_text', type: 'string'),
                    new OA\Property(property: 'phone_number', type: 'string'),
                    new OA\Property(property: 'working_hours', type: 'string'),
                    new OA\Property(property: 'employees_count', type: 'integer'),
                    new OA\Property(property: 'site_url', type: 'string'),
                    new OA\Property(property: 'return_policy', type: 'string'),
                    new OA\Property(property: 'privacy_policy', type: 'string'),
                    new OA\Property(property: 'terms_and_conditions', type: 'string'),
                    new OA\Property(property: 'default_currency', type: 'string', description: 'Only for a holder of can_manage_checkout_settings'),
                    new OA\Property(property: 'default_language', type: 'string', description: 'Only for a holder of can_manage_checkout_settings'),
                ]
            )
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Whether the save succeeded',
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
        summary: 'Show the current site settings page',
        tags: ['Admin - Site Settings'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'HTML page — requires the can_edit_site_content permission'),
            new OA\Response(response: 302, description: 'Redirect to /admin/login'),
            new OA\Response(response: 403, description: 'Forbidden — the admin lacks can_edit_site_content'),
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
        $this->beginJsonPost();
        Middleware::requirePermission('can_edit_site_content');

        // ── Read the current values before updating, so they can be compared ──
        $before = SettingsModel::get();

        // ── Build the allow-list of fields from the admin's permissions ───────
        // The security check happens here in the controller, not only in the view.
        // Even if someone forges the form and posts default_currency directly, the
        // controller will not read it unless it is in the permitted $fields.
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

        // ── Narrow down to the fields that actually changed ───────────────────
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

        // ── Broadcast to every higher-ranked admin holding can_edit_site_content (root excluded) ──
        $rankOrder = ['D' => 1, 'C' => 2, 'B' => 3, 'A' => 4];
        $myRank    = getAdminRole();
        $myRankVal = $rankOrder[$myRank] ?? 0;
        $higherRanks = array_keys(array_filter($rankOrder, fn($v) => $v > $myRankVal));
        $rootId      = AdminModel::getRootAdminId();

        if ($higherRanks) {
            $recipients = AdminModel::findByPermsAndRanks(['can_edit_site_content'], $higherRanks);
            foreach ($recipients as $recipientId) {
                $recipientId = (int)$recipientId;
                if ($recipientId === $adminId) {
                    continue;
                }
                if ($rootId !== null && $recipientId === $rootId) {
                    continue;
                }

                AdminModel::sendNotification(
                    $recipientId,
                    'Site Settings Updated',
                    "Site configuration was updated. {$detailsText}",
                    'settings_updated',
                    'website_settings',
                    1,
                    $adminId
                );
            }
        }

        $this->respond(true, 'Site Configuration saved successfully.');
    }
}
