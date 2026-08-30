<?php

namespace App\Models;

use App\Core\Model;
use Exception;

/**
 * CategoryModel — managing the dynamic categories table (after the ENUM→VARCHAR migration).
 */
class CategoryModel extends Model
{
    /** The four core categories in their fixed, mandatory order — do not change. */
    public const CORE_ORDER = ['phone', 'computer', 'accessories', 'gaming'];

    /**
     * Every category, ordered: the core ones first in CORE_ORDER, then the rest
     * alphabetically. Each row includes product_count (how many products are actually
     * linked through product_category_pivot).
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

            // Sort the core ones by the fixed CORE_ORDER
            $orderedCore = [];
            foreach (self::CORE_ORDER as $name) {
                if (isset($core[$name])) {
                    $orderedCore[] = $core[$name];
                }
            }

            // Sort the rest alphabetically
            usort($extra, fn($a, $b) => strcasecmp($a['name'], $b['name']));

            return array_merge($orderedCore, $extra);
        } catch (Exception $e) {
            error_log("CategoryModel::getAllOrdered Error: " . $e->getMessage());
            return [];
        }
    }

    /** Checks whether a name exists (case-insensitively) — used to prevent duplicates on add. */
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
            // Safety: on an error we assume it exists, to prevent a possible duplicate
            return true;
        }
    }

    /**
     * Suggests the closest categories by meaning (textual similarity) to a name the
     * admin typed. Used in two situations: (a) while typing in the "add category" field,
     * to prevent duplicates, and (b) when deleting a category, to suggest a destination.
     * Returns an array sorted by descending similarity (similar_text %).
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
     * Add a new category. Returns the new id, or null on failure or a duplicate.
     * is_core is always set to 0 — a new core category cannot be created through the UI.
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

    /** Returns true if the category is a core one (protected from deletion). */
    public static function isCore(int $id): bool
    {
        try {
            $stmt = self::db()->prepare("SELECT is_core FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("CategoryModel::isCore Error: " . $e->getMessage());
            // Safety: on an error we block the deletion
            return true;
        }
    }

    /**
     * Delete a category, first moving all of its products to $destinationCategoryId.
     * Refused if the category is a core one (is_core=1) or if $destinationCategoryId does
     * not exist. One transaction: move the links in product_category_pivot, then delete
     * the row.
     *
     * The logic: UPDATE IGNORE skips duplicates (a product already linked to both
     * categories), then DELETE clears any old row still pointing at the deleted category.
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

            // Move every product link to the new destination, avoiding a duplicate composite key
            $db->prepare("
                UPDATE IGNORE product_category_pivot
                SET category_id = ?
                WHERE category_id = ?
            ")->execute([$destinationCategoryId, $categoryId]);

            // Delete any duplicate links left over (the product was already linked to both)
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

    /** Fetches one category's name by id — used in the UI's confirmation messages. */
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
