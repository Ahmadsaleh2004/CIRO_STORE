<?php

namespace Tests\Integration;

use App\Models\OrderModel;
use Tests\Support\DatabaseTestCase;

/**
 * "My Info" lists a customer's orders with their items — each item under its own order.
 *
 * OrderModel::getUserOrders used to read the items one order at a time: fifty orders, fifty-
 * one queries. It now reads them all in one and groups them, so what matters is that the
 * grouping is right: every item under the order it belongs to, in the order it was added, an
 * order without items still listed with none, and nothing of another customer's.
 */
final class UserOrdersItemsTest extends DatabaseTestCase
{
    private function user(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (full_name, email) VALUES (?, ?)');
        $stmt->execute(['Orders User', 'orders' . uniqid() . '@example.com']);

        return (int) $this->pdo->lastInsertId();
    }

    private function product(string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, discount_percentage, stock_quantity, is_visible) VALUES (?, 10, 0, 5, 1)'
        );
        $stmt->execute([$name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function order(int $userId, string $createdAt): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO orders (user_id, total_amount, created_at) VALUES (?, 10, ?)');
        $stmt->execute([$userId, $createdAt]);

        return (int) $this->pdo->lastInsertId();
    }

    private function item(int $orderId, int $productId, int $qty): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) VALUES (?, ?, ?, 10)'
        );
        $stmt->execute([$orderId, $productId, $qty]);
    }

    public function testEachOrderCarriesItsOwnItemsInTheOrderTheyWereAdded(): void
    {
        $me    = $this->user();
        $other = $this->user();
        $phone = $this->product('Phone');
        $case  = $this->product('Case');

        $older = $this->order($me, '2026-01-01 10:00:00');
        $newer = $this->order($me, '2026-02-01 10:00:00');
        $empty = $this->order($me, '2025-12-01 10:00:00');
        $theirs = $this->order($other, '2026-03-01 10:00:00');

        $this->item($older, $phone, 1);
        $this->item($newer, $case, 2);
        $this->item($older, $case, 3);
        $this->item($theirs, $phone, 9);

        $orders = OrderModel::getUserOrders($me);

        // Newest first, as before; the other customer's order is not among them
        $this->assertSame([$newer, $older, $empty], array_map(static fn (array $o): int => (int) $o['order_id'], $orders));

        $names = static fn (array $o): array => array_map(
            static fn (array $i): string => $i['product_name'] . '×' . $i['quantity'],
            $o['items']
        );
        $this->assertSame(['Case×2'], $names($orders[0]));
        $this->assertSame(['Phone×1', 'Case×3'], $names($orders[1]));
        $this->assertSame([], $orders[2]['items'], 'An order without items is still listed, with none');
    }

    public function testACustomerWithoutOrdersGetsAnEmptyList(): void
    {
        $this->assertSame([], OrderModel::getUserOrders($this->user()));
    }
}
