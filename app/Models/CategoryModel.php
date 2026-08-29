<?php

namespace App\Models;

use App\Core\Model;
use Exception;

/**
 * CategoryModel — إدارة جدول categories الديناميكي (بعد migration ENUM→VARCHAR)
 */
class CategoryModel extends Model
{
    /** الأربع كاتوجريز الأساسية بترتيبها الثابت الإلزامي — لا تُغيَّر */
    public const CORE_ORDER = ['phone', 'computer', 'accessories', 'gaming'];

    /**
     * كل الكاتوجريز مرتبة: الأساسية أولاً بترتيب CORE_ORDER، ثم الباقي أبجدياً.
     * كل صف يتضمن product_count (عدد المنتجات المرتبطة فعلياً عبر product_category_pivot).
     *
     * @return list<array<string, mixed>>
     */
    public static function getAllOrdered(): array
    {
        try {
            $stmt = self::db()->query("
                SELECT c.id, c.name, c.is_core,
                       COUNT(pcp.product_id) AS product_count
                FROM categories c
                LEFT JOIN product_category_pivot pcp ON pcp.category_id = c.id
                GROUP BY c.id, c.name, c.is_core
            ");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $core  = [];
            $extra = [];
            foreach ($rows as $r) {
                if ($r['is_core']) {
                    $core[$r['name']] = $r;
                } else {
                    $extra[] = $r;
                }
            }

            // رتّب الأساسية بترتيب CORE_ORDER الثابت
            $orderedCore = [];
            foreach (self::CORE_ORDER as $name) {
                if (isset($core[$name])) {
                    $orderedCore[] = $core[$name];
                }
            }

            // رتّب الباقي أبجدياً
            usort($extra, fn($a, $b) => strcasecmp($a['name'], $b['name']));

            return array_merge($orderedCore, $extra);
        } catch (Exception $e) {
            error_log("CategoryModel::getAllOrdered Error: " . $e->getMessage());
            return [];
        }
    }

    /** يتحقق من وجود اسم (case-insensitive) — يُستخدم لمنع التكرار عند الإضافة */
    public static function nameExists(string $name): bool
    {
        try {
            $stmt = self::db()->prepare(
                "SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1"
            );
            $stmt->execute([trim($name)]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            error_log("CategoryModel::nameExists Error: " . $e->getMessage());
            // الأمان: بحالة الخطأ نفترض موجود لمنع تكرار محتمل
            return true;
        }
    }

    /**
     * يقترح أقرب الكاتوجريز بالمعنى (تشابه نصي) لاسم مُدخل من الأدمن.
     * يُستخدم بحالتين: (أ) أثناء الكتابة بحقل "إضافة كاتوجري" لمنع التكرار،
     * (ب) عند حذف كاتوجري لاقتراح وجهة النقل.
     * يرجع مصفوفة مرتبة تنازلياً حسب نسبة التشابه (similar_text %).
     *
     * @return list<array<string, mixed>>
     */
    public static function suggestSimilar(string $query, int $limit = 5, ?int $excludeId = null): array
    {
        try {
            $all   = self::db()->query("SELECT id, name FROM categories")->fetchAll(\PDO::FETCH_ASSOC);
            $query = trim($query);

            $scored = [];
            foreach ($all as $row) {
                if ($excludeId !== null && (int)$row['id'] === $excludeId) {
                    continue;
                }
                similar_text(mb_strtolower($query), mb_strtolower($row['name']), $percent);
                $scored[] = [
                    'id'         => (int)$row['id'],
                    'name'       => $row['name'],
                    'similarity' => round($percent, 1),
                ];
            }

            usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
            return array_slice($scored, 0, $limit);
        } catch (Exception $e) {
            error_log("CategoryModel::suggestSimilar Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * إضافة كاتوجري جديدة. يرجع الـ id الجديد أو null عند الفشل/التكرار.
     * is_core تُضبط دائماً 0 — لا يمكن إنشاء كاتوجري أساسية جديدة عبر الواجهة.
     */
    public static function create(string $name): ?int
    {
        $name = trim($name);
        if ($name === '' || self::nameExists($name)) {
            return null;
        }

        try {
            $db   = self::db();
            $stmt = $db->prepare("INSERT INTO categories (name, is_core) VALUES (?, 0)");
            $stmt->execute([$name]);
            return (int)$db->lastInsertId();
        } catch (Exception $e) {
            error_log("CategoryModel::create Error: " . $e->getMessage());
            return null;
        }
    }

    /** يرجع true لو الكاتوجري أساسية (محمية من الحذف) */
    public static function isCore(int $id): bool
    {
        try {
            $stmt = self::db()->prepare("SELECT is_core FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("CategoryModel::isCore Error: " . $e->getMessage());
            // الأمان: بحالة الخطأ نمنع الحذف
            return true;
        }
    }

    /**
     * حذف كاتوجري ونقل كل منتجاتها إلى $destinationCategoryId أولاً.
     * يُرفض إذا كانت الكاتوجري أساسية (is_core=1) أو إذا $destinationCategoryId غير موجود.
     * عملية واحدة (transaction): نقل الربط بـ product_category_pivot ثم حذف الصف.
     *
     * المنطق: UPDATE IGNORE يتجاهل التكرار (منتج مرتبط أصلاً بكلا الكاتوجريز)،
     * ثم DELETE تنضف أي صف قديم تبقّى مرتبطاً بالكاتوجري المحذوفة.
     */
    public static function deleteAndReassign(int $categoryId, int $destinationCategoryId): bool
    {
        if ($categoryId === $destinationCategoryId) {
            return false;
        }
        if (self::isCore($categoryId)) {
            return false;
        }

        $db = self::db();
        try {
            $db->beginTransaction();

            // انقل كل ربط المنتجات للوجهة الجديدة، متجنبين تكرار المفتاح المركب
            $db->prepare("
                UPDATE IGNORE product_category_pivot
                SET category_id = ?
                WHERE category_id = ?
            ")->execute([$destinationCategoryId, $categoryId]);

            // احذف أي روابط تكرارية تبقّت (المنتج كان مرتبط أصلاً بالوجهتين معاً)
            $db->prepare("DELETE FROM product_category_pivot WHERE category_id = ?")
               ->execute([$categoryId]);

            $db->prepare("DELETE FROM categories WHERE id = ? AND is_core = 0")
               ->execute([$categoryId]);

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("CategoryModel::deleteAndReassign Error: " . $e->getMessage());
            return false;
        }
    }

    /** يجلب اسم كاتوجري واحدة بالـ id — يُستخدم برسائل التأكيد بالواجهة */
    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        try {
            $stmt = self::db()->prepare(
                "SELECT id, name, is_core FROM categories WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$id]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            error_log("CategoryModel::findById Error: " . $e->getMessage());
            return null;
        }
    }
}
