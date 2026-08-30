<?php

namespace App\Models;

use Exception;
use App\Core\Model;

/**
 * CartModel — the user's cart, on the server.
 *
 * ══════════════════════════════════════════════════════════════
 * What it stores, and what it does not
 * ══════════════════════════════════════════════════════════════
 *
 * It stores **what and how many** and nothing else: a product, a colour, a quantity.
 * It stores no price, no name and no image — all of those are read from
 * `product_variants` at display time.
 *
 * ⚠️ The absence of a price column is a decision, not an oversight. The first phase
 * closed the door on "the price comes from the client", and a price column here opens
 * it from another side: a monetary value stored away from its source becomes, over
 * time, a second source of truth, and one day somebody reads it instead of the
 * original.
 *
 * That is why `getForUser` joins `product_variants` on every read: the price the
 * customer sees in their cart is the database's price **now**, not the price at the
 * moment of adding. Which makes a price change visible in the cart before checkout
 * rather than at it.
 *
 * ══════════════════════════════════════════════════════════════
 * No guest cart
 * ══════════════════════════════════════════════════════════════
 *
 * The cart button and the "add to cart" button are login-guarded in all three
 * templates, and a signed-out visitor is pushed to the login modal. So every row here
 * has a known owner from the moment it is created — no merge logic and no session id.
 */
class CartModel extends Model
{
    /**
     * The maximum quantity for a single line.
     *
     * It matches CheckoutController::MAX_ITEM_QTY deliberately: a cart accepting what
     * checkout refuses means a customer who discovers the refusal at the last step.
     */
    public const MAX_QTY = 100;

