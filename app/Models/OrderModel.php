<?php

namespace App\Models;

use App\Core\Model;
use Exception;

/**
 * OrderModel — covers the orders, order_items and user_addresses tables.
 */
class OrderModel extends Model
{
    // ════════════════════════════════════════════════════════
    // User addresses
    // ════════════════════════════════════════════════════════

    /** Fetch a user's addresses. */
    /**
     * @return list<array<string, mixed>>
     */
    public static function getUserAddresses(int $userId): array
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id ASC"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("OrderModel::getUserAddresses Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Builds a textual snapshot of the address at the moment of the order.
     *
     * It is called from inside the placeOrder transaction, so the row is read in the
     * same window the rest of the order is written in — there is no window in which the
     * address could change between the read and the write.
     *
     * A missing address (deleted between the customer choosing it and pressing
     * "confirm") returns null rather than an empty string: "no address on record" is a
     * different fact from "the address is known and it is blank", and telling the two
     * apart is the whole value of the snapshot.
     *
     * @return array{text: ?string, phone: ?string}
     */
    private static function addressSnapshot(int $addressId): array
    {
        $stmt = self::db()->prepare(
            "SELECT label, full_address, city, country, phone_number
             FROM user_addresses WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$addressId]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['text' => null, 'phone' => null];
        }

        // array_filter drops the empties, so no dangling commas appear for an address
        // with no city or no label.
        $parts = array_filter([
            $row['label']        ?? '',
            $row['full_address'] ?? '',
            $row['city']         ?? '',
            $row['country']      ?? '',
        ], static fn ($p): bool => trim((string) $p) !== '');

