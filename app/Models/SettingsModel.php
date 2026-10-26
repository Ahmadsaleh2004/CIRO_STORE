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
            // Row 1 by name, the row update() writes. It was `LIMIT 1` with no order, which is
            // whichever row the engine returns first — and a table with a second row (there
            // is one in the development database) could hand back settings no form saves.
            $stmt = self::db()->query("SELECT * FROM website_settings WHERE id = 1");
            return $stmt->fetch() ?: [];
        } catch (Exception $e) {
            error_log("SettingsModel::get Error: " . $e->getMessage());
            return [];
        }
    }

    /** The cached copy's file name — fixed; only its folder can change (useCacheDir). */
    private const CACHE_FILE = 'settings.json';

    /** Where cached() keeps its copy; tests point it elsewhere with useCacheDir(). */
    private static ?string $cacheDir = null;

    /**
     * The settings for the store's own pages, without a database round trip.
     *
     * The footer needs the social links and its text on every page. Reading them from the
     * database would add a round trip to every page of the store — the very cost the rest of
     * this work removed. They change only when an admin saves the settings form, so they are
     * kept in storage/cache as JSON and dropped by update().
     *
     * A failed read returns [] and caches nothing, so the next request tries again.
     *
     * @return array<string, mixed>
     */
    public static function cached(): array
    {
        $file = self::cacheFile();

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $settings = self::get();
        if ($settings === []) {
            return [];
        }

        // Written whole and then renamed into place, so a request reading at the same moment
        // finds the old file or the new one — never half of it.
        $dir = dirname($file);
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode($settings, JSON_UNESCAPED_UNICODE)) !== false) {
                @rename($tmp, $file);
            }
        }

        return $settings;
    }

    /** For the test suite: keep the cache somewhere other than the development copy. */
    public static function useCacheDir(?string $dir): void
    {
        self::$cacheDir = $dir;
    }

    private static function cacheDir(): string
    {
        return self::$cacheDir ?? ROOTPATH . '/storage/cache';
    }

    private static function cacheFile(): string
    {
        return self::cacheDir() . '/' . self::CACHE_FILE;
    }

    private static function forgetCache(): void
    {
        // A known folder and a bare file name: basename() is what keeps a deletion from ever
        // reaching outside the folder (the project's cairo-unlink-unvalidated-path rule, which
        // reads the guard inside the unlink() call itself).
        if (is_file(self::cacheFile())) {
            @unlink(self::cacheDir() . '/' . basename(self::CACHE_FILE));
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
            $ok = $stmt->execute($values);

            // Saving is the one way the settings change, so it is the one place the copy
            // cached() reads is thrown away.
            self::forgetCache();

            return $ok;
        } catch (Exception $e) {
            error_log("SettingsModel::update Error: " . $e->getMessage());
            return false;
        }
    }
}
