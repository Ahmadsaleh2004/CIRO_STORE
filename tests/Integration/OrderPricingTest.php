<?php

namespace Tests\Integration;

use App\Models\OrderModel;
use Tests\Support\DatabaseTestCase;

/**
 * OrderModel::placeOrder — the pricing comes from the database, not from the client.
 *
 * ══════════════════════════════════════════════════════════════
 * The original fault
 * ══════════════════════════════════════════════════════════════
 *
 * The cart was built in localStorage and the browser sent it as it was, and the function
 * read `price` from it, summed that into `total_amount` and wrote it into
 * `price_at_purchase`. Which is to say **the price was user input**: an order carrying
 * `price: 0.01` passed with a valid token and a valid session, decremented the stock, and
 * reached the admin as a legitimate order.
 *
 * And nothing caught it because this path — the most valuable path in the store — was
 * covered by no test at all. This file is that cover.
 *
 * ══════════════════════════════════════════════════════════════
 * Why refusal rather than proceeding at the server's price
 * ══════════════════════════════════════════════════════════════
 *
 * An explicit product decision: the customer is not surprised by an amount they never agreed
 * to. And cash on delivery moves the surprise to the doorstep — where its cost is a refused
 * delivery and a return shipment. So a differing price cancels the operation and returns the
 * correct prices.
 */
