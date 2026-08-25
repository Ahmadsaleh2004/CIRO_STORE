<?php

namespace App\Models;

use App\Core\Database;
use Exception;

/**
 * SettingsModel — يغطي جدول website_settings (صف واحد ثابت id=1 دائماً)
 */
class SettingsModel
{
    /** الحقول القابلة للتعديل من الفورم العام (بدون شرط صلاحية إضافية) */
    public const GENERAL_FIELDS = [
        'footer_text', 'facebook_url', 'instagram_url', 'snapchat_url',
        'whatsapp_number', 'tiktok_url', 'twitter_x_url', 'google_maps_url',
        'copyright_text', 'phone_number', 'working_hours', 'employees_count',
        'site_url', 'return_policy', 'privacy_policy', 'terms_and_conditions',
    ];

    /** حقول إضافية تظهر فقط لصاحب صلاحية can_manage_checkout_settings */
    public const CHECKOUT_FIELDS = ['default_currency', 'default_language'];

    /**
     * جلب صف الإعدادات الحالي (صف واحد فقط id=1)
     */
    public static function get(): array
    {
        try {
            $stmt = Database::connect()->query("SELECT * FROM website_settings LIMIT 1");
            return $stmt->fetch() ?: [];
        } catch (Exception $e) {
            error_log("SettingsModel::get Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * تحديث الإعدادات. $data مصفوفة [field => value] مُفلترة مسبقاً من الكونترولر
     * (فقط الحقول المسموحة حسب صلاحيات الأدمن — راجع AdminSiteSettingsController::save()).
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

            $stmt = Database::connect()->prepare(
                "UPDATE website_settings SET {$setParts} WHERE id = ?"
            );
            return $stmt->execute($values);
        } catch (Exception $e) {
            error_log("SettingsModel::update Error: " . $e->getMessage());
            return false;
        }
    }
}
