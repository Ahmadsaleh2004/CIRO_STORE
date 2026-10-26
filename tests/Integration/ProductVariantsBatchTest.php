<?php

namespace Tests\Integration;

use App\Models\ProductModel;
use Tests\Support\DatabaseTestCase;

/**
 * The products page reads every card's variants in one query — and they are the same rows.
 *
 * /products used to call ProductModel::getVariants once per card. getVariantsForProducts
 * replaces those nine round trips with one, and pickDisplayVariant chooses each card's
 * price, stock and image from what it returns. So the guard is equality with the per-product
 * call: the same rows, in the same order, for every product — and nothing for a product that
 * has no variants, which the page then shows from its base row as before.
 */
final class ProductVariantsBatchTest extends DatabaseTestCase
{
    /** @param list<string> $colors */
    private function makeProduct(string $name, array $colors): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, discount_percentage, stock_quantity, is_visible) VALUES (?, 100, 0, 5, 1)'
        );
        $stmt->execute([$name]);
        $productId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, discount_percentage, stock_quantity)
             VALUES (?, ?, ?, 0, ?)'
        );
        foreach ($colors as $i => $color) {
            $stmt->execute([$productId, $color, 100 + $i, 3 + $i]);
        }

        return $productId;
    }

    public function testEachProductGetsExactlyTheRowsGetVariantsReturns(): void
    {
        $phone  = $this->makeProduct('Phone', ['Black', 'White', 'Blue']);
        $laptop = $this->makeProduct('Laptop', ['Silver']);
        $bare   = $this->makeProduct('No Variants', []);

        // Interleaved inserts, so grouping cannot pass by accident of insertion order
        $this->makeProduct('Unrelated', ['Red']);

        $batch = ProductModel::getVariantsForProducts([$phone, $laptop, $bare]);

        $this->assertSame(ProductModel::getVariants($phone), $batch[$phone]);
        $this->assertSame(ProductModel::getVariants($laptop), $batch[$laptop]);
        $this->assertArrayNotHasKey($bare, $batch);
        $this->assertCount(2, $batch, 'Only the requested products, and only those with variants');
    }

    public function testNoIdsMeansNoQuery(): void
    {
        $this->assertSame([], ProductModel::getVariantsForProducts([]));
        $this->assertSame([], ProductModel::getVariantsForProducts([0, -3]));
    }
}