final class OrderPricingTest extends DatabaseTestCase
{
    private function makeUser(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email) VALUES (?, ?)'
        );
        $stmt->execute(['Test Buyer', 'buyer' . uniqid() . '@example.com']);
        return (int) $this->pdo->lastInsertId();
    }

    private function makeAddress(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_addresses (user_id, full_address) VALUES (?, ?)'
        );
        $stmt->execute([$userId, '1 Test Street']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} [productId, variantId] */
    private function makeProductWithVariant(
        float $price = 100.00,
        float $discount = 0.00,
        int $stock = 5,
        int $visible = 1
    ): array {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, discount_percentage, stock_quantity, is_visible)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Test Product', $price, $discount, $stock, $visible]);
        $productId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, discount_percentage, stock_quantity)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$productId, 'Black', $price, $discount, $stock]);

        return [$productId, (int) $this->pdo->lastInsertId()];
    }

    /**
     * @param  list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function place(int $userId, int $addressId, array $items, string $key = ''): array
    {
        return OrderModel::placeOrder(
            $userId,
            $addressId,
            $items,
            'cash_on_delivery',
            $key !== '' ? $key : bin2hex(random_bytes(8))
        );
    }

    private function variantStock(int $variantId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ?');
        $stmt->execute([$variantId]);
        return (int) $stmt->fetchColumn();
    }

    // ════════════════════════════════════════════════════════
    // The sound path
    // ════════════════════════════════════════════════════════

    public function testAnHonestOrderIsPricedFromTheDatabase(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10.00, 5);

        // price_after_discount is a computed column: 100 − 10% = 90.00
        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 2,
            'shown_price' => 90.00,
        ]]);

        $this->assertSame(OrderModel::PLACE_OK, $result['status']);

        $stmt = $this->pdo->prepare('SELECT total_amount FROM orders WHERE order_id = ?');
        $stmt->execute([$result['order_id']]);
        $this->assertSame('180.00', $stmt->fetchColumn());

        $stmt = $this->pdo->prepare(
            'SELECT price_at_purchase, color_name_snapshot FROM order_items WHERE order_id = ?'
        );
        $stmt->execute([$result['order_id']]);
        $row = $stmt->fetch();

        $this->assertSame('90.00', $row['price_at_purchase']);

        // The snapshot comes from the database rather than from the client: a snapshot
        // written by the party it is recorded against is not a snapshot.
        $this->assertSame('Black', $row['color_name_snapshot']);

        $this->assertSame(3, $this->variantStock($variantId));
    }

    // ════════════════════════════════════════════════════════
    // The hole itself
    // ════════════════════════════════════════════════════════

    public function testAForgedPriceIsRejectedAndNothingIsWritten(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 0.00, 5);

        // "Buy a hundred-dollar product for a cent" — the order that used to go through.
        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 1,
            'shown_price' => 0.01,
        ]]);

        $this->assertSame(OrderModel::PLACE_PRICE_CHANGED, $result['status']);
        $this->assertSame(0, $this->countRows('orders'));
        $this->assertSame(0, $this->countRows('order_items'));

        // And no stock row moved — the rollback is complete rather than partial.
        $this->assertSame(5, $this->variantStock($variantId));

        // And the reply carries the correct price so the client can correct its cart.
        $this->assertSame(100.00, $result['items'][0]['price']);
        $this->assertSame(0.01, $result['items'][0]['shown_price']);
    }

    public function testEveryDriftedPriceIsReportedNotJustTheFirst(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$p1, $v1] = $this->makeProductWithVariant(100.00, 0.00, 5);
        [$p2, $v2] = $this->makeProductWithVariant(50.00, 0.00, 5);

        $result = $this->place($userId, $addressId, [
            ['product_id' => $p1, 'variant_id' => $v1, 'qty' => 1, 'shown_price' => 10.00],
            ['product_id' => $p2, 'variant_id' => $v2, 'qty' => 1, 'shown_price' => 5.00],
        ]);

        $this->assertSame(OrderModel::PLACE_PRICE_CHANGED, $result['status']);

        // A customer with two changed prices deserves to see both at once, rather than
        // retrying twice to discover them one by one.
        $this->assertCount(2, $result['items']);
    }

    public function testCentPrecisionDoesNotRejectAnHonestOrder(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);

        // 19.99 − 15% = 16.9915 → the computed column stores it as 16.99
        [$productId, $variantId] = $this->makeProductWithVariant(19.99, 15.00, 5);

        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 3,
            'shown_price' => 16.99,
        ]]);

        // The comparison is in cents rather than decimals: 0.1 + 0.2 !== 0.3 in any binary
        // arithmetic, and a float comparison would have refused this sound order.
        $this->assertSame(OrderModel::PLACE_OK, $result['status']);
    }

    // ════════════════════════════════════════════════════════
    // What is no longer purchasable
    // ════════════════════════════════════════════════════════

    public function testAHiddenProductCannotBeOrdered(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 0.00, 5, visible: 0);

        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 1,
            'shown_price' => 100.00,
        ]]);

        // Nothing on the ordering path checked is_visible. The locking query carries it, so
        // that door was closed along with the price's.
        $this->assertSame(OrderModel::PLACE_UNAVAILABLE, $result['status']);
        $this->assertSame(0, $this->countRows('orders'));
    }

    public function testAVariantBelongingToAnotherProductIsRejected(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$cheapProduct, ]           = $this->makeProductWithVariant(5.00, 0.00, 5);
        [, $expensiveVariant]       = $this->makeProductWithVariant(500.00, 0.00, 5);

        // Pairing an expensive variant with a cheap product: without the ownership check, an
        // order_items row is stored against a product whose price was never the one charged.
        $result = $this->place($userId, $addressId, [[
            'product_id'  => $cheapProduct,
            'variant_id'  => $expensiveVariant,
            'qty'         => 1,
            'shown_price' => 500.00,
        ]]);

        $this->assertSame(OrderModel::PLACE_UNAVAILABLE, $result['status']);
        $this->assertSame(0, $this->countRows('orders'));
    }

    // ════════════════════════════════════════════════════════
    // The address snapshot
    // ════════════════════════════════════════════════════════

    public function testTheOrderKeepsItsOwnCopyOfTheAddress(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 0.00, 5);

        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 1,
            'shown_price' => 100.00,
        ]]);
        $this->assertSame(OrderModel::PLACE_OK, $result['status']);

        // The user edits their address after the order — an entirely ordinary thing to do.
        $stmt = $this->pdo->prepare('UPDATE user_addresses SET full_address = ? WHERE id = ?');
        $stmt->execute(['A completely new address', $addressId]);

        $stmt = $this->pdo->prepare('SELECT address_snapshot FROM orders WHERE order_id = ?');
        $stmt->execute([$result['order_id']]);
        $snapshot = (string) $stmt->fetchColumn();

        // Without the snapshot, the record said the shipment went somewhere it never went —
        // retroactively, and with no trace of the change.
        $this->assertStringContainsString('1 Test Street', $snapshot);
        $this->assertStringNotContainsString('A completely new address', $snapshot);
    }

    public function testDeletingTheAddressDoesNotEraseItFromPastOrders(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 0.00, 5);

        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 1,
            'shown_price' => 100.00,
        ]]);

        $stmt = $this->pdo->prepare('DELETE FROM user_addresses WHERE id = ?');
        $stmt->execute([$addressId]);

        $stmt = $this->pdo->prepare(
            'SELECT address_id, address_snapshot FROM orders WHERE order_id = ?'
        );
        $stmt->execute([$result['order_id']]);
        $order = $stmt->fetch();

        // The key becomes NULL under ON DELETE SET NULL — and that is the whole of the old
        // behaviour: a completed order loses its address permanently with no copy anywhere.
        // The snapshot is what survives.
        $this->assertNull($order['address_id']);
        $this->assertStringContainsString('1 Test Street', (string) $order['address_snapshot']);
    }

    public function testAnItemWithoutAVariantIsRejected(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, ] = $this->makeProductWithVariant(100.00, 0.00, 5);

        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => null,
            'qty'         => 1,
            'shown_price' => 100.00,
        ]]);

        // The fault this test closes: the stock decrement sat inside `if ($variantId)`, so
        // an item without a variant was recorded in order_items and sold **without touching
        // any stock counter** — unlimited sales of a product that had run out.
        //
        // And refusal rather than decrementing from products: the store requires a variant
        // for every product in two places, so a second source of truth for the stock costs
        // more than refusing a case that never arrives from its own interface.
        $this->assertSame(OrderModel::PLACE_UNAVAILABLE, $result['status']);
        $this->assertSame(0, $this->countRows('orders'));
        $this->assertSame(0, $this->countRows('order_items'));
    }

    // ════════════════════════════════════════════════════════
    // Stock and duplication
    // ════════════════════════════════════════════════════════

    public function testInsufficientStockRollsBackTheWholeOrder(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$p1, $v1] = $this->makeProductWithVariant(100.00, 0.00, 10);
        [$p2, $v2] = $this->makeProductWithVariant(100.00, 0.00, 1);

        $result = $this->place($userId, $addressId, [
            ['product_id' => $p1, 'variant_id' => $v1, 'qty' => 2, 'shown_price' => 100.00],
            ['product_id' => $p2, 'variant_id' => $v2, 'qty' => 5, 'shown_price' => 100.00],
        ]);

        $this->assertSame(OrderModel::PLACE_OUT_OF_STOCK, $result['status']);
        $this->assertSame(0, $this->countRows('orders'));

        // The first item's stock succeeded before the second failed — and the rollback must
        // restore it. That is what makes the transaction a transaction rather than a chain of
        // updates.
        $this->assertSame(10, $this->variantStock($v1));
        $this->assertSame(1, $this->variantStock($v2));
    }

    public function testTheSameIdempotencyKeyReturnsTheSameOrder(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 0.00, 10);

        $item = [
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 1,
            'shown_price' => 100.00,
        ];

        $first  = $this->place($userId, $addressId, [$item], 'fixed-key-123');
        $second = $this->place($userId, $addressId, [$item], 'fixed-key-123');

        $this->assertSame(OrderModel::PLACE_OK, $first['status']);
        $this->assertSame(OrderModel::PLACE_OK, $second['status']);
        $this->assertSame($first['order_id'], $second['order_id']);
        $this->assertTrue($second['duplicate']);

        $this->assertSame(1, $this->countRows('orders'));

        // And more importantly: the repeated click does not deduct the stock twice.
        $this->assertSame(9, $this->variantStock($variantId));
    }
}
