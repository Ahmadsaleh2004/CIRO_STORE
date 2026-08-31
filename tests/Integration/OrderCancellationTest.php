<?php

namespace Tests\Integration;

use App\Models\OrderModel;
use Tests\Support\DatabaseTestCase;

/**
 * Cancelling an order and returning its stock.
 *
 * ══════════════════════════════════════════════════════════════
 * Why this file exists
 * ══════════════════════════════════════════════════════════════
 *
 * Cancellation is **the other side of the same counter** the order decrements. And any error
 * in it shows up as a direct loss in one of two directions:
 *
 *   · the stock was not returned → goods sitting in the warehouse that are never sold.
 *   · it was returned twice      → selling what does not exist, and then apologising to a
 *                                  customer.
 *
 * And the second direction is what the `stock_restored` column guards: a flag saying this
 * order has already returned its goods. And that is exactly the kind of protection whose
 * breaking shows up only after the error has accumulated in the database for weeks.
 *
 * And nothing covered that: zero tests on OrderModel before this phase.
 */
final class OrderCancellationTest extends DatabaseTestCase
{
    private function makeUser(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (full_name, email) VALUES (?, ?)');
        $stmt->execute(['Test Buyer', 'buyer' . uniqid() . '@example.com']);
        return (int) $this->pdo->lastInsertId();
    }