        return [
            'text'  => $parts === [] ? null : implode(', ', $parts),
            'phone' => ($row['phone_number'] ?? '') !== '' ? $row['phone_number'] : null,
        ];
    }

    /** Add a new address. */
    /**
     * @param array<string, mixed> $data
     */
    public static function addAddress(int $userId, array $data): ?int
    {
        try {
            $db = self::db();

            // If the new address is_default → clear the default from the rest
            if (!empty($data['is_default'])) {
                $db->prepare("UPDATE user_addresses SET is_default=0 WHERE user_id=?")
                   ->execute([$userId]);
            }

            $stmt = $db->prepare(
                "INSERT INTO user_addresses (user_id, label, country, city, full_address, phone_number, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $data['label']        ?? 'Home',
                $data['country']      ?? null,
                $data['city']         ?? null,
                $data['full_address'],
                $data['phone_number'] ?? null,
                !empty($data['is_default']) ? 1 : 0,
            ]);
            return (int)$db->lastInsertId();
        } catch (Exception $e) {
            error_log("OrderModel::addAddress Error: " . $e->getMessage());
            return null;
        }
    }

    /** Delete an address. */
    public static function deleteAddress(int $addressId, int $userId): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("DELETE FROM user_addresses WHERE id=? AND user_id=?");
            return $stmt->execute([$addressId, $userId]);
        } catch (Exception $e) {
            error_log("OrderModel::deleteAddress Error: " . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════
    // Orders
    // ════════════════════════════════════════════════════════

    /** The order was created. */
    public const PLACE_OK = 'ok';

    /** One or more item prices no longer match what the customer was shown — the order is refused. */
    public const PLACE_PRICE_CHANGED = 'price_changed';

    /** An item no longer exists, or was hidden after being added to the cart. */
    public const PLACE_UNAVAILABLE = 'unavailable';

    /** Stock is no longer sufficient for one of the items. */
    public const PLACE_OUT_OF_STOCK = 'out_of_stock';

    /** A technical fault — no fault of the customer's, nor of their cart's. */
    public const PLACE_ERROR = 'error';

    /**
     * Create a new order: price from the database, then decrement the stock, inside one
     * transaction.
     *
     * ══════════════════════════════════════════════════════════
     * ⚠️ The price never comes from the client. Ever.
     * ══════════════════════════════════════════════════════════
     *
     * This method used to read `$item['price']`, sum it into `total_amount` and write
     * it into `price_at_purchase`. And the cart is built in `localStorage` and sent by
     * the browser as-is — meaning **the price was user input**. An order submitted with
     * `price: 0.01` passed with a valid token, a valid session and a sound `auth` guard,
     * genuinely decremented the stock, and reached the admin as an entirely legitimate
     * order. And the throttle does not see it: that is one order, not a thousand.
     *
     * Now every monetary value is read from the database inside the same transaction
     * that will write the stock — so there is no window between reading the price and
     * reserving the quantity.
     *
     * ── What still comes from the client ──────────────────────
     *
     * Three fields and no fourth: `product_id`, `variant_id` and `qty` — that is, "what
     * I want and how many". And `shown_price` is a fourth field that is **compared, never
     * stored, and never computed from**: it is what the browser displayed to the
     * customer, and its presence answers exactly one question — did the price change
     * between the moment of display and the moment of submission?
     *
     * Even `color_name_snapshot` is now read from the database: it used to come from the
     * client, and a snapshot written by the party it is meant to document is not a
     * snapshot.
     *
     * ── Why refuse rather than proceed at the server's price ──
     *
     * An explicit product decision: the customer is never surprised by an amount they
     * did not agree to. And cash on delivery moves the surprise to the doorstep rather
     * than the screen — where its cost is a refused delivery and a returned shipment. So
     * when a price differs, the whole operation is cancelled and the correct prices are
     * returned for the customer to review their cart and decide.
     *
     * ── Comparing in minor units rather than decimals ─────────
     *
     * `0.1 + 0.2 !== 0.3` in any binary floating-point arithmetic, and
     * `price_after_discount` is a column computed with `round(...,2)`. Comparing on
     * `float` would have refused sound orders over differences that exist only in the
     * representation. Minor units are integers, and their equality is real equality.
     *
     * ── The return value ──────────────────────────────────────
     *
     * An array, not a `?int`. The outcome is no longer binary: "succeeded" and "failed"
     * were both collapsed into `null`, so the controller told the customer "some items
     * may be out of stock" about a database outage, a changed price, or a hidden product
     * alike. The code separates the cases, and the message becomes honest.
     *
     * @param  list<array{product_id: int, variant_id: int|null, qty: int, shown_price: float}> $items
     *         Items already sanitised by the controller:
     *                      [['product_id'=>int,'variant_id'=>?int,
     *                        'qty'=>int,'shown_price'=>float], ...]
     * @return array{status:string, order_id?:int, duplicate?:bool,
     *               items?:list<array<string,mixed>>}
     */
    public static function placeOrder(
        int $userId,
        int $addressId,
        array $items,
        string $paymentMethod,
        string $idempotencyKey
    ): array {
        if (empty($items)) {
            return ['status' => self::PLACE_ERROR];
        }

        $db = self::db();

        try {
            // ── Idempotency: before the transaction, deliberately ──
            //
            // Resending the same key must return the existing order rather than open a
            // transaction, lock stock rows and then roll them back. The column carries a
            // UNIQUE constraint, so a race between two concurrent requests fails at the
            // insert rather than here.
            $dup = $db->prepare("SELECT order_id FROM orders WHERE idempotency_key=? LIMIT 1");
            $dup->execute([$idempotencyKey]);
            $existing = $dup->fetchColumn();
            if ($existing) {
                return [
                    'status'    => self::PLACE_OK,
                    'order_id'  => (int) $existing,
                    'duplicate' => true,
                ];
            }

            $db->beginTransaction();

            // ── Sorting before any lock ──────────────────────────
            //
            // The sort used to happen after the order row was inserted and before the stock
            // loop, and its purpose is to prevent a deadlock between two concurrent orders
            // locking the same rows in opposite orders. It now precedes **the locking read**
            // as well, because the locking begins there rather than at the UPDATE.
            usort($items, fn($a, $b) => ($a['variant_id'] ?? 0) <=> ($b['variant_id'] ?? 0));

            // ── Reading the real price and stock, under a lock ───
            //
            // ⚠️ There is no path for an item without a variant — a measured decision, not
            // an oversight.
            //
            // The store enforces at least one variant per product, explicitly in two
            // places: AdminProductsController::storeAdd and ::storeEdit both refuse with
            // "At least one variant with a valid name and price is required". And the
            // database confirms it: zero products out of sixteen without a variant.
            //
            // And every add-to-cart path passes a real id (data-variant-id on both product
            // pages, and cart.js carries it along). So an item without a variant never
            // arrives from the store front at all.
            //
            // The alternative — decrementing products.stock_quantity for that case — would
            // have created **two sources of truth for stock** inside one method: a column in
            // products and another in product_variants, with nothing reconciling them. That
            // is a far higher price than refusing a case that does not occur.
            $variantIds = [];
            foreach ($items as $item) {
                if (empty($item['variant_id'])) {
                    $db->rollBack();
                    return ['status' => self::PLACE_UNAVAILABLE];
                }
                $variantIds[] = (int) $item['variant_id'];
            }

            $variants = ProductModel::findVariantsForUpdate($variantIds);

            // ── Pricing and validation before any row is written ──
            //
            // The whole loop before any INSERT: a refusal then needs no rolling back of a
            // write that already happened, and more importantly it collects **all** the
            // changed prices rather than the first. A customer with three changed prices
            // deserves to see them at once, not to retry three times discovering them one
            // by one.
            $priced      = [];
            $total       = 0.0;
            $priceDrifts = [];

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $variantId = (int) $item['variant_id'];
                $qty       = (int) $item['qty'];
                $shown     = (float) ($item['shown_price'] ?? -1);

                $row = $variants[$variantId] ?? null;

                // Either it does not exist, or it was hidden after being added to the cart
                // (the query carries is_visible = 1). From the customer's side both are the
                // same: this item can no longer be bought.
                if ($row === null) {
                    $db->rollBack();
                    return ['status' => self::PLACE_UNAVAILABLE];
                }

                // ⚠️ The variant must belong to the product that was sent. Without this
                // check an order could pair a cheap variant with a different product, and an
                // order_items row would be stored for a product whose price was never used.
                if ((int) $row['product_id'] !== $productId) {
                    $db->rollBack();
                    return ['status' => self::PLACE_UNAVAILABLE];
                }

                $unitPrice = (float) $row['price_after_discount'];

                if ((int) round($unitPrice * 100) !== (int) round($shown * 100)) {
                    $priceDrifts[] = [
                        'product_id'  => $productId,
                        'variant_id'  => $variantId,
                        'name'        => $row['product_name'],
                        'shown_price' => round($shown, 2),
                        'price'       => round($unitPrice, 2),
                    ];
                    continue;
                }

                $total += $unitPrice * $qty;

                $priced[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'qty'        => $qty,
                    'unit_price' => $unitPrice,
                    // The snapshot comes from the database, not from the client.
                    'color_name' => $row['color_name'] ?? null,
                ];
            }

            if ($priceDrifts !== []) {
                $db->rollBack();
                return [
                    'status' => self::PLACE_PRICE_CHANGED,
                    'items'  => $priceDrifts,
                ];
            }

            // ── The address snapshot ─────────────────────────────
            //
            // The address is copied as text into the order row, not merely referenced.
            //
            // address_id used to be a live foreign key with ON DELETE SET NULL, meaning the
            // order's address did not belong to the order: a user editing their address
            // retroactively changed the destination of an order that had **already been
            // delivered**, and deleting it erased the address of completed orders
            // permanently, with no copy anywhere.
            //
            // This is a class of error that shows up in no functional test and on no
            // screen — it shows up the day somebody asks "where was this order sent?" and
            // the question has no answer. The key stays for the relationship, and the
            // snapshot is the historical record.
            $snapshot = self::addressSnapshot($addressId);

            // ── The writes ───────────────────────────────────────
            $stmt = $db->prepare(
                "INSERT INTO orders (user_id, address_id, address_snapshot,
                                    address_phone_snapshot, total_amount, payment_method,
                                    status, idempotency_key, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'not_taken', ?, NOW())"
            );
            $stmt->execute([
                $userId,
                $addressId,
                $snapshot['text'],
                $snapshot['phone'],
                $total,
                $paymentMethod,
                $idempotencyKey,
            ]);
            $orderId = (int)$db->lastInsertId();

            $stmtItem = $db->prepare(
                "INSERT INTO order_items
                    (order_id, product_id, variant_id, color_name_snapshot, quantity, price_at_purchase)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmtStock = $db->prepare(
                "UPDATE product_variants SET stock_quantity = stock_quantity - ?
                 WHERE id = ? AND stock_quantity >= ?"
            );

            foreach ($priced as $item) {
                $stmtItem->execute([
                    $orderId,
                    $item['product_id'],
                    $item['variant_id'],
                    $item['color_name'],
                    $item['qty'],
                    $item['unit_price'],
                ]);

                // The `stock_quantity >= ?` condition stays even though the row is locked and
                // was read above: the lock prevents another request changing it, not a bug in
                // this method. And the condition is what makes the reservation atomic in
                // either case.
                //
                // And there is no `if ($variantId)` branch here any more: every item reaching
                // this line certainly carries a variant — the check above refuses anything
                // without one before a single price is read. The old branch meant an item
                // without a variant **was sold without touching any stock counter**: unlimited
                // sales of a product that had run out.
                $stmtStock->execute([$item['qty'], $item['variant_id'], $item['qty']]);
                if (!$stmtStock->rowCount()) {
                    $db->rollBack();
                    return ['status' => self::PLACE_OUT_OF_STOCK];
                }
            }

            $db->commit();
            return ['status' => self::PLACE_OK, 'order_id' => $orderId];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("OrderModel::placeOrder Error: " . $e->getMessage());
            reportException($e);
            return ['status' => self::PLACE_ERROR];
        }
    }

    /**
     * Cancel an order, by the user — a hard delete.
     * Only orders in the not_taken state: the stock is restored first, then the order row
     * is deleted along with its items and its expiry log, permanently (nothing remains in
     * the database).
     *
     * Notes on the deliberate scope:
     * - cancelAllPendingForUser() (the block cascade) and adminCancelDelivery() remain
     *   soft cancels (status='cancelled') — the audit trail is preserved.
     */
    public static function cancelOrder(int $orderId, int $userId): bool
    {
        $db = self::db();

        try {
            $db->beginTransaction();

            // Verify the order and its ownership
            $stmt = $db->prepare(
                "SELECT order_id, status, stock_restored
                 FROM orders WHERE order_id=? AND user_id=? LIMIT 1"
            );
            $stmt->execute([$orderId, $userId]);
            $order = $stmt->fetch();

            if (!$order || $order['status'] !== 'not_taken') {
                $db->rollBack();
                return false;
            }

            // Restore the stock first (before the delete, with the same stock_restored logic as elsewhere)
            if (!$order['stock_restored']) {
                $items = $db->prepare(
                    "SELECT variant_id, quantity FROM order_items WHERE order_id=? AND variant_id IS NOT NULL"
                );
                $items->execute([$orderId]);
                $restore = $db->prepare(
                    "UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id=?"
                );
                foreach ($items->fetchAll() as $item) {
                    $restore->execute([$item['quantity'], $item['variant_id']]);
                }
            }

            // A hard delete — order_items and order_expiry_log both hold a foreign key onto
            // orders.order_id (with ON DELETE CASCADE in the schema), but deleting explicitly
            // here guarantees a clear order independent of the database's behaviour.
            $db->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$orderId]);
            $db->prepare("DELETE FROM order_expiry_log WHERE order_id=?")->execute([$orderId]);
            $db->prepare("DELETE FROM orders WHERE order_id=?")->execute([$orderId]);

            $db->commit();
            return true;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("OrderModel::cancelOrder Error: " . $e->getMessage());
            reportException($e);
            return false;
        }
    }

    /**
     * Fetch the user's orders together with their items.
     *
     * @return list<array<string, mixed>>
     */
    public static function getUserOrders(int $userId): array
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "SELECT o.*,
                        ua.label AS address_label, ua.city, ua.country, ua.full_address
                 FROM orders o
                 LEFT JOIN user_addresses ua ON ua.id = o.address_id
                 WHERE o.user_id = ?
                 ORDER BY o.created_at DESC"
            );
            $stmt->execute([$userId]);
            $orders = $stmt->fetchAll();

            // Fetch the items for each order
            $stmtItems = $db->prepare(
                "SELECT oi.*, p.name AS product_name, pv.color_name, pv.image_path
                 FROM order_items oi
                 JOIN products p ON p.id = oi.product_id
                 LEFT JOIN product_variants pv ON pv.id = oi.variant_id
                 WHERE oi.order_id = ?"
            );
            foreach ($orders as &$order) {
                $stmtItems->execute([$order['order_id']]);
                $order['items'] = $stmtItems->fetchAll();
            }

            return $orders;
        } catch (Exception $e) {
            error_log("OrderModel::getUserOrders Error: " . $e->getMessage());
            return [];
        }
    }

    // ════════════════════════════════════════════════════════
    // User management — the admin panel (Users 02/03/04)
    // ════════════════════════════════════════════════════════

    /**
     * Every one of a user's orders with each order's item count, newest first.
     * Used by UserModel::addStrike() and by the user details page to show the history.
     *
     * @return list<array<string, mixed>>
     */
    public static function getOrdersForUser(int $userId): array
    {
        try {
            $stmt = self::db()->prepare("
                SELECT o.*,
                       (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS items_count
                FROM orders o
                WHERE o.user_id = ?
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("OrderModel::getOrdersForUser Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Cancel all of a user's pending orders (not_taken/taken) and restore their stock, in
     * one transaction.
     * Called automatically from UserModel::addStrike() on reaching three strikes (an
     * automatic block).
     * It respects the stock_restored column to prevent a double restore — the same logic
     * as cancelOrder().
     */
    public static function cancelAllPendingForUser(int $userId): void
    {
        $db = self::db();

        try {
            $db->beginTransaction();

            // The lock protects the stock, not merely the status: if another cancellation
            // of the same order happened at the same instant (a manual cancellation by the
            // customer alongside the automatic block), both would read `stock_restored = 0`
            // and restore the stock **twice** — and that is stock that gets sold while not
            // existing.
            $stmt = $db->prepare(
                "SELECT order_id, status, stock_restored
                 FROM orders
                 WHERE user_id = ? AND status IN ('not_taken', 'taken')
                 FOR UPDATE"
            );
            $stmt->execute([$userId]);
            $orders = $stmt->fetchAll();

            $updStatus    = $db->prepare("UPDATE orders SET status='cancelled' WHERE order_id=?");
            $items        = $db->prepare(
                "SELECT variant_id, quantity FROM order_items WHERE order_id=? AND variant_id IS NOT NULL"
            );
            $restore      = $db->prepare(
                "UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id=?"
            );
            $markRestored = $db->prepare("UPDATE orders SET stock_restored=1 WHERE order_id=?");

            foreach ($orders as $order) {
                $updStatus->execute([$order['order_id']]);

                if (!$order['stock_restored']) {
                    $items->execute([$order['order_id']]);
                    foreach ($items->fetchAll() as $item) {
                        $restore->execute([$item['quantity'], $item['variant_id']]);
                    }
                    $markRestored->execute([$order['order_id']]);
                }
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("OrderModel::cancelAllPendingForUser Error: " . $e->getMessage());
            reportException($e);
        }
    }

    // ════════════════════════════════════════════════════════
    // Order management — the admin panel (Orders 02/03/04/05)
    // ════════════════════════════════════════════════════════

    /** Mark every order read — called from AdminOrdersController::index() after the list renders. */
    public static function markAllOrdersNotified(): void
    {
        try {
            self::db()->prepare("UPDATE orders SET is_notified=1")->execute();
        } catch (Exception $e) {
            error_log("OrderModel::markAllOrdersNotified Error: " . $e->getMessage());
        }
    }

    /**
     * A lazy automatic release of taken orders past their 4-hour deadline:
     * status='taken' with taken_at more than 4 hours ago → recorded in order_expiry_log,
     * then the order returns to not_taken with taken_by_admin_id and taken_at cleared.
     * Called at the start of every request for an Orders page (the list or the details) —
     * with no cron job.
     *
     * @return array<int, array{order_id:int, previous_admin_id:?int}> The orders that were
     *         just reverted by this call, so the caller can log/notify. Empty array if none.
     */
    public static function releaseExpiredTakenOrders(): array
    {
        $db = self::db();
        $reverted = [];
        try {
            $db->beginTransaction();

            // The cut-off is computed inside MySQL (NOW()) — no comparison against PHP's
            // clock (PHP's and MySQL's timezones sometimes differ, so leaving the arithmetic
            // to the database guarantees accuracy)
            // ⚠️ `FOR UPDATE` is not a precaution here.
            //
            // This method runs with no cron: it is called at the start of **every** request
            // for an Orders page. So two admins opening the page together read the same
            // expired orders, each inserts a row into order_expiry_log and sends a
            // notification — and one event ends up with two records and two notifications.
            // The update itself is idempotent, so it does not reveal the fault; the log
            // alone bears witness to it.
            //
            // The lock serialises the two: the second waits until the first finishes, then
            // reads afresh and finds nothing left to expire.
            $stmt = $db->prepare(
                "SELECT order_id, taken_by_admin_id, taken_at FROM orders
                 WHERE status='taken' AND taken_at < DATE_SUB(NOW(), INTERVAL 4 HOUR)
                 FOR UPDATE"
            );
            $stmt->execute();
            $expired = $stmt->fetchAll();

            if ($expired) {
                $logStmt = $db->prepare(
                    "INSERT INTO order_expiry_log (order_id, previous_admin_id, taken_at)
                     VALUES (?, ?, ?)"
                );
                $updStmt = $db->prepare(
                    "UPDATE orders SET status='not_taken', taken_at=NULL, taken_by_admin_id=NULL
                     WHERE order_id=? AND status='taken'"
                );
                foreach ($expired as $row) {
                    // The update first and the log second — with the `status='taken'` condition
                    // in the statement itself. So if somebody got there first despite the lock
                    // (an order taken by another admin, or just completed), no row is inserted
                    // about an expiry that never happened. The log follows the facts, not the
                    // intention.
                    $updStmt->execute([$row['order_id']]);

                    if ($updStmt->rowCount() === 0) {
                        continue;
                    }

                    $logStmt->execute([$row['order_id'], $row['taken_by_admin_id'], $row['taken_at']]);
                    $reverted[] = [
                        'order_id'          => (int)$row['order_id'],
                        'previous_admin_id' => $row['taken_by_admin_id'] !== null ? (int)$row['taken_by_admin_id'] : null,
                    ];
                }
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("OrderModel::releaseExpiredTakenOrders Error: " . $e->getMessage());
            reportException($e);
            return [];
        }
        return $reverted;
    }

    /**
     * The order list for the admin/orders page, with filtering, search and pagination.
     * Display order: not_taken first, then taken, then cancelled, then completed, newest
     * first within each group.
     *
     * @param array{status?: string, search?: string} $filters
     * @return array<string, mixed> The rows together with the pagination data
     */
    public static function getAdminOrdersList(array $filters, int $page, int $perPage = 20): array
    {
        try {
            $db = self::db();

            $search = trim((string)($filters['search'] ?? ''));
            $status = $filters['status'] ?? '';

            $where  = [];
            $params = [];

            if ($status !== '' && in_array($status, ['not_taken', 'taken', 'cancelled', 'completed'], true)) {
                $where[]  = 'o.status = ?';
                $params[] = $status;
            }

            if ($search !== '') {
                if (is_numeric($search)) {
                    $where[]  = '(o.order_id = ? OR u.full_name LIKE ? OR u.email LIKE ?)';
                    $params[] = (int)$search;
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                } else {
                    $where[]  = '(u.full_name LIKE ? OR u.email LIKE ?)';
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                }
            }

            $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM orders o
                 JOIN users u ON u.id = o.user_id
                 {$whereClause}"
            );
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();

            $page      = max(1, $page);
            $offset    = ($page - 1) * $perPage;
            $totalPages = max(1, (int)ceil($total / $perPage));

            $stmt = $db->prepare(
                "SELECT o.*, u.full_name, u.email, ta.full_name AS handled_by_name
                 FROM orders o
                 JOIN users u ON u.id = o.user_id
                 LEFT JOIN admins ta ON ta.id = o.taken_by_admin_id
                 {$whereClause}
                 ORDER BY
                   CASE o.status
                     WHEN 'not_taken' THEN 1
                     WHEN 'taken'     THEN 2
                     WHEN 'cancelled' THEN 3
                     WHEN 'completed' THEN 4
                     ELSE 5
                   END ASC,
                   o.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute(array_merge($params, [(int)$perPage, (int)$offset]));

            return [
                'orders'     => $stmt->fetchAll(),
                'total'      => $total,
                'totalPages' => $totalPages,
            ];
        } catch (Exception $e) {
            error_log("OrderModel::getAdminOrdersList Error: " . $e->getMessage());
            return ['orders' => [], 'total' => 0, 'totalPages' => 1];
        }
    }

    /**
     * Every detail of one order, for the order details page (admin/orders/details).
     *
     * @return array<string, mixed>|null
     */
    public static function getAdminOrderDetails(int $orderId): ?array
    {
        try {
            $stmt = self::db()->prepare(
                "SELECT o.*,
                        u.full_name AS user_name, u.email AS user_email, u.phone_number AS user_phone,
                        ua.full_address, ua.country, ua.city,
                        ua.phone_number AS shipping_phone, ua.label AS address_label,
                        ta.full_name AS handler_admin_name
                 FROM orders o
                 JOIN users u ON u.id = o.user_id
                 LEFT JOIN user_addresses ua ON ua.id = o.address_id
                 LEFT JOIN admins ta ON ta.id = o.taken_by_admin_id
                 WHERE o.order_id = ? LIMIT 1"
            );
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            return $order ?: null;
        } catch (Exception $e) {
            error_log("OrderModel::getAdminOrderDetails Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * One order's items with their product and colour variant — the same JOIN
     * getUserOrders() uses, so the correct variant image and colour name are shown rather
     * than only the generic product image.
     * The colour name comes from color_name_snapshot (as at purchase time), falling back
     * to the current pv.color_name.
     *
     * @return list<array<string, mixed>>
     */
    public static function getOrderItemsWithProduct(int $orderId): array
    {
        try {
            $stmt = self::db()->prepare(
                "SELECT oi.*, p.name AS product_name,
                        COALESCE(pv.image_path, p.image_path) AS image_path,
                        COALESCE(oi.color_name_snapshot, pv.color_name) AS color_name
                 FROM order_items oi
                 JOIN products p ON p.id = oi.product_id
                 LEFT JOIN product_variants pv ON pv.id = oi.variant_id
                 WHERE oi.order_id = ?"
            );
            $stmt->execute([$orderId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("OrderModel::getOrderItemsWithProduct Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Take an order off the not_taken list (setting the status to taken for the current admin).
     *
     * @return array{success: bool, message: string, targetUserId: int|null}
     */
    public static function adminTakeOrder(int $orderId, int $adminId): array
    {
        $db = self::db();
        try {
            // ⚠️ A transaction and a `FOR UPDATE` — this method alone had no lock.
            //
            // The check and the write used to be two separate statements with no
            // transaction: two admins read the `not_taken` status at the same instant, the
            // condition passed for both, then both writes landed — and the last one won. So
            // the first believes they are holding the order while `taken_by_admin_id` carries
            // the second's name, and the two work on one order.
            //
            // Its counterpart `adminReleaseOrder` was correctly locked from the start — so
            // the right pattern existed in this same file, a hundred lines away.
            //
            // `FOR UPDATE` holds the row until the transaction ends, so the second waits,
            // then reads the new `taken` status and is politely refused.
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT status, user_id FROM orders WHERE order_id=? LIMIT 1 FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Order not found.', 'targetUserId' => null];
            }

            if ($order['status'] !== 'not_taken') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Cannot take this order — invalid status.', 'targetUserId' => null];
            }

            // The status condition is repeated in the write deliberately: the lock is
            // sufficient, but repeating it makes the statement correct in itself rather than
            // by its context — so if it were ever moved outside the transaction it would not
            // silently become an open door.
            $upd = $db->prepare(
                "UPDATE orders SET status='taken', taken_at=NOW(), taken_by_admin_id=?
                 WHERE order_id=? AND status='not_taken'"
            );
            $upd->execute([$adminId, $orderId]);

            if ($upd->rowCount() === 0) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Cannot take this order — invalid status.', 'targetUserId' => null];
            }

            $db->commit();

            return ['success' => true, 'message' => 'Order taken successfully.', 'targetUserId' => (int)$order['user_id']];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("OrderModel::adminTakeOrder Error: " . $e->getMessage());
            reportException($e);
            return ['success' => false, 'message' => 'Something went wrong.', 'targetUserId' => null];
        }
    }

    /**
     * Complete an order's delivery, recording the admin as the actor if the field is empty.
     *
     * @return array{success: bool, message: string, targetUserId: int|null}
     */
    public static function adminMarkDelivered(int $orderId, int $adminId): array
    {
        $db = self::db();
        try {
            // ⚠️ The status check was entirely absent, not merely incomplete.
            //
            // The method used to read `user_id` alone and then write `completed` onto any
            // order, whatever its status. So a **cancelled** order (whose stock had already
            // been returned to the warehouse) could be flipped to "completed" with a single
            // request to /admin/orders/mark-delivered, and would appear in the sales reports
            // with no goods having left.
            //
            // The interface only shows the button on a `taken` order — but guarding in the
            // interface is not guarding: the endpoint accepts a direct request.
            //
            // And `FOR UPDATE` prevents a stale check passing against a status that changed
            // between the read and the write.
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT status, user_id FROM orders WHERE order_id=? LIMIT 1 FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Order not found.', 'targetUserId' => null];
            }

            if ($order['status'] !== 'taken') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Only a taken order can be marked as delivered.', 'targetUserId' => null];
            }

            $db->prepare(
                "UPDATE orders SET status='completed', taken_by_admin_id=COALESCE(taken_by_admin_id, ?)
                 WHERE order_id=? AND status='taken'"
            )->execute([$adminId, $orderId]);

            $db->commit();

            return ['success' => true, 'message' => 'Order marked as delivered.', 'targetUserId' => (int)$order['user_id']];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("OrderModel::adminMarkDelivered Error: " . $e->getMessage());
            reportException($e);
            return ['success' => false, 'message' => 'Something went wrong.', 'targetUserId' => null];
        }
    }

    /**
     * Cancel an order's delivery, correctly restoring the variants' stock.
     * It respects the stock_restored column to prevent a double restore — the same logic
     * as cancelOrder().
     *
     * @return array{success: bool, message: string, targetUserId: int|null}
     */
    public static function adminCancelDelivery(int $orderId, int $adminId): array
    {
        $db = self::db();
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT user_id, stock_restored FROM orders WHERE order_id=? LIMIT 1");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Order not found.', 'targetUserId' => null];
            }

            if (!$order['stock_restored']) {
                $items = $db->prepare(
                    "SELECT variant_id, quantity FROM order_items WHERE order_id=? AND variant_id IS NOT NULL"
                );
                $items->execute([$orderId]);
                $restore = $db->prepare(
                    "UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id=?"
                );
                foreach ($items->fetchAll() as $item) {
                    $restore->execute([$item['quantity'], $item['variant_id']]);
                }
            }

            $db->prepare(
                "UPDATE orders SET status='cancelled', stock_restored=1,
                 taken_by_admin_id=COALESCE(taken_by_admin_id, ?) WHERE order_id=?"
            )->execute([$adminId, $orderId]);

            $db->commit();
            return ['success' => true, 'message' => 'Delivery cancelled.', 'targetUserId' => (int)$order['user_id']];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("OrderModel::adminCancelDelivery Error: " . $e->getMessage());
            reportException($e);
            return ['success' => false, 'message' => 'Something went wrong while cancelling.', 'targetUserId' => null];
        }
    }

    /**
     * A hard delete of a completed or cancelled order (status='completed' or 'cancelled')
     * from the admin panel. It refuses any order in another state — which preserves the
     * audit trail for orders cancelled through cancelAllPendingForUser() or
     * adminCancelDelivery() (soft cancels).
     *
     * @return array{success: bool, message: string}
     */
    public static function adminDeleteOrder(int $orderId): array
    {
        $db = self::db();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT status FROM orders WHERE order_id=? LIMIT 1");
            $stmt->execute([$orderId]);
            $status = $stmt->fetchColumn();

            if ($status === false) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Order not found.'];
            }
            if (!in_array($status, ['completed', 'cancelled'], true)) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Only completed or cancelled orders can be deleted permanently.'];
            }

            // A hard delete — the same explicit manual ordering cancelOrder() uses
            $db->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$orderId]);
            $db->prepare("DELETE FROM order_expiry_log WHERE order_id=?")->execute([$orderId]);
            $db->prepare("DELETE FROM orders WHERE order_id=?")->execute([$orderId]);

            $db->commit();
            return ['success' => true, 'message' => "Order #{$orderId} has been permanently deleted."];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("OrderModel::adminDeleteOrder Error: " . $e->getMessage());
            reportException($e);
            return ['success' => false, 'message' => 'Something went wrong.'];
        }
    }

    /**
     * Voluntary release: an admin who currently holds a 'taken' order gives it back.
     * Order returns to 'not_taken', taken_at and taken_by_admin_id are cleared.
     * Only succeeds if $adminId is the CURRENT holder of the order (enforced here,
     * not just at the controller/UI level, to prevent any admin from releasing
     * an order someone else is holding by crafting the request directly).
     *
     * @return array{success: bool, message: string, targetUserId: int|null}
     */
    public static function adminReleaseOrder(int $orderId, int $adminId): array
    {
        $db = self::db();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT status, taken_by_admin_id, user_id FROM orders WHERE order_id=? LIMIT 1 FOR UPDATE");
            $stmt->execute([$orderId]);
            $row = $stmt->fetch();

            if (!$row) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Order not found.', 'targetUserId' => null];
            }
            if ($row['status'] !== 'taken') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Only a taken order can be released.', 'targetUserId' => null];
            }
            if ((int)$row['taken_by_admin_id'] !== $adminId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You can only release an order you currently hold.', 'targetUserId' => null];
            }

            $db->prepare(
                "UPDATE orders SET status='not_taken', taken_at=NULL, taken_by_admin_id=NULL WHERE order_id=?"
            )->execute([$orderId]);

            $db->commit();
            return ['success' => true, 'message' => "Order #{$orderId} has been released back to Not Taken.", 'targetUserId' => (int)$row['user_id']];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("OrderModel::adminReleaseOrder Error: " . $e->getMessage());
            reportException($e);
            return ['success' => false, 'message' => 'Something went wrong.', 'targetUserId' => null];
        }
    }

    /**
     * A helper: an order's user_id, or null — used by the report_issue operation
     * (which does not change the order's status, only notifies the user and writes an
     * audit entry).
     */
    public static function getOrderUserId(int $orderId): ?int
    {
        try {
            $stmt = self::db()->prepare("SELECT user_id FROM orders WHERE order_id=? LIMIT 1");
            $stmt->execute([$orderId]);
            $userId = $stmt->fetchColumn();
            return $userId !== false ? (int)$userId : null;
        } catch (Exception $e) {
            error_log("OrderModel::getOrderUserId Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Every order matching the filters (without pagination) for the CSV export — the same
     * logic as getAdminOrdersList().
     *
     * @param array{status?: string, search?: string} $filters
     * @return list<array<string, mixed>>
     */
    public static function getAllForCsvExport(array $filters): array
    {
        try {
            $db = self::db();

            $search = trim((string)($filters['search'] ?? ''));
            $status = $filters['status'] ?? '';

            $where  = [];
            $params = [];

            if ($status !== '' && in_array($status, ['not_taken', 'taken', 'cancelled', 'completed'], true)) {
                $where[]  = 'o.status = ?';
                $params[] = $status;
            }

            if ($search !== '') {
                if (is_numeric($search)) {
                    $where[]  = '(o.order_id = ? OR u.full_name LIKE ? OR u.email LIKE ?)';
                    $params[] = (int)$search;
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                } else {
                    $where[]  = '(u.full_name LIKE ? OR u.email LIKE ?)';
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                }
            }

            $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $stmt = $db->prepare(
                "SELECT o.order_id, u.full_name, u.email, o.total_amount, o.payment_method,
                        o.status, ta.full_name AS handled_by_name, o.created_at
                 FROM orders o
                 JOIN users u ON u.id = o.user_id
                 LEFT JOIN admins ta ON ta.id = o.taken_by_admin_id
                 {$whereClause}
                 ORDER BY
                   CASE o.status
                     WHEN 'not_taken' THEN 1
                     WHEN 'taken'     THEN 2
                     WHEN 'cancelled' THEN 3
                     WHEN 'completed' THEN 4
                     ELSE 5
                   END ASC,
                   o.created_at DESC"
            );
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("OrderModel::getAllForCsvExport Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * The orders a given admin handled — newest first. It covers:
     *   (a) orders whose current taken_by_admin_id = $adminId (as before);
     *   (b) earlier orders that expired and were released automatically while this admin
     *       was holding them (through order_expiry_log.previous_admin_id) — flagged with
     *       was_auto_released=1, because their current status in the orders table may have
     *       changed since (another admin taking them later, say), so showing them the
     *       ordinary status badge would be wrong.
     * Used on the admin details page (manage-admins/details).
     *
     * @return list<array<string, mixed>>
     */
    public static function getOrdersHandledByAdmin(int $adminId, int $limit = 50): array
    {
        try {
            $stmt = self::db()->prepare(
                "SELECT order_id, status, total_amount, created_at, 0 AS was_auto_released
                 FROM orders
                 WHERE taken_by_admin_id = ?

                 UNION

                 SELECT o.order_id, o.status, o.total_amount, o.created_at, 1 AS was_auto_released
                 FROM order_expiry_log el
                 JOIN orders o ON o.order_id = el.order_id
                 WHERE el.previous_admin_id = ?
                   AND (o.taken_by_admin_id IS NULL OR o.taken_by_admin_id != ?)

                 ORDER BY created_at DESC
                 LIMIT ?"
            );
            $stmt->execute([$adminId, $adminId, $adminId, (int)$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("OrderModel::getOrdersHandledByAdmin Error: " . $e->getMessage());
            return [];
        }
    }
}
