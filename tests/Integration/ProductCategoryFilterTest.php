<?php

namespace Tests\Integration;

use App\Models\ProductModel;
use Tests\Support\DatabaseTestCase;

/**
 * /products?cat=… is filtered by the server, across every page.
 *
 * It used to be filtered in the browser over the nine cards of the page on screen. With five
 * phones in the catalogue and one of them on page 2, "Phone" from the home page listed four.
 * The filter now runs in the query, before the LIMIT, so the count and every page are of the
 * category itself — while a card still lists all of its categories, since the filter must
 * not narrow the GROUP_CONCAT that builds them.
 */
final class ProductCategoryFilterTest extends DatabaseTestCase
{
    private function category(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO categories (name) VALUES (?)');
        $stmt->execute([$name]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<int> $categoryIds */
    private function product(string $name, array $categoryIds, int $visible = 1): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, discount_percentage, stock_quantity, is_visible) VALUES (?, 100, 0, 5, ?)'
        );
        $stmt->execute([$name, $visible]);
        $id = (int) $this->pdo->lastInsertId();

        $link = $this->pdo->prepare('INSERT INTO product_category_pivot (product_id, category_id) VALUES (?, ?)');
        foreach ($categoryIds as $categoryId) {
            $link->execute([$id, $categoryId]);
        }

        return $id;
    }

    public function testACategoryIsCountedAndPagedAsAWhole(): void
    {
        $phone  = $this->category('Phone');
        $gaming = $this->category('Gaming');

        for ($i = 1; $i <= 5; $i++) {
            $this->product("Phone {$i}", [$phone]);
        }
        $this->product('Console', [$gaming]);
        $this->product('Hidden phone', [$phone], 0);

        $this->assertSame(5, ProductModel::countVisible(['phone']));
        $this->assertCount(3, ProductModel::findVisiblePaginated(3, 0, ['phone']));
        $this->assertCount(2, ProductModel::findVisiblePaginated(3, 3, ['phone']), 'The second page holds the rest of the category');

        $this->assertSame(6, ProductModel::countVisible(['phone', 'gaming']), 'Several categories are any-match');
        $this->assertSame(6, ProductModel::countVisible(), 'No category, no filter');
    }

    /**
     * "You May Also Like" on the details page shares this catalogue's rule: a product an
     * admin hid is not offered — it used to be, by category and by manufacturer alike.
     */
    public function testRelatedProductsLeaveHiddenOnesOut(): void
    {
        $phone   = $this->category('Phone');
        $current = $this->product('Current phone', [$phone]);
        $shown   = $this->product('Shown phone', [$phone]);
        $this->product('Hidden phone', [$phone], 0);

        $related = ProductModel::getRelated($current);

        $this->assertSame([$shown], array_map(static fn (array $p): int => (int) $p['id'], $related));
    }

    public function testACardStillListsAllItsCategories(): void
    {
        $phone  = $this->category('Phone');
        $gaming = $this->category('Gaming');
        $this->product('Gaming phone', [$phone, $gaming]);

        $rows = ProductModel::findVisiblePaginated(10, 0, ['gaming']);

        $this->assertCount(1, $rows);
        $this->assertSame('Gaming,Phone', $rows[0]['categories']);
    }
}