    private function makeAddress(int $userId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_addresses (user_id, full_address) VALUES (?, ?)');
        $stmt->execute([$userId, '1 Test Street']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} [productId, variantId] */
    private function makeProductWithVariant(float $price = 100.00, int $stock = 10): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, stock_quantity, is_visible) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute(['Test Product', $price, $stock]);
        $productId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, stock_quantity)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$productId, 'Black', $price, $stock]);

        return [$productId, (int) $this->pdo->lastInsertId()];
    }

    /** Places a sound order and returns its id. */
    private function placeOrder(int $userId, int $addressId, int $productId, int $variantId, int $qty): int
    {
        $result = OrderModel::placeOrder($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => $qty,
            'shown_price' => 100.00,
        ]], 'cash_on_delivery', bin2hex(random_bytes(8)));

        $this->assertSame(OrderModel::PLACE_OK, $result['status'], 'The test order could not be prepared.');

        return $result['order_id'];
    }

    private function variantStock(int $variantId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ?');
        $stmt->execute([$variantId]);
        return (int) $stmt->fetchColumn();
    }

    private function setStatus(int $orderId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE orders SET status = ? WHERE order_id = ?');
        $stmt->execute([$status, $orderId]);
    }

    // ════════════════════════════════════════════════════════
    // Cancellation by the user
    // ════════════════════════════════════════════════════════

    public function testCancellingReturnsTheStockAndRemovesTheOrder(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 3);
        $this->assertSame(7, $this->variantStock($variantId));

        $this->assertTrue(OrderModel::cancelOrder($orderId, $userId));

        $this->assertSame(10, $this->variantStock($variantId));
        $this->assertSame(0, $this->countRows('orders'));

        // A hard delete, deliberately, for this state alone (not_taken), with its items
        // following it.
        $this->assertSame(0, $this->countRows('order_items'));
    }

    public function testAUserCannotCancelSomeoneElsesOrder(): void
    {
        $ownerId   = $this->makeUser();
        $addressId = $this->makeAddress($ownerId);
        $strangerId = $this->makeUser();
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($ownerId, $addressId, $productId, $variantId, 2);

        // Ownership is a condition in the SELECT itself (WHERE order_id=? AND user_id=?),
        // not a separate check that could be forgotten on another path.
        $this->assertFalse(OrderModel::cancelOrder($orderId, $strangerId));

        $this->assertSame(1, $this->countRows('orders'));
        $this->assertSame(8, $this->variantStock($variantId), 'Stock moved despite the cancellation being refused.');
    }

    public function testAnOrderAlreadyTakenByAnAdminCannotBeCancelledByTheUser(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 2);
        $this->setStatus($orderId, 'taken');

        // The rule: the customer cancels before an admin takes the order, not after — after
        // that the goods are already on their way to them.
        $this->assertFalse(OrderModel::cancelOrder($orderId, $userId));

        $this->assertSame(1, $this->countRows('orders'));
        $this->assertSame(8, $this->variantStock($variantId));
    }

    public function testCancellingTwiceDoesNotReturnTheStockTwice(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 4);

        $this->assertTrue(OrderModel::cancelOrder($orderId, $userId));
        $this->assertSame(10, $this->variantStock($variantId));

        // The double click, or two racing requests from two tabs. The order no longer
        // exists, so the second must fail with no effect — not add four phantom units to the
        // stock.
        $this->assertFalse(OrderModel::cancelOrder($orderId, $userId));
        $this->assertSame(10, $this->variantStock($variantId));
    }

    // ════════════════════════════════════════════════════════
    // The block cascade
    // ════════════════════════════════════════════════════════

    public function testBlockingAUserCancelsPendingOrdersAndReturnsStockOnce(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 20);

        $first  = $this->placeOrder($userId, $addressId, $productId, $variantId, 3);
        $second = $this->placeOrder($userId, $addressId, $productId, $variantId, 2);
        $this->assertSame(15, $this->variantStock($variantId));

        OrderModel::cancelAllPendingForUser($userId);

        $this->assertSame(20, $this->variantStock($variantId));

        // A soft cancellation here rather than a delete: the audit log must keep a record of
        // what was cancelled by the block — for whom, what, and when.
        $stmt = $this->pdo->prepare('SELECT status, stock_restored FROM orders WHERE order_id IN (?, ?)');
        $stmt->execute([$first, $second]);

        foreach ($stmt->fetchAll() as $row) {
            $this->assertSame('cancelled', $row['status']);
            $this->assertSame(1, (int) $row['stock_restored']);
        }

        // And a rerun — a second block, or the repair script — must not return anything a
        // second time. That is the entire purpose of the stock_restored column.
        OrderModel::cancelAllPendingForUser($userId);
        $this->assertSame(20, $this->variantStock($variantId));
    }

    // ════════════════════════════════════════════════════════
    // The order's state transitions from the control panel
    // ════════════════════════════════════════════════════════

    private function makeAdmin(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admins (full_name, email, password, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            'Test Admin',
            'admin' . uniqid() . '@example.com',
            password_hash('secret123', PASSWORD_BCRYPT),
            'A',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function orderStatus(int $orderId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM orders WHERE order_id = ?');
        $stmt->execute([$orderId]);

        return (string) $stmt->fetchColumn();
    }

    public function testOnlyOneAdminCanTakeAnOrder(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 1);

        $first  = $this->makeAdmin();
        $second = $this->makeAdmin();

        $this->assertTrue(OrderModel::adminTakeOrder($orderId, $first)['success']);

        // The second is refused: the state is no longer not_taken. And before the
        // transaction and `FOR UPDATE` were added, the check and the write were two separate
        // statements, so both passed together and the last write won — leaving the first
        // believing they hold the order while the second holds it.
        $result = OrderModel::adminTakeOrder($orderId, $second);
        $this->assertFalse($result['success']);

        $stmt = $this->pdo->prepare('SELECT taken_by_admin_id FROM orders WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $this->assertSame($first, (int) $stmt->fetchColumn());
    }

    public function testACancelledOrderCannotBeMarkedDelivered(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 2);
        $adminId = $this->makeAdmin();

        $this->setStatus($orderId, 'cancelled');

        // ⚠️ adminMarkDelivered did not check the state at all: it read user_id alone and
        // then wrote 'completed' over any order. So a cancelled order — whose stock had
        // already gone back to the warehouse — was flipped to "completed" by one request to
        // /admin/orders/mark-delivered, entering the sales reports with no goods having left.
        //
        // And the interface only shows the button on a taken order, but guarding in the
        // interface is not guarding — the endpoint accepts a direct request.
        $result = OrderModel::adminMarkDelivered($orderId, $adminId);

        $this->assertFalse($result['success']);
        $this->assertSame('cancelled', $this->orderStatus($orderId));
    }

    public function testAnUntakenOrderCannotBeMarkedDelivered(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 1);
        $adminId = $this->makeAdmin();

        // Delivery presupposes that somebody took the order and prepared it. Skipping that
        // produces a "completed" order carrying nobody's name as its handler.
        $this->assertFalse(OrderModel::adminMarkDelivered($orderId, $adminId)['success']);
        $this->assertSame('not_taken', $this->orderStatus($orderId));
    }

    public function testATakenOrderIsMarkedDeliveredNormally(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 1);
        $adminId = $this->makeAdmin();

        // The sound path stays sound: the guard blocks only what it should block.
        $this->assertTrue(OrderModel::adminTakeOrder($orderId, $adminId)['success']);
        $this->assertTrue(OrderModel::adminMarkDelivered($orderId, $adminId)['success']);
        $this->assertSame('completed', $this->orderStatus($orderId));
    }
}
