<?php

namespace App\Models;

use App\Core\Model;
use PDO;
use Exception;

class BrandingModel extends Model
{
    /**
     * كل الشرائح مرتبة sort_order ASC، وكل شريحة فيها items مرتبة sort_order ASC
     * مع JOIN مع products لاسم/صورة/وصف المنتج الحالي (لتعبئة فورم التعديل).
     *
     * @return list<array<string, mixed>> مصفوفة شرائح كل واحدة: {id, sort_order, updated_by_admin_id, items[]}
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
                // روابط صور جاهزة للاستعمال — نفس اصطلاح searchProducts()
                // في هذا الموديل: الحقل 'image' فيها ناتج fixImagePath.
                //
                // كان محرّر السلايدر يبني المسار في JS بـ
                // URLROOT + '/' + product_image_path، و product_image_path
                // اسم ملف عارٍ مثل "airpods.jpg" — فيخرج /airpods.jpg بدل
                // /images/airpods.jpg وتُكسر **كل** صور المنتجات في المحرّر.
                // fixImagePath هي التي تعرف قاعدة البادئة، وهي في PHP.
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
     * للعرض بالصفحة الرئيسية فقط — يرجع الحقول النهائية (صورة/رابط/وصف)
     * محسوبة حسب active_mode داخل الـ SQL نفسه. يتجاهل العناصر غير المكتملة
     * (Product بلا منتج فعلي، أو Manual بلا صورة).
     *
     * @return list<array<string, mixed>> مصفوفة شرائح كل واحدة: {id, items: [{image_path, link_url, description}]}
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
                // تجاهل أي عنصر Product بلا منتج فعلي، أو Manual بلا صورة — بيانات فاسدة/غير مكتملة
                if (empty($it['image_path'])) {
                    continue;
                }
                $bySlider[(int)$it['slider_id']][] = [
                    'image_path'  => fixImagePath($it['image_path']),
                    'link_url'    => $it['link_url'] ?: null,
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
     * بحث حي بالـ Popup اختيار منتج — يرجع فقط أعمدة العرض والتعبئة التلقائية.
     *
     * @param string $q     كلمة البحث (فارغة = كل المنتجات)
     * @param int    $limit عدد النتائج الأقصى
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
     * الحفظ الكامل (Full Replace): يحذف كل الشرائح القديمة (CASCADE على
     * home_slider_items) ثم يُدرج كل شيء من جديد بترتيب الفورم — Transaction واحدة.
     *
     * @param list<array<string, mixed>> $slides  مصفوفة مُنظّفة وجاهزة: [{items: [{active_mode, product_id,
     *                        product_link_url, product_description, manual_image_path,
     *                        manual_link_url, manual_description}]}]
     * @param int     $adminId أدمن الحفظ الحالي (updated_by_admin_id + audit)
     */
    public static function saveAll(array $slides, int $adminId): bool
    {
        $db = self::db();
        try {
            $db->beginTransaction();

            // 1) احذف كل شيء قديم (CASCADE يحذف home_slider_items تلقائياً)
            $db->exec("DELETE FROM home_sliders");

            // 2) أدرج كل شيء من جديد بترتيب الفورم الحالي
            $sliderIns = $db->prepare("
                INSERT INTO home_sliders (sort_order, updated_by_admin_id)
                VALUES (?, ?)
            ");
            $itemIns = $db->prepare("
                INSERT INTO home_slider_items
                    (slider_id, sort_order, active_mode,
                     product_id, product_link_url, product_description,
                     manual_image_path, manual_link_url, manual_description)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                        $item['product_description'] ?: null,
                        $item['manual_image_path'] ?: null,
                        $item['manual_link_url'] ?: null,
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
     * كل مسارات manual_image_path الحالية بقاعدة البيانات (قبل الحذف) —
     * تُقارن بالمسارات الجديدة لحذف الصور اليتيمة من القرص بعد نجاح الحفظ.
     *
     * @return string[] مسارات نسبية مثل images/slider_xxx.jpg
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
     * رفع صورة سلايدر واحدة — نسخة مطابقة لمنطق AdminProductModel::uploadVariantImage()
     * لكن بادئة اسم الملف `slider_` بدل `product_` لتمييز صور السلايدر بمجلد الصور.
     *
     * @param array<string, mixed> $fileEntry مصفوفة ملف واحدة من $_FILES
     * @param string $uploadDir المجلد المطلق (مع trailing slash)
     * @return string|null      المسار النسبي (images/slider_xxx.jpg) أو null عند فشل التحقق/الرفع
     */
    public static function uploadSliderImage(array $fileEntry, string $uploadDir): ?string
    {
        // كان هنا نسخة مطابقة لمنطق AdminProductModel — والتعليق فوقها
        // كان يقول ذلك صراحةً. صارتا واحدة في App\Core\ImageUpload،
        // والفرق الوحيد (بادئة الاسم) وسيطاً.
        return \App\Core\ImageUpload::store($fileEntry, $uploadDir, 'slider_');
    }
}
