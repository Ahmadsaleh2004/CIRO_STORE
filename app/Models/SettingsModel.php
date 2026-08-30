<?php

namespace App\Models;

use App\Core\Model;
use Exception;

/**
 * SettingsModel — covers the website_settings table (always a single row, id=1).
 */
class SettingsModel extends Model
{
    /** The fields editable from the general form, with no extra permission required. */
    public const GENERAL_FIELDS = [
        'footer_text', 'facebook_url', 'instagram_url', 'snapchat_url',
        'whatsapp_number', 'tiktok_url', 'twitter_x_url', 'google_maps_url',
        'copyright_text', 'phone_number', 'working_hours', 'employees_count',
        'site_url', 'return_policy', 'privacy_policy', 'terms_and_conditions',
    ];

    /** Extra fields shown only to a holder of can_manage_checkout_settings. */
    public const CHECKOUT_FIELDS = ['default_currency', 'default_language'];

    /**
     * Fetch the current settings row (there is only ever one, id=1).
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        try {
            $stmt = self::db()->query("SELECT * FROM website_settings LIMIT 1");
            return $stmt->fetch() ?: [];
        } catch (Exception $e) {
            error_log("SettingsModel::get Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update the settings. $data is a [field => value] array already filtered by the
     * controller (only the fields the admin's permissions allow — see
     * AdminSiteSettingsController::save()).
     *
     * @param array<string, mixed> $data
     */
    public static function update(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        try {
            $setParts = implode(', ', array_map(fn($f) => "`{$f}` = ?", array_keys($data)));
            $values   = array_values($data);
            $values[] = 1; // WHERE id = 1

            $stmt = self::db()->prepare(
                "UPDATE website_settings SET {$setParts} WHERE id = ?"
            );
            return $stmt->execute($values);
        } catch (Exception $e) {
            error_log("SettingsModel::update Error: " . $e->getMessage());
            return false;
        }
    }
}