    /**
     * The user's cart, with live display data from the database.
     *
     * The shape matches what `js/features/cart.js` expects exactly — the same keys as
     * the old local cart (`id`, `variant_id`, `quantity`, `price` …) — so the client
     * needs no second translation. The only translation left is when sending to
     * `/checkout`, as the first phase established.
     *
     * `is_visible = 1` in the join: a product hidden after being added disappears from
     * the cart, rather than reaching checkout and being refused there.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForUser(int $userId): array
    {
        try {
            $stmt = self::db()->prepare("
                SELECT
                    c.product_id                AS id,
                    c.variant_id,
                    c.quantity,
                    p.name,
                    v.color_name,
                    v.image_path,
                    v.price_after_discount      AS price,
                    v.stock_quantity            AS stock
                FROM cart_items c
                JOIN product_variants v ON v.id = c.variant_id
                JOIN products p         ON p.id = c.product_id
                WHERE c.user_id = ?
                  AND p.is_visible = 1
                ORDER BY c.created_at ASC, c.id ASC
            ");
            $stmt->execute([$userId]);

            return array_map(static function (array $row): array {
                $row['id']         = (int) $row['id'];
                $row['variant_id'] = (int) $row['variant_id'];
                $row['quantity']   = (int) $row['quantity'];
                $row['price']      = (float) $row['price'];
                $row['stock']      = (int) $row['stock'];
                return $row;
            }, $stmt->fetchAll());
        } catch (Exception $e) {
            error_log('CartModel::getForUser Error: ' . $e->getMessage());
            reportException($e);
            return [];
        }
    }

    /**
     * Adds a quantity to a line, or creates it.
     *
     * ── Why ON DUPLICATE KEY rather than SELECT then INSERT/UPDATE ──
     *
     * Because the latter is an outright race: two tabs adding the same colour at the
     * same instant both read "not there" and both insert a row — and the unique key stops
     * them with an error rather than producing the right result. One statement lets the
     * database settle it itself, in a single round trip.
     *
     * And the upper bound lives inside the SQL, in a LEAST: computing it in PHP means a
     * read before the write, which is the same race through another door.
     *
     * ⚠️ It does not check stock. A cart is an intention, not a reservation — and the
     * reservation happens in placeOrder, inside a transaction that locks the row. A stock
     * check here makes a promise the cart cannot keep, and leads the customer to believe
     * the item is held for them.
     */
    public static function add(int $userId, int $productId, int $variantId, int $qty): bool
    {
        if ($qty < 1 || $qty > self::MAX_QTY) {
            return false;
        }

        // ⚠️ The variant must belong to the product that was sent.
        //
        // The two foreign keys each check only that their own row exists, and nothing
        // ties them together — so a request pairing `product_id` with one product and
        // `variant_id` with a colour of another passes both of them.
        //
        // The effect was measured on a live server: a cart showing **one product's name
        // with another product's price** ("Apple Watch" at a PS4's price). It cannot be
        // bought — placeOrder checks the relationship and refuses — but it is a false row
        // in the database, a screen lying to the customer, and a cart that never reaches
        // checkout for no comprehensible reason.
        //
        // And the check belongs here, not in placeOrder alone: refusing early, at the
        // moment of adding, is more honest than refusing late at the final step.
        $stock = self::stockForVariantOfProduct($productId, $variantId);

        if ($stock === null) {
            return false;
        }

        if ($stock <= 0) {
            return false;
        }

        try {
            // ══════════════════════════════════════════════════════
            // ⚠️ The ceiling is the stock, not MAX_QTY alone
            // ══════════════════════════════════════════════════════
            //
            // It used to be `LEAST(quantity + VALUES(quantity), MAX_QTY)` — meaning the
            // cart accepted a hundred units of a product with five in stock. The only guard
            // against that lived in the browser, and it **falls over on rapid clicking**:
            // each click reads the local mirror before the previous click's response
            // arrives, sees the old quantity, and passes the check.
            //
            // Reported from real use, and reproduced: ten parallel adds against a product
            // with 5 in stock → **the cart holds 10**. And the badge shows 10 because it is
            // telling the truth — the database really does hold 10. It only returns to 5
            // when the customer opens the cart and syncCartWithStock runs.
            //
            // ── And this does not contradict "a cart is an intention, not a reservation" ──
            //
            // A reservation would mean stopping somebody else from buying because the item
            // is in your cart — and that is still refused; reserving happens in placeOrder
            // alone. This is a plausibility ceiling: the cart should not hold what does not
            // exist. The difference is that the first takes from somebody else, and the
            // second stops you from being lied to.
            //
            // And the computation lives in the SQL rather than in PHP: the addition and
            // the ceiling in one atomic statement, so ten concurrent statements all settle
            // at the stock level with no race — which is exactly what any read-then-write
            // check fails to do.
            $stmt = self::db()->prepare("
                INSERT INTO cart_items (user_id, product_id, variant_id, quantity)
                VALUES (?, ?, ?, LEAST(?, ?, " . self::MAX_QTY . "))
                ON DUPLICATE KEY UPDATE
                    quantity = LEAST(quantity + VALUES(quantity), ?, " . self::MAX_QTY . ")
            ");

            return $stmt->execute([$userId, $productId, $variantId, $qty, $stock, $stock]);
        } catch (Exception $e) {
            // The usual cause: a variant deleted between the page rendering and the click —
            // the foreign key refuses, and that is the correct refusal.
            error_log('CartModel::add Error: ' . $e->getMessage());
            reportException($e);
            return false;
        }
    }

    /**
     * Sets a line's quantity absolutely — or deletes it at zero.
     *
     * Zero is a deletion rather than a quantity: a line with a quantity of zero shows in
     * the cart and is never ordered, a meaningless state that confuses both the display
     * and the counter.
     */
    public static function setQuantity(int $userId, int $variantId, int $qty): bool
    {
        if ($qty <= 0) {
            return self::remove($userId, $variantId);
        }

        if ($qty > self::MAX_QTY) {
            return false;
        }

        try {
            // ⚠️ The stock ceiling lives inside the statement itself.
            //
            // This method used to write the requested quantity as given: `setQuantity` with
            // 100 against a variant holding 2 succeeded, so the cart carried a hundred units
            // of an item with two in stock.
            //
            // Stranger still, the guard already existed on the neighbouring path: `add`
            // caps with `LEAST(?, stock, MAX_QTY)`, ever since the "the number keeps going
            // up" report. So the door was shut on the adding side and open on the updating
            // side — and `POST /cart/update` accepted any number outright.
            //
            // And why a JOIN rather than a read then a write: reading the stock in one
            // statement and writing in another opens a race window between them — precisely
            // the hole that was closed in `add` by putting the ceiling inside the SQL. The
            // stock is read and applied in one statement, so there is no window at all.
            $stmt = self::db()->prepare(
                'UPDATE cart_items c
                    JOIN product_variants v ON v.id = c.variant_id
                    SET c.quantity = LEAST(?, v.stock_quantity, ' . self::MAX_QTY . ')
                  WHERE c.user_id = ? AND c.variant_id = ? AND v.stock_quantity > 0'
            );
            $stmt->execute([$qty, $userId, $variantId]);

            // Zero stock: the condition above blocks the update, so the line keeps its old
            // quantity — a quantity of an item that has run out. Deleting is more honest than
            // keeping it: `add` refuses to admit an out-of-stock variant in the first place,
            // so it is not right for one path to guard against it while the other lets it be.
            if ($stmt->rowCount() === 0 && self::hasVariant($userId, $variantId)) {
                $stock = self::stockForVariant($variantId);
                if ($stock !== null && $stock <= 0) {
                    return self::remove($userId, $variantId);
                }
            }

            // rowCount = 0 means "no line with this id for this user" — either because it
            // does not exist, or because it belongs to somebody else. From the caller's side
            // both are one refusal, and the user_id condition in the same statement is what
            // prevents editing other people's carts (IDOR).
            //
            // ⚠️ And updating to the same value is accepted: MySQL returns 0 in that case,
            // so existence is checked explicitly rather than reporting a failure over a
            // success.
            return $stmt->rowCount() > 0 || self::hasVariant($userId, $variantId);
        } catch (Exception $e) {
            error_log('CartModel::setQuantity Error: ' . $e->getMessage());
            reportException($e);
            return false;
        }
    }

    /** Deletes a line. The ownership condition is in the same statement — not a separate check that gets forgotten. */
    public static function remove(int $userId, int $variantId): bool
    {
        try {
            $stmt = self::db()->prepare(
                'DELETE FROM cart_items WHERE user_id = ? AND variant_id = ?'
            );
            $stmt->execute([$userId, $variantId]);

            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('CartModel::remove Error: ' . $e->getMessage());
            return false;
        }
    }

    /** Empties the user's cart — called after an order succeeds. */
    public static function clear(int $userId): bool
    {
        try {
            return self::db()
                ->prepare('DELETE FROM cart_items WHERE user_id = ?')
                ->execute([$userId]);
        } catch (Exception $e) {
            error_log('CartModel::clear Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The total number of items in the user's cart — for the counter badge.
     *
     * The sum of quantities, not the count of lines: the badge says "how many items",
     * not "how many colours".
     */
    public static function countItems(int $userId): int
    {
        try {
            $stmt = self::db()->prepare(
                'SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE user_id = ?'
            );
            $stmt->execute([$userId]);

            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log('CartModel::countItems Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Does this variant really belong to this product?
     *
     * One query over one column — cheaper than a false row discovered at checkout.
     */
    private static function stockForVariantOfProduct(int $productId, int $variantId): ?int
    {
        $stmt = self::db()->prepare(
            'SELECT stock_quantity FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
        );
        $stmt->execute([$variantId, $productId]);
        $stock = $stmt->fetchColumn();

        // false means "no row" — either the variant does not exist, or it does not belong
        // to this product. From the caller's side both are one refusal.
        return $stock === false ? null : (int) $stock;
    }

    /**
     * A variant's stock, by its id alone.
     *
     * It differs from stockForVariantOfProduct: that one also verifies the variant
     * belongs to the requested product, a check needed when **adding**, where both ids
     * arrive together from the client. setQuantity receives no product_id at all — the
     * line already exists and its ownership is guarded by the user_id condition.
     *
     * @return int|null null if the variant does not exist.
     */
    private static function stockForVariant(int $variantId): ?int
    {
        $stmt = self::db()->prepare(
            'SELECT stock_quantity FROM product_variants WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$variantId]);
        $stock = $stmt->fetchColumn();

        return $stock === false ? null : (int) $stock;
    }

    /** Does the user have a line for this variant? */
    private static function hasVariant(int $userId, int $variantId): bool
    {
        $stmt = self::db()->prepare(
            'SELECT 1 FROM cart_items WHERE user_id = ? AND variant_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $variantId]);

        return (bool) $stmt->fetchColumn();
    }
}
