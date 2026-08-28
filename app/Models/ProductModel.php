<?php

namespace App\Models;

use App\Core\Model;
use PDO;
use Exception;

class ProductModel extends Model
{
    /**
     * جلب كافة المنتجات المتاحة للعرض من قاعدة البيانات
     */
    public static function findVisible(): array
    {
        try {
            $db = self::db();
            $stmt = $db->query("SELECT * FROM products WHERE is_visible = 1 OR is_visible IS NULL ORDER BY id DESC");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("ProductModel::findVisible Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * عدد كل المنتجات المرئية (لأجل الـ Pagination)
     */
    public static function countVisible(): int
    {
        try {
            $db = self::db();
            $stmt = $db->query("SELECT COUNT(*) FROM products WHERE is_visible = 1 OR is_visible IS NULL");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("ProductModel::countVisible Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * جلب المنتجات المرئية صفحة صفحة مع أسماء الأقسام (categories)
     */
    public static function findVisiblePaginated(int $limit, int $offset): array
    {
        try {
            $db = self::db();
            $stmt = $db->prepare("
                SELECT p.*, GROUP_CONCAT(DISTINCT c.name ORDER BY c.name) AS categories
                FROM products p
                LEFT JOIN product_category_pivot pcp ON pcp.product_id = p.id
                LEFT JOIN categories c ON c.id = pcp.category_id
                WHERE (p.is_visible = 1 OR p.is_visible IS NULL)
                GROUP BY p.id
                ORDER BY p.date_added DESC, p.id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("ProductModel::findVisiblePaginated Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * جلب منتج واحد بواسطة الـ ID
     */
    public static function findById(int $id): ?array
    {
        try {
            $db = self::db();
            $stmt = $db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (Exception $e) {
            error_log("ProductModel::findById Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * جلب خيارات/أنواع المنتج (Variants)
     */
    public static function getVariants(int $productId): array
    {
        try {
            $db = self::db();
            $stmt = $db->prepare("SELECT * FROM product_variants WHERE product_id = ?");
            $stmt->execute([$productId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("ProductModel::getVariants Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * جلب كافة التقييمات الخاصة بمنتج معين مع أسماء المستخدمين
     */
    public static function getReviews(int $productId): array
    {
        try {
            $db = self::db();
            $stmt = $db->prepare("
                SELECT pr.*, u.full_name 
                FROM product_reviews pr
                JOIN users u ON u.id = pr.user_id
                WHERE pr.product_id = ? 
                ORDER BY pr.created_at DESC
            ");
            $stmt->execute([$productId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("ProductModel::getReviews Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * جلب تقييم مستخدم محدد لمنتج معين
     */
    public static function getUserReview(int $productId, int $userId): ?array
    {
        try {
            $db = self::db();
            $stmt = $db->prepare("SELECT * FROM product_reviews WHERE product_id = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$productId, $userId]);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (Exception $e) {
            error_log("ProductModel::getUserReview Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * جلب المنتجات المشابهة (بناءً على التصنيف أو الشركة المصنعة)
     */
    public static function getRelated(int $productId, ?string $manufacturer = null): array
    {
        try {
            $db = self::db();

            // 1. البحث عن منتجات تشترك في نفس التصنيف
            $stmt = $db->prepare("
                SELECT DISTINCT p.* 
                FROM products p
                JOIN product_category_pivot pcp ON pcp.product_id = p.id
                WHERE pcp.category_id IN (
                    SELECT category_id FROM product_category_pivot WHERE product_id = ?
                ) AND p.id != ? 
                LIMIT 4
            ");
            $stmt->execute([$productId, $productId]);
            $related = $stmt->fetchAll();

            // 2. إذا لم نجد منتجات بنفس التصنيف، نبحث عن منتجات لنفس الشركة المصنعة
            if (empty($related) && !empty($manufacturer)) {
                $stmt2 = $db->prepare("SELECT * FROM products WHERE manufacturer = ? AND id != ? LIMIT 4");
                $stmt2->execute([$manufacturer, $productId]);
                $related = $stmt2->fetchAll();
            }

            return $related;
        } catch (Exception $e) {
            error_log("ProductModel::getRelated Error: " . $e->getMessage());
            return [];
        }
    }
    /**
     * جلب بيانات المخزون/السعر الحية لمجموعة IDs — تُستخدم من صفحة الويش ليست
     */
    public static function findStockByIds(array $ids): array
    {
        try {
            $db = self::db();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // ⚠️ لا نقرأ products.stock_quantity مباشرة — هذا العمود لا يُحدَّث
            // إطلاقاً من لوحة تحكم الأدمن الحالية (INSERT/UPDATE لا يشمله)، والمخزون
            // الحقيقي بالكامل موجود بجدول product_variants. نجمعه هون بدلاً منه، مع
            // COALESCE كـ fallback فقط لو المنتج بلا variants إطلاقاً (حالة نادرة).
            $stmt = $db->prepare("
                SELECT p.id,
                       COALESCE(SUM(pv.stock_quantity), p.stock_quantity, 0) AS stock_quantity,
                       p.price, p.discount_percentage, p.price_after_discount,
                       COALESCE(p.is_visible, 1) AS is_visible
                FROM products p
                LEFT JOIN product_variants pv ON pv.product_id = p.id
                WHERE p.id IN ({$placeholders})
                GROUP BY p.id
            ");
            $stmt->execute($ids);

            $result = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(string)$row['id']] = [
                    'stock_quantity'       => (int)$row['stock_quantity'],
                    'price'                => (float)$row['price'],
                    'discount_percentage'  => (float)$row['discount_percentage'],
                    'price_after_discount' => (float)$row['price_after_discount'],
                    'is_visible'           => (int)$row['is_visible'],
                ];
            }
            return $result;
        } catch (Exception $e) {
            error_log("ProductModel::findStockByIds Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * إضافة/تحديث تقييم مستخدم لمنتج (Upsert) — تُرجع مصفوفة ['ok' => bool, 'message' => string]
     */
    public static function saveReview(int $productId, int $userId, int $rating, string $comment): array
    {
        $comment = trim($comment);

        if (empty($rating) && $comment === '') {
            return ['ok' => false, 'message' => 'Please provide a rating or a comment.'];
        }
        if (!empty($rating) && ($rating < 1 || $rating > 5)) {
            return ['ok' => false, 'message' => 'Please select a rating from 1 to 5.'];
        }

        try {
            $db = self::db();
            $ex = $db->prepare("SELECT id FROM product_reviews WHERE product_id = ? AND user_id = ? LIMIT 1");
            $ex->execute([$productId, $userId]);

            if ($ex->fetch()) {
                $db->prepare("UPDATE product_reviews SET rating = ?, comment = ? WHERE product_id = ? AND user_id = ?")
                   ->execute([$rating ?: null, $comment ?: null, $productId, $userId]);
                return ['ok' => true, 'message' => '✅ Your review has been updated.'];
            }

            $db->prepare("INSERT INTO product_reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)")
               ->execute([$productId, $userId, $rating ?: null, $comment ?: null]);
            return ['ok' => true, 'message' => '✅ Thank you! Your review has been added.'];
        } catch (Exception $e) {
            error_log('ProductModel::saveReview Error: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Something went wrong, please try again.'];
        }
    }

    /**
     * اسم منتج بمعرّفه، أو null إن لم يوجد.
     *
     * كان هذا الاستعلام مكتوباً مرتين: هنا (عبر WishlistController) وفي
     * AdminProductModel. اسم المنتج ليس مفهوماً خاصاً بلوحة التحكم،
     * فمكانه الطبيعي موديل المتجر، و AdminProductModel::getNameById
     * صارت تفوّض إليه.
     */
    public static function getNameById(int $productId): ?string
    {
        try {
            $stmt = self::db()->prepare("SELECT name FROM products WHERE id = ? LIMIT 1");
            $stmt->execute([$productId]);
            $name = $stmt->fetchColumn();
            return $name !== false ? (string)$name : null;
        } catch (Exception $e) {
            error_log("ProductModel::getNameById Error: " . $e->getMessage());
            return null;
        }
    }
    /**
     * بيانات المخزون والسعر الحالية لمجموعة variants — لفحص السلة قبل
     * إتمام الطلب. تُرجع الظاهرة فقط (is_visible = 1) كي لا تُباع نسخة
     * من منتج أُخفي بعد إضافته للسلة.
     *
     * نُقل من CartController::checkStock حيث كان استعلاماً مكتوباً مباشرة.
     *
     * @param  int[] $variantIds
     * @return array<int,array<string,mixed>>
     */
    public static function findVariantsStock(array $variantIds): array
    {
        $variantIds = array_values(array_filter(array_map('intval', $variantIds)));
        if (!$variantIds) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
            $stmt = self::db()->prepare("
                SELECT
                    pv.id            AS variant_id,
                    p.id             AS product_id,
                    p.name           AS product_name,
                    pv.color_name,
                    pv.price,
                    pv.discount_percentage,
                    pv.price_after_discount,
                    pv.stock_quantity,
                    pv.image_path
                FROM product_variants pv
                JOIN products p ON p.id = pv.product_id
                WHERE pv.id IN ({$placeholders})
                  AND p.is_visible = 1
            ");
            $stmt->execute($variantIds);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("ProductModel::findVariantsStock Error: " . $e->getMessage());
            return [];
        }
    }
}
