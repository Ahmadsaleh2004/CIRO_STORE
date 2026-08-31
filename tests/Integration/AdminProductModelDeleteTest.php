<?php

namespace Tests\Integration;

use App\Models\AdminProductModel;
use Tests\Support\DatabaseTestCase;

/**
 * AdminProductModel::delete — the explicit contract that replaced the unconditional
 * `return true`.
 *
 * The original fault: a DELETE on a non-existent id **succeeds** in SQL and deletes zero
 * rows. So the function returned true for a product that never existed, and the controller
 * wrote an audit row and a notification about a deletion that never happened — an audit log
 * that lies.
 *
 * The contract is now three-way:
 *   true  → really deleted
 *   false → not found (and zero side effects)
 *   null  → a technical error
 */
final class AdminProductModelDeleteTest extends DatabaseTestCase
{
    private function insertProduct(string $name = 'Test Product'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, stock_quantity) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, 99.99, 10]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertVariant(int $productId, string $color = 'Black'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, stock_quantity)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$productId, $color, 99.99, 5]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testDeletingAnExistingProductReturnsTrueAndRemovesTheRow(): void
    {
        $id = $this->insertProduct();
        $this->assertSame(1, $this->countRows('products'));

        $this->assertTrue(AdminProductModel::delete($id));
        $this->assertSame(0, $this->countRows('products'));
    }

    /**
     * The case the contract exists for.
     */
    public function testDeletingAMissingProductReturnsFalseNotTrue(): void
    {
        $this->assertSame(0, $this->countRows('products'));

        $result = AdminProductModel::delete(999999);

        $this->assertFalse(
            $result,
            'Deleting a non-existent id returned ' . var_export($result, true)
            . ' — the "lying audit log" fault has returned.'
        );
    }

    /**
     * More important than the return value: **zero side effects**.
     *
     * The function deletes the variants and the categories before discovering that the
     * product does not exist. Without a rollBack it would erase other products' data if the
     * id happened to collide — and that is what this test guards.
     */
    public function testAFailedDeleteLeavesOtherProductsAndVariantsUntouched(): void
    {
        $keep = $this->insertProduct('Survivor');
        $this->insertVariant($keep, 'Red');
        $this->insertVariant($keep, 'Blue');

        $this->assertFalse(AdminProductModel::delete(999999));

        $this->assertSame(1, $this->countRows('products'), 'A product nobody asked to delete has disappeared.');
        $this->assertSame(2, $this->countRows('product_variants'), 'Another product\'s variants have disappeared.');
    }

    public function testDeletingAProductAlsoRemovesItsVariants(): void
    {
        $id = $this->insertProduct();
        $this->insertVariant($id, 'Red');
        $this->insertVariant($id, 'Blue');
        $this->assertSame(2, $this->countRows('product_variants'));

        $this->assertTrue(AdminProductModel::delete($id));

        $this->assertSame(0, $this->countRows('products'));
        $this->assertSame(0, $this->countRows('product_variants'), 'Orphaned variants were left behind.');
    }

    public function testDeletingTheSameProductTwiceReturnsTrueThenFalse(): void
    {
        $id = $this->insertProduct();

        $this->assertTrue(AdminProductModel::delete($id), 'The first deletion failed.');
        $this->assertFalse(
            AdminProductModel::delete($id),
            'The second deletion returned true — that is, two clicks on the button write two audit rows for one deletion.'
        );
    }

    public function testDeletingOneProductDoesNotTouchAnother(): void
    {
        $doomed   = $this->insertProduct('Doomed');
        $survivor = $this->insertProduct('Survivor');
        $this->insertVariant($doomed, 'Red');
        $this->insertVariant($survivor, 'Green');

        $this->assertTrue(AdminProductModel::delete($doomed));

        $this->assertSame(1, $this->countRows('products'));
        $this->assertSame(1, $this->countRows('product_variants'));

        $remaining = $this->pdo->query('SELECT name FROM products')->fetchColumn();
        $this->assertSame('Survivor', $remaining);
    }
}
