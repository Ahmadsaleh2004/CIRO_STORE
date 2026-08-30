<?php

namespace App\Models;

use App\Core\Model;
use PDO;
use Exception;

class BrandingModel extends Model
{
    /**
     * Every slide ordered by sort_order ASC, each carrying its items ordered by
     * sort_order ASC, joined against products for the current product's name, image and
     * description (to populate the edit form).
     *
     * @return list<array<string, mixed>> An array of slides, each {id, sort_order, updated_by_admin_id, items[]}
     */
    public static function getFullSliderData(): array
    {
        try {
            $db = self::db();

            $sliders = $db->query("SELECT * FROM home_sliders ORDER BY sort_order ASC, id ASC")
                           ->fetchAll(PDO::FETCH_ASSOC);

            if (!$sliders) {
                return [];
            }

            $sliderIds = array_column($sliders, 'id');
            $placeholders = implode(',', array_fill(0, count($sliderIds), '?'));

            $stmt = $db->prepare("
                SELECT si.*,
                       p.name         AS product_name,
                       p.image_path   AS product_image_path,
                       p.description  AS product_default_description
                FROM home_slider_items si
                LEFT JOIN products p ON p.id = si.product_id
                WHERE si.slider_id IN ({$placeholders})
                ORDER BY si.slider_id ASC, si.sort_order ASC, si.id ASC
            ");
            $stmt->execute($sliderIds);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $itemsBySlider = [];
            foreach ($items as $it) {
                // Ready-to-use image URLs — the same convention as searchProducts() in this
                // model: its 'image' field is the output of fixImagePath.
                //
                // The slider editor used to build the path in JavaScript as
                // URLROOT + '/' + product_image_path, and product_image_path is a bare file
                // name such as "airpods.jpg" — producing /airpods.jpg instead of
                // /images/airpods.jpg and breaking **every** product image in the editor.
                // fixImagePath is what knows the prefix rule, and it lives in PHP.
                $it['product_image_url'] = $it['product_image_path']
                    ? fixImagePath($it['product_image_path'])
                    : null;
                $it['manual_image_url'] = $it['manual_image_path']
                    ? fixImagePath($it['manual_image_path'])
                    : null;

                $itemsBySlider[(int)$it['slider_id']][] = $it;
            }

            foreach ($sliders as &$s) {
                $s['items'] = $itemsBySlider[(int)$s['id']] ?? [];
            }
            unset($s);

            return $sliders;
        } catch (Exception $e) {
            error_log("BrandingModel::getFullSliderData Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * For the home page display only — it returns the final fields (image, link,
     * description) computed from active_mode inside the SQL itself. Incomplete items are
     * skipped (a Product item with no actual product, or a Manual one with no image).
     *
     * @return list<array<string, mixed>> An array of slides, each:
     *         {id, items: [{image_path, link_url, title, description}]}
     */
    public static function getActiveSlidersForHome(): array
    {
        try {
            $db = self::db();

            $sliders = $db->query("SELECT id FROM home_sliders ORDER BY sort_order ASC, id ASC")
                           ->fetchAll(PDO::FETCH_ASSOC);
            if (!$sliders) {
                return [];
            }

            $stmt = $db->query("
                SELECT
                    si.slider_id,
                    si.id,
                    si.active_mode,
                    CASE WHEN si.active_mode = 'product'
                         THEN p.image_path
                         ELSE si.manual_image_path
                    END AS image_path,
                    CASE WHEN si.active_mode = 'product'
                         THEN COALESCE(NULLIF(si.product_link_url, ''), CONCAT('/product?id=', si.product_id))
                         ELSE si.manual_link_url
                    END AS link_url,
                    CASE WHEN si.active_mode = 'product'
                         THEN COALESCE(NULLIF(si.product_title, ''), p.name)
                         ELSE si.manual_title
                    END AS title,
                    CASE WHEN si.active_mode = 'product'
                         THEN COALESCE(NULLIF(si.product_description, ''), p.description)
                         ELSE si.manual_description
                    END AS description
                FROM home_slider_items si
                LEFT JOIN products p ON p.id = si.product_id
                ORDER BY si.slider_id ASC, si.sort_order ASC, si.id ASC
            ");
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $bySlider = [];
            foreach ($items as $it) {
                // Skip any Product item with no actual product, or Manual one with no image — corrupt or incomplete data
                if (empty($it['image_path'])) {
                    continue;
                }
                $bySlider[(int)$it['slider_id']][] = [
                    'image_path'  => fixImagePath($it['image_path']),
                    'link_url'    => $it['link_url'] ?: null,
                    'title'       => $it['title'] ?: '',
                    'description' => $it['description'] ?: '',
                ];
            }

            $result = [];
            foreach ($sliders as $s) {
                $sid = (int)$s['id'];
                if (!empty($bySlider[$sid])) {
                    $result[] = ['id' => $sid, 'items' => $bySlider[$sid]];
                }
            }
            return $result;
        } catch (Exception $e) {
            error_log("BrandingModel::getActiveSlidersForHome Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Live search in the product-picker popup — it returns only the columns needed for
     * display and auto-fill.
     *
     * @param string $q     The search term (empty = every product)
     * @param int    $limit The maximum number of results
     * @return list<array<string, mixed>> [{id, name, image, description, link}]
     */
    public static function searchProducts(string $q, int $limit = 15): array
    {
        try {
            $db = self::db();
            $sql = "SELECT id, name, image_path, description
                    FROM products";
            $params = [];
            if ($q !== '') {
                $sql .= " WHERE name LIKE ?";
                $params[] = "%{$q}%";
            }
            $sql .= " ORDER BY name ASC LIMIT ?";
            $stmt = $db->prepare($sql);
            foreach ($params as $i => $val) {
                $stmt->bindValue($i + 1, $val, PDO::PARAM_STR);
            }
            $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
            $stmt->execute();

            return array_map(function ($p) {
                return [
                    'id'          => (int)$p['id'],
                    'name'        => $p['name'],
                    'image'       => fixImagePath($p['image_path'] ?? ''),
                    'description' => $p['description'] ?? '',
                    'link'        => URLROOT . '/product?id=' . (int)$p['id'],
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            error_log("BrandingModel::searchProducts Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * A full replace: it deletes every old slide (cascading to home_slider_items) and
     * then inserts everything afresh in the form's order — one transaction.
     *
     * @param list<array<string, mixed>> $slides  A cleaned, ready array: [{items: [{active_mode, product_id,
     *                        product_link_url, product_description, manual_image_path,
     *                        manual_link_url, manual_description}]}]
     * @param int     $adminId The admin performing the save (updated_by_admin_id + audit)
     */
    public static function saveAll(array $slides, int $adminId): bool
    {
        $db = self::db();
        try {
            $db->beginTransaction();

            // 1) Delete everything old (the cascade removes home_slider_items automatically)
            $db->exec("DELETE FROM home_sliders");

            // 2) Insert everything afresh, in the current form's order
            $sliderIns = $db->prepare("
                INSERT INTO home_sliders (sort_order, updated_by_admin_id)
                VALUES (?, ?)
            ");
            $itemIns = $db->prepare("
                INSERT INTO home_slider_items
                    (slider_id, sort_order, active_mode,
                     product_id, product_link_url, product_title, product_description,
                     manual_image_path, manual_link_url, manual_title, manual_description)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($slides as $slideIndex => $slide) {
                $sliderIns->execute([$slideIndex, $adminId]);
                $sliderId = (int)$db->lastInsertId();

                foreach ($slide['items'] as $itemIndex => $item) {
                    $itemIns->execute([
                        $sliderId,
                        $itemIndex,
                        $item['active_mode'],
                        $item['product_id'] ?: null,
                        $item['product_link_url'] ?: null,
                        $item['product_title'] ?: null,
                        $item['product_description'] ?: null,
                        $item['manual_image_path'] ?: null,
                        $item['manual_link_url'] ?: null,
                        $item['manual_title'] ?: null,
                        $item['manual_description'] ?: null,
                    ]);
                }
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("BrandingModel::saveAll Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Every current manual_image_path in the database (before the delete) — compared
     * against the new paths so orphaned images can be removed from disk once the save
     * succeeds.
     *
     * @return string[] Relative paths such as images/slider_xxx.jpg
     */
    public static function collectAllImagePaths(): array
    {
        try {
            $stmt = self::db()->query(
                "SELECT manual_image_path FROM home_slider_items WHERE manual_image_path IS NOT NULL"
            );
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'manual_image_path');
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Upload a single slider image — identical in logic to
     * AdminProductModel::uploadVariantImage(), but with the file-name prefix `slider_`
     * rather than `product_`, so slider images are distinguishable in the images folder.
     *
     * @param array<string, mixed> $fileEntry A single file entry from $_FILES
     * @param string $uploadDir The absolute directory (with a trailing slash)
     * @return string|null      The relative path (images/slider_xxx.jpg), or null if validation or the upload failed
     */
    public static function uploadSliderImage(array $fileEntry, string $uploadDir): ?string
    {
        // There used to be an identical copy of AdminProductModel's logic here — and the
        // comment above it said so outright. The two are now one, in App\Core\ImageUpload,
        // with the single difference (the name prefix) as a parameter.
        return \App\Core\ImageUpload::store($fileEntry, $uploadDir, 'slider_');
    }
}
