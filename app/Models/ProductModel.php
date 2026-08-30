<?php

namespace App\Models;

use App\Core\Model;
use PDO;
use Exception;

class ProductModel extends Model
{
    /**
     * Fetch every product available for display from the database.
     *
     * @return list<array<string, mixed>>
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
     * The count of all visible products (for pagination).
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
     * Fetch the visible products page by page, along with their category names.
     *
     * ⚠️ It returns **the rows alone**, not pagination data, unlike its siblings
     * (UserModel::getAllForAdmin and OrderModel::getAdminOrdersList return a map with
     * rows and total). The name suggests otherwise — and the comment here was first
     * written by analogy with them, so it was false until a runtime audit caught it.
     *
     * @return list<array<string, mixed>>
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
     * Fetch a single product by id.
     *
     * @return array<string, mixed>|null
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
     * Fetch a product's variants.
     *
     * @return list<array<string, mixed>>
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
     * Fetch every review for a given product, along with the reviewers' names.
     *
     * @return list<array<string, mixed>>
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
     * Fetch one user's review of a given product.
     *
     * @return array<string, mixed>|null
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
     * Fetch related products (by category, or by manufacturer).
     *
     * @return list<array<string, mixed>>
     */
    public static function getRelated(int $productId, ?string $manufacturer = null): array
    {
        try {
            $db = self::db();

            // 1. Look for products sharing the same category
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

            // 2. If none share a category, look for products from the same manufacturer
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
     * Fetch live stock and price data for a set of ids — used by the wishlist page.
     *
     * @param list<int> $ids
     * ⚠️ The key is an **integer**, not a string, despite the `(string)` in the
     * construction: PHP converts numeric string keys to integers automatically, so that
     * cast has no effect. The comment was first written by following the visible cast,
     * and a runtime audit revealed it described an intention rather than a fact.
     *
     * @return array<int, array{stock_quantity: int, price: float, discount_percentage: float, price_after_discount: float, is_visible: int}> keyed by product id
     */
    public static function findStockByIds(array $ids): array
    {
        try {
            $db = self::db();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // ⚠️ products.stock_quantity is not read directly — that column is never
            // updated by the current admin panel (neither INSERT nor UPDATE touches it),
            // and the real stock lives entirely in product_variants. It is summed here
            // instead, with a COALESCE as a fallback only for a product with no variants
            // at all (a rare case).
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
                // ⚠️ No cast to a string. There used to be `(string)$row['id']` here, and it
                // was **inert**: PHP converts any numeric string key to an integer
                // automatically, so the key was always an int despite the cast.
                //
                // Its harm was misleading two readers: people believed the keys were
                // strings, and PHPStan inferred the same and accepted a false annotation. A
                // runtime audit caught it by comparing the declared shape against the actual
                // one.
                //
                // The client reads them with String(variant_id) regardless, which works
                // either way — so removing the cast changes no behaviour.
                $result[(int)$row['id']] = [
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
     * Add or update a user's review of a product (an upsert) — it returns
     * ['ok' => bool, 'message' => string].
     *
     * @return array{ok: bool, message: string}
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
     * A product's name by its id, or null if it does not exist.
     *
     * This query used to be written twice: here (through WishlistController) and in
     * AdminProductModel. A product's name is not an admin-panel concept, so its natural
     * home is the store model, and AdminProductModel::getNameById now delegates to it.
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
     * Current stock and price data for a set of variants — for checking the cart before
     * an order completes. It returns only the visible ones (is_visible = 1), so a variant
     * of a product hidden after it was added to the cart cannot be sold.
     *
     * Moved out of CartController::checkStock, where it was an inline query.
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
            reportException($e);
            return [];
        }
    }

    /**
     * The same data as findVariantsStock but **with a write lock**, keyed by variant id —
     * for use inside the order-creation transaction alone.
     *
     * ── Why a second method rather than a `$forUpdate` parameter ──
     *
     * Because the two serve different contracts. findVariantsStock is called from a
     * public endpoint (/cart/check-stock) outside any transaction, and `FOR UPDATE` there
     * is meaningless: without a transaction the lock is released at once. Worse, if one
     * method sometimes locked, its behaviour would hang on the caller's context — which
     * is not visible to whoever reads the call site.
     *
     * ⚠️ Do not call this outside an open transaction. The lock lasts until COMMIT or
     * ROLLBACK; without a transaction it drops in the same instant, so you pay the lock's
     * cost without its benefit.
     *
     * The lock covers the `products` rows too, because the query joins them — and
     * MariaDB does not support `FOR UPDATE OF`, which would have confined the lock to
     * `pv` alone, while CI runs on MySQL 8. The plain form works on both, and the extra
     * breadth is acceptable: the order reads these rows in order to write their stock a
     * few lines later.
     *
     * Keyed by variant id rather than a flat list: the caller looks up one particular
     * row per cart item, and a linear search inside a loop is what makes a twenty-item
     * order scan the array twenty times.
     *
     * @param  int[] $variantIds
     * @return array<int,array<string,mixed>> keyed by variant_id
     */
    public static function findVariantsForUpdate(array $variantIds): array
    {
        $variantIds = array_values(array_filter(array_map('intval', $variantIds)));
        if (!$variantIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));

        // No try/catch, deliberately — unlike this method's siblings.
        //
        // Those swallow the exception and return [] because they serve a display: an
        // empty list means "no data" and does no harm. Here an empty list means "no known
        // price", and swallowing that inside a transaction turns a technical fault into
        // "your price changed" — a false message that hides the fault from the log and
        // from the customer. The exception propagates to placeOrder, which rolls back and
        // records the real cause.
        $stmt = self::db()->prepare("
            SELECT
                pv.id            AS variant_id,
                p.id             AS product_id,
                p.name           AS product_name,
                pv.color_name,
                pv.price_after_discount,
                pv.stock_quantity
            FROM product_variants pv
            JOIN products p ON p.id = pv.product_id
            WHERE pv.id IN ({$placeholders})
              AND p.is_visible = 1
            FOR UPDATE
        ");
        $stmt->execute($variantIds);

        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['variant_id']] = $row;
        }

        return $byId;
    }
}
