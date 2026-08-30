<?php

namespace App\Services;

use App\Models\AdminProductModel;

/**
 * ProductVariantUploader — turns the raw product form input
 * (`$_POST['variants']` + `$_FILES['variants']`) into a variants array ready to be
 * saved, uploading each variant's image along the way.
 *
 * Why a service of its own?
 * This logic used to be three private methods inside AdminProductsController, and it
 * was the single biggest reason storeAdd and storeEdit were as long as they were. It
 * has nothing to do with the request cycle, the permissions, or the response — it is
 * input transformation and file uploading, so it belongs outside the controller.
 *
 * A note on the design: the class reads from neither $_POST nor $_FILES itself —
 * everything it needs arrives as a parameter. The old version read
 * $_POST['default_variant'] from inside itself, a hidden dependency that made testing
 * it, or reusing it from another context, impossible.
 *
 * Every method is static, consistent with the rest of the project's layers.
 */
class ProductVariantUploader
{
    /**
     * Builds the variants array ready to be saved.
     *
     * It skips any row with no colour name or a price ≤ 0 — those are the empty rows the
     * form leaves behind when fields are added and then not filled in.
     *
     * @param  list<array<string, mixed>> $postVariants  $_POST['variants']
     * @param  array<string, mixed> $filesVariants $_FILES['variants']
     * @param  string $uploadDir     The destination directory on disk
     * @param  int    $defaultIndex  The default variant's position, as the form submitted it
     * @return array<int,array<string,mixed>>
     */
    public static function parse(
        array $postVariants,
        array $filesVariants,
        string $uploadDir,
        int $defaultIndex = 0
    ): array {
        $result = [];

        foreach ($postVariants as $i => $v) {
            $colorName = trim($v['color_name'] ?? '');
            $price     = (float)($v['price'] ?? 0);

            if ($colorName === '' || $price <= 0) {
                continue;
            }

            // Upload this variant's new image, if there is one
            $imagePath = null;
            $fileEntry = self::extractFileEntry($filesVariants, (int)$i);
            if ($fileEntry) {
                $imagePath = AdminProductModel::uploadVariantImage($fileEntry, $uploadDir);
            }

            // Keep the old image when no new one was uploaded
            if ($imagePath === null && !empty($v['existing_image'])) {
                $imagePath = $v['existing_image'];
            }

            $result[] = [
                'id'         => isset($v['id']) && (int)$v['id'] > 0 ? (int)$v['id'] : null,
                'color_name' => $colorName,
                'color_hex'  => trim($v['color_hex'] ?? '') ?: null,
                'price'      => $price,
                'discount'   => (float)($v['discount'] ?? 0),
                'stock'      => (int)($v['stock'] ?? 0),
                'gender'     => in_array($v['gender'] ?? 'both', ['male', 'female', 'both'], true)
                                    ? $v['gender'] : 'both',
                'image_path' => $imagePath,
                'is_default' => (count($result) === $defaultIndex),
                'sort_order' => count($result),
            ];
        }

        return $result;
    }

    /**
     * Was at least one image submitted among the variants?
     *
     * $_FILES for an array input arrives in two shapes depending on the naming depth in
     * the form, so the check handles the value as either a string or a nested array.
     *
     * @param array<string, mixed> $filesVariants The nested $_FILES["variants"] structure
     */
    public static function hasAnyImage(array $filesVariants): bool
    {
        if (empty($filesVariants['tmp_name'])) {
            return false;
        }

        foreach ((array)$filesVariants['tmp_name'] as $tmpName) {
            if (is_array($tmpName)) {
                foreach ($tmpName as $t) {
                    if (!empty($t)) {
                        return true;
                    }
                }
                continue;
            }
            if (!empty($tmpName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes the just-uploaded images from disk — called when a save fails, so orphaned
     * files that no database row points at do not accumulate.
     *
     * @param list<array<string, mixed>> $parsedVariants
     */
    public static function cleanup(array $parsedVariants, string $uploadDir): void
    {
        foreach ($parsedVariants as $v) {
            if (empty($v['image_path'])) {
                continue;
            }
            // basename strips any path, so the result is confined to $uploadDir by
            // construction rather than by trusting where the value came from.
            $disk = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($v['image_path']);
            if (file_exists($disk)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use,php.lang.security.injection.tainted-filename.tainted-filename
                @unlink($disk);
            }
        }
    }

    /**
     * Extracts a single file from the array-shaped $_FILES structure in the form expected by
     * AdminProductModel::uploadVariantImage.
     *
     * ⚠️ The $_FILES structure here is nested, not flat. The form field is named
     * variants[i][image] (see js/admin/products.js), which PHP inverts into:
     *
     *     $_FILES['variants']['tmp_name'][0]['image'] = '/tmp/phpXXXX'
     *
     * meaning tmp_name[$idx] is an array keyed by 'image' rather than a string. The
     * previous version of this method assumed it was a string and compared
     * $error !== UPLOAD_ERR_OK while $error was an array — a comparison that is always
     * true, so it always returned null. The result: variant images were never uploaded at
     * all, from either the add or the edit form.
     *
     * Both shapes are handled here: the nested one (today's reality) and the flat one (in
     * case the field name ever changes to variants_image[]).
     *
     * @param array<string, mixed> $filesVariants The nested $_FILES["variants"] structure
     * @return array<string, mixed>|null
     */
    private static function extractFileEntry(array $filesVariants, int $idx): ?array
    {
        $pick = static function (string $key, mixed $default) use ($filesVariants, $idx): mixed {
            $slot = $filesVariants[$key][$idx] ?? null;
            if (is_array($slot)) {
                // The nested shape: take 'image' if present, otherwise the first value
                return $slot['image'] ?? (reset($slot) ?: $default);
            }
            return $slot ?? $default;
        };

        $tmpName = $pick('tmp_name', '');
        $error   = $pick('error', UPLOAD_ERR_NO_FILE);

        if (empty($tmpName) || (int)$error !== UPLOAD_ERR_OK) {
            return null;
        }

        return [
            'tmp_name' => $tmpName,
            'name'     => $pick('name', ''),
            'size'     => $pick('size', 0),
            'type'     => $pick('type', ''),
            'error'    => (int)$error,
        ];
    }
}
