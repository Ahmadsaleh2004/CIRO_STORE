<?php

namespace App\Models;

use App\Core\Model;
use Exception;
use PDO;

/**
 * AdminProductModel — the admin panel's product queries alone
 * (separate from ProductModel, which serves the public store front).
 */
class AdminProductModel extends Model
{
    /** Price sort options. */
    public const PRICE_SORT_OPTIONS = [
        'price_desc' => 'Price: High to Low',
        'price_asc'  => 'Price: Low to High',
    ];

    /** Quantity sort options. */
    public const STOCK_SORT_OPTIONS = [
        'stock_desc' => 'Stock: High to Low',
        'stock_asc'  => 'Stock: Low to High',
    ];

    /** Date sort options. */
    public const DATE_SORT_OPTIONS = [
        'date_desc' => 'Newest First',
        'date_asc'  => 'Oldest First',
    ];

    /**
     * The product list for the Manage Products page — search, multi-category filtering,
     * and a compound sort. Priority: price → quantity → date.
     *
     * @param list<int> $categoryIds
     * @return array<string, mixed> The rows together with the pagination data
     */
    public static function getPaginated(
        string $search,
        array $categoryIds,  // an array rather than an int — it accepts several categories (OR)
        ?string $priceSort,
        ?string $stockSort,
        ?string $dateSort,
        int $limit,
        int $offset
    ): array {
        try {
            $db     = self::db();
            $where  = [];
            $params = [];

            // Search filter, by name
            if ($search !== '') {
                $where[]  = 'p.name LIKE ?';
                $params[] = "%{$search}%";
            }

            // A JOIN filtering on several categories (OR) — DISTINCT prevents the product repeating
            $joinCat   = '';
            $catParams = [];
            if (!empty($categoryIds)) {
                $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
                $joinCat      = "JOIN product_category_pivot pcpf
                                  ON pcpf.product_id = p.id
                                  AND pcpf.category_id IN ({$placeholders})";
                $catParams    = array_map('intval', $categoryIds);
            }

            // A compound ORDER BY, in priority order: price → quantity → date
            $orderParts = [];
            if ($priceSort === 'price_desc') {
                $orderParts[] = 'p.price DESC';
            } elseif ($priceSort === 'price_asc') {
                $orderParts[] = 'p.price ASC';
            }

            if ($stockSort === 'stock_desc') {
                $orderParts[] = 'total_stock DESC';
            } elseif ($stockSort === 'stock_asc') {
                $orderParts[] = 'total_stock ASC';
            }

            if ($dateSort === 'date_asc') {
                $orderParts[] = 'p.date_added ASC';
            } elseif ($dateSort === 'date_desc') {
                $orderParts[] = 'p.date_added DESC';
            }

            // Default: newest first, when no sort was specified
            if (empty($orderParts)) {
                $orderParts[] = 'p.date_added DESC';
            }

            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            $orderSql = implode(', ', $orderParts);

            // Parameter order: catParams first (the JOIN), then WHERE, then LIMIT/OFFSET
            $allParams   = array_merge($catParams, $params);
            $allParams[] = $limit;
            $allParams[] = $offset;

            $sql = "
                SELECT p.*,
                       GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS categories,
                       COALESCE(
                           (SELECT SUM(pv.stock_quantity)
                            FROM product_variants pv WHERE pv.product_id = p.id),
                           p.stock_quantity, 0
                       ) AS total_stock,
                       (SELECT MIN(pv2.stock_quantity)
                        FROM product_variants pv2 WHERE pv2.product_id = p.id) AS min_variant_stock,
                       lm.full_name AS last_modified_by_name
                FROM products p
                {$joinCat}
                LEFT JOIN product_category_pivot pcp ON pcp.product_id = p.id
                LEFT JOIN categories c ON c.id = pcp.category_id
                LEFT JOIN admins lm ON lm.id = p.updated_by_admin_id
                {$whereSql}
                GROUP BY p.id, lm.full_name
                ORDER BY {$orderSql}
                LIMIT ? OFFSET ?
            ";

            $stmt       = $db->prepare($sql);
            $totalCount = count($allParams);

            foreach ($allParams as $i => $val) {
                // The last two are always LIMIT/OFFSET, and integers
                $isInt = ($i >= $totalCount - 2) || is_int($val);
                $stmt->bindValue($i + 1, $val, $isInt ? PDO::PARAM_INT : PDO::PARAM_STR);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("AdminProductModel::getPaginated Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * The product count after the filters are applied — used to compute the page count.
     *
     * @param list<int> $categoryIds
     */
    public static function countFiltered(string $search, array $categoryIds = []): int
    {
        try {
            $db        = self::db();
            $where     = [];
            $catParams = [];
            $params    = [];

            if ($search !== '') {
                $where[]  = 'p.name LIKE ?';
                $params[] = "%{$search}%";
            }

            $joinCat = '';
            if (!empty($categoryIds)) {
                $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
                $joinCat      = "JOIN product_category_pivot pcpf
                                  ON pcpf.product_id = p.id
                                  AND pcpf.category_id IN ({$placeholders})";
                $catParams    = array_map('intval', $categoryIds);
            }

            $whereSql  = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            $allParams = array_merge($catParams, $params);

            $stmt = $db->prepare(
                "SELECT COUNT(DISTINCT p.id) FROM products p {$joinCat} {$whereSql}"
            );
            $stmt->execute($allParams);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("AdminProductModel::countFiltered Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * The total number of products in the system — entirely unfiltered (for the counter
     * in the Manage Products page title).
     */
    public static function countAll(): int
    {
        try {
            return (int)self::db()
                ->query("SELECT COUNT(*) FROM products")
                ->fetchColumn();
        } catch (Exception $e) {
            error_log("AdminProductModel::countAll Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Fetch one product together with its category ids and its variants — for the edit page.
     *
     * @return array<string, mixed>|null
     */
    public static function findByIdWithCategories(int $id): ?array
    {
        try {
            $db = self::db();

            $stmt = $db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                return null;
            }

            // The product's categories, as an array of ids
            $catStmt = $db->prepare(
                "SELECT category_id FROM product_category_pivot WHERE product_id = ?"
            );
            $catStmt->execute([$id]);
            $product['category_ids'] = array_map('intval', $catStmt->fetchAll(PDO::FETCH_COLUMN));

            // Variants ordered by sort_order
            $varStmt = $db->prepare(
                "SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order ASC, id ASC"
            );
            $varStmt->execute([$id]);
            $product['variants'] = $varStmt->fetchAll(PDO::FETCH_ASSOC);

            return $product;
        } catch (Exception $e) {
            error_log("AdminProductModel::findByIdWithCategories Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Synchronise a product's categories inside an already-open transaction — it is not
     * called on its own. It deletes the old links and writes the new ones. $categoryIds
     * must contain at least one element.
     *
     * @param list<int> $categoryIds
     */
    public static function syncCategories(\PDO $db, int $productId, array $categoryIds): void
    {
        $db->prepare("DELETE FROM product_category_pivot WHERE product_id = ?")
           ->execute([$productId]);

        $ins = $db->prepare(
            "INSERT IGNORE INTO product_category_pivot (product_id, category_id) VALUES (?, ?)"
        );
        foreach (array_unique(array_map('intval', $categoryIds)) as $catId) {
            if ($catId > 0) {
                $ins->execute([$productId, $catId]);
            }
        }
    }

    /**
     * Create a new product with its variants and categories, in one transaction.
     *
     * ⚠️ The image check must happen before this method is called — a transaction is not
     * opened for an operation that is certain to fail.
     *
     * @param array<string, mixed> $data The products fields (name, description, country_of_origin, manufacturer…)
     * @param list<array<string, mixed>> $variants Each variant: [color_name, color_hex, price, discount, stock, gender, image_path, is_default, sort_order]
     * @param list<int> $categoryIds An array of ids (at least one)
     * @param int   $adminId     The id of the admin creating it
     * @return int|null          The new product's id, or null on failure
     */
    public static function create(array $data, array $variants, array $categoryIds, int $adminId): ?int
    {
        if (empty($variants)) {
            error_log("AdminProductModel::create — variants array is empty.");
            return null;
        }
        if (empty($categoryIds)) {
            error_log("AdminProductModel::create — categoryIds array is empty.");
            return null;
        }

        $db = self::db();
        try {
            $db->beginTransaction();

            $db->prepare("
                INSERT INTO products
                    (name, description, country_of_origin, manufacturer,
                     price, discount_percentage, gender_category,
                     image_path, date_added, is_visible,
                     created_by_admin_id, updated_by_admin_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, ?, ?)
            ")->execute([
                trim($data['name']),
                trim($data['description']   ?? ''),
                trim($data['country']        ?? ''),
                trim($data['manufacturer']   ?? ''),
                (float)($data['price']       ?? 0),
                (float)($data['discount']    ?? 0),
                in_array($data['gender'] ?? 'both', ['male', 'female', 'both']) ? $data['gender'] : 'both',
                $data['image_path'] ?? null,
                $adminId,
                $adminId,
            ]);

            $productId = (int)$db->lastInsertId();

            self::insertVariants($db, $productId, $variants);
            self::syncCategories($db, $productId, $categoryIds);

            $db->commit();
            return $productId;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("AdminProductModel::create Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update an existing product with its variants and categories, in one transaction.
     *
     * @param int   $productId   The product id
     * @param array<string, mixed> $data The products fields to update
     * @param list<array<string, mixed>> $variants Each variant with its data
     * @param list<int> $categoryIds An array of ids
     * @param int   $adminId     The id of the admin making the change
     *
     * The return value has three states, as in delete():
     *   true  — genuinely updated
     *   false — the id does not exist
     *   null  — a technical failure or invalid input
     *
     * @return bool|null
     */
    public static function update(int $productId, array $data, array $variants, array $categoryIds, int $adminId): ?bool
    {
        if (empty($variants)) {
            error_log("AdminProductModel::update — variants array is empty.");
            return null;
        }
        if (empty($categoryIds)) {
            error_log("AdminProductModel::update — categoryIds array is empty.");
            return null;
        }

        $db = self::db();
        try {
            $db->beginTransaction();

            // ⚠️ Existence is checked explicitly rather than through the UPDATE's
            // rowCount. In MySQL, an UPDATE with identical values reports **zero affected
            // rows** even though the row exists, so relying on it conflates "does not
            // exist" with "nothing changed" — and makes saving an unmodified product answer
            // "Product not found". (delete() can rely on rowCount, because a deletion has
            // no such ambiguity.)
            $exists = $db->prepare("SELECT 1 FROM products WHERE id = ? LIMIT 1");
            $exists->execute([$productId]);
            if ($exists->fetchColumn() === false) {
                $db->rollBack();
                return false;
            }

            $fields = [
                'name'                => trim($data['name']),
                'description'         => trim($data['description']   ?? ''),
                'country_of_origin'   => trim($data['country']       ?? ''),
                'manufacturer'        => trim($data['manufacturer']   ?? ''),
                'price'               => (float)($data['price']       ?? 0),
                'discount_percentage' => (float)($data['discount']    ?? 0),
                'gender_category'     => in_array($data['gender'] ?? 'both', ['male', 'female', 'both'])
                                            ? $data['gender'] : 'both',
                'updated_at'          => date('Y-m-d H:i:s'),
                'updated_by_admin_id' => $adminId,
            ];

            if (!empty($data['image_path'])) {
                $fields['image_path'] = $data['image_path'];
            }

            $setSql = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
            $params = array_values($fields);
            $params[] = $productId;

            // The rule fires on "a sequence of UPDATEs then return true", because it sees
            // nothing proving the row exists. The justification here: existence is checked
            // explicitly above this line with a SELECT, and rowCount was deliberately not
            // used — see the comment at the top of the transaction. The rule firing here is
            // its purpose: to force this justification to be written rather than pass
            // silently.
            // nosemgrep: cairo-execute-then-return-true
            $db->prepare("UPDATE products SET {$setSql} WHERE id = ?")->execute($params);

            // ⚠️ A second exemption, and it is not a duplicate of the one above. The rule
            // matches a *range* ending at `return true`, so it produces more than one match
            // in this function: one starting at the UPDATE, another starting here. A
            // `nosemgrep` suppresses only the match that begins on its own line, so the
            // exemption above left this one firing — the justification was written and the
            // gate stayed red anyway, which is the worst of both.
            //
            // Same justification: the row's existence is checked with a SELECT above the
            // transaction, and rowCount is deliberately not used — see the comment at the
            // top of the transaction for why.
            //
            // nosemgrep: cairo-execute-then-return-true
            $db->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$productId]);
            self::insertVariants($db, $productId, $variants);
            self::syncCategories($db, $productId, $categoryIds);

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("AdminProductModel::update Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a product with its variants and pivot rows, in one transaction.
     * The variant images are deleted by the controller before this method is called.
     *
     * The return value has three states, deliberately:
     *   true  — genuinely deleted (at least one row affected)
     *   false — the id does not exist, so nothing was deleted (and the transaction rolled back)
     *   null  — a technical failure (an exception)
     * Separating false from null is intentional: the caller needs a different message for
     * each, and must not write an audit record or raise a notification in the false case.
     */
    public static function delete(int $productId): ?bool
    {
        $db = self::db();
        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$productId]);
            $db->prepare("DELETE FROM product_category_pivot WHERE product_id = ?")->execute([$productId]);

            $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$productId]);

            // A DELETE against a non-existent id succeeds without error and removes zero
            // rows. Without this check the method returned true for a product that never
            // existed, and the controller wrote an audit row and a notification about a
            // deletion that never happened.
            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                return false;
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("AdminProductModel::delete Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * A product's total stock across all of its variants (colours) — used to detect a
     * stock-out after a product is added or edited. It returns 1 (not 0) when the query
     * fails, specifically to avoid raising a false "out of stock" notification caused by a
     * technical error rather than a genuine stock-out.
     */
    public static function getTotalStock(int $productId): int
    {
        try {
            $stmt = self::db()->prepare("
                SELECT COALESCE(SUM(stock_quantity), 0)
                FROM product_variants
                WHERE product_id = ?
            ");
            $stmt->execute([$productId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("AdminProductModel::getTotalStock Error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * A product's name by id alone — used before a hard delete, because the row is about
     * to vanish from the table and its name will then be unknowable for the notification
     * and the audit record.
     */
    /**
     * A product's name by its id.
     *
     * Delegates to ProductModel: the query used to be written here and in the store model
     * in identical text. A product's name is not an admin-panel concept, so the source of
     * truth is now ProductModel. This method was kept because the admin controllers call it.
     */
    public static function getNameById(int $productId): ?string
    {
        return ProductModel::getNameById($productId);
    }

    /**
     * Hide or show a product (toggling is_visible).
     * Returns the new is_visible value, or null on failure.
     */
    public static function toggleVisibility(int $productId): ?int
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("SELECT is_visible FROM products WHERE id = ? LIMIT 1");
            $stmt->execute([$productId]);
            $current = $stmt->fetchColumn();
            if ($current === false) {
                return null;
            }
            $newVal = $current ? 0 : 1;
            $db->prepare("UPDATE products SET is_visible = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$newVal, $productId]);
            return $newVal;
        } catch (Exception $e) {
            error_log("AdminProductModel::toggleVisibility Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch a product's variant image paths — so they can be removed from disk before the
     * product itself is deleted.
     *
     * @return list<string> The variant image paths
     */
    public static function getVariantImagePaths(int $productId): array
    {
        try {
            $stmt = self::db()->prepare(
                "SELECT image_path FROM product_variants WHERE product_id = ? AND image_path IS NOT NULL"
            );
            $stmt->execute([$productId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log("AdminProductModel::getVariantImagePaths Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Insert a batch of variants inside an already-open transaction.
     *
     * @param list<array<string, mixed>> $variants The variant rows as ProductVariantUploader builds them
     */
    private static function insertVariants(\PDO $db, int $productId, array $variants): void
    {
        $ins = $db->prepare("
            INSERT INTO product_variants
                (product_id, color_name, color_hex, price, discount_percentage,
                 stock_quantity, gender_category, image_path, is_default, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($variants as $idx => $v) {
            $ins->execute([
                $productId,
                trim($v['color_name']  ?? ''),
                trim($v['color_hex']   ?? '') ?: null,
                (float)($v['price']    ?? 0),
                (float)($v['discount'] ?? 0),
                (int)($v['stock']      ?? 0),
                in_array($v['gender'] ?? 'both', ['male', 'female', 'both']) ? $v['gender'] : 'both',
                $v['image_path'] ?? null,
                (int)(!empty($v['is_default'])),
                (int)($v['sort_order'] ?? $idx),
            ]);
        }
    }

    /**
     * Upload a single variant image to disk and return the relative path.
     * The controller calls it per variant, before calling create() or update().
     *
     * @param array<string, mixed> $fileEntry A single file entry from $_FILES
     * @param string $uploadDir  The absolute directory (with a trailing slash)
     * @return string|null       The relative path (images/xxx.jpg), or null
     */
    public static function uploadVariantImage(array $fileEntry, string $uploadDir): ?string
    {
        // The logic lives in App\Core\ImageUpload: it used to be written here and in
        // BrandingModel::uploadSliderImage as two identical copies separated only by the
        // name prefix — so any security tightening was applied to one while the other
        // stayed as it was. The new size limit surfaced that immediately.
        return \App\Core\ImageUpload::store($fileEntry, $uploadDir, 'product_');
    }
}
