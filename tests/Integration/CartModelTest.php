<?php

namespace Tests\Integration;

use App\Models\CartModel;
use Tests\Support\DatabaseTestCase;

/**
 * The user's cart on the server.
 *
 * ══════════════════════════════════════════════════════════════
 * What this file guards
 * ══════════════════════════════════════════════════════════════
 *
 * The cart moved from `localStorage` into a table. And what was previously a display error
 * in one browser became a shared data error: a quantity doubling, a line appearing twice, or
 * a cart read by somebody other than its owner.
 *
 * And three properties in particular do not reveal their breaking in ordinary use:
 *
 *   · a repeated add updates a row rather than creating a second — the unique key enforces
 *     that, but `ON DUPLICATE KEY` is what makes it succeed rather than throw.
 *   · editing another user's cart is refused — the ownership condition is in the statement
 *     itself.
 *   · and the price is read from the database on every read rather than stored — so a price
 *     change shows in the cart before checkout rather than at it.
 */
final class CartModelTest extends DatabaseTestCase
{
    private function makeUser(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (full_name, email) VALUES (?, ?)');
        $stmt->execute(['Cart User', 'cart' . uniqid() . '@example.com']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} [productId, variantId] */
    private function makeProduct(float $price = 100.00, float $discount = 0.00, int $stock = 10, int $visible = 1): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, discount_percentage, stock_quantity, is_visible)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Cart Product', $price, $discount, $stock, $visible]);
        $productId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, discount_percentage, stock_quantity)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$productId, 'Black', $price, $discount, $stock]);

        return [$productId, (int) $this->pdo->lastInsertId()];
    }

    // ════════════════════════════════════════════════════════
    // Adding
    // ════════════════════════════════════════════════════════

    public function testAddingCreatesOneLineWithLiveProductData(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct(100.00, 10.00, 10);

        $this->assertTrue(CartModel::add($userId, $productId, $variantId, 2));

        $items = CartModel::getForUser($userId);
        $this->assertCount(1, $items);

        // The price, the name and the stock come from the database rather than the input:
        // 100 − 10% = 90
        $this->assertSame(90.00, $items[0]['price']);
        $this->assertSame('Cart Product', $items[0]['name']);
        $this->assertSame('Black', $items[0]['color_name']);
        $this->assertSame(2, $items[0]['quantity']);
        $this->assertSame(10, $items[0]['stock']);
    }

    public function testAddingTheSameVariantTwiceSumsIntoOneLine(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($userId, $productId, $variantId, 2);
        CartModel::add($userId, $productId, $variantId, 3);

        $items = CartModel::getForUser($userId);

        // Two rows for the same colour mean a cart displaying the product twice and
        // ordering it twice. The unique key prevents that, and ON DUPLICATE KEY makes the
        // prevention a success rather than an error.
        $this->assertCount(1, $items);
        $this->assertSame(5, $items[0]['quantity']);
        $this->assertSame(1, $this->countRows('cart_items'));
    }

    public function testTwoVariantsOfOneProductAreSeparateLines(): void
    {
        $userId = $this->makeUser();
        [$productId, $firstVariant] = $this->makeProduct();

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, stock_quantity) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$productId, 'White', 100.00, 5]);
        $secondVariant = (int) $this->pdo->lastInsertId();

        CartModel::add($userId, $productId, $firstVariant, 1);
        CartModel::add($userId, $productId, $secondVariant, 1);

        // The same product in two colours is two different things in the cart — which is
        // what the interface actually shows. Which is why the unique key is on the variant
        // rather than on the product.
        $this->assertCount(2, CartModel::getForUser($userId));
    }

    public function testTheQuantityCeilingIsEnforced(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        // Anything outside the accepted range is refused before it touches the database.
        $this->assertFalse(CartModel::add($userId, $productId, $variantId, CartModel::MAX_QTY + 1));
        $this->assertFalse(CartModel::add($userId, $productId, $variantId, 0));
        $this->assertSame(0, $this->countRows('cart_items'));
    }

    public function testMaxQtyIsTheCeilingWhenStockIsPlentiful(): void
    {
        $userId = $this->makeUser();

        // Stock wider than MAX_QTY, so it is clear which of the two ceilings governs.
        [$productId, $variantId] = $this->makeProduct(100.00, 0.00, CartModel::MAX_QTY + 50);

        CartModel::add($userId, $productId, $variantId, CartModel::MAX_QTY);
        CartModel::add($userId, $productId, $variantId, 10);

        // Both ceilings together in a LEAST inside the SQL: the stock and MAX_QTY. Here the
        // second wins because the first is wider.
        $this->assertSame(CartModel::MAX_QTY, CartModel::getForUser($userId)[0]['quantity']);
    }

    // ════════════════════════════════════════════════════════
    // Updating, deleting — and ownership
    // ════════════════════════════════════════════════════════

    public function testSettingQuantityToZeroRemovesTheLine(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($userId, $productId, $variantId, 3);
        $this->assertTrue(CartModel::setQuantity($userId, $variantId, 0));

        // A row with a quantity of zero appears in the cart and is never ordered — a
        // meaningless state that confuses the display and the counter alike.
        $this->assertSame(0, $this->countRows('cart_items'));
    }

    public function testSettingTheSameQuantityIsStillASuccess(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($userId, $productId, $variantId, 2);

        // MySQL returns rowCount = 0 when the value does not change. Relying on it alone
        // would return "failed" for a success — showing the customer an error with no cause.
        $this->assertTrue(CartModel::setQuantity($userId, $variantId, 2));
    }

    public function testAUserCannotTouchAnotherUsersCart(): void
    {
        $owner    = $this->makeUser();
        $stranger = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($owner, $productId, $variantId, 4);

        // The user_id condition is in the UPDATE/DELETE statement itself — not a separate
        // check that could be forgotten on another path (IDOR).
        $this->assertFalse(CartModel::setQuantity($stranger, $variantId, 99));
        $this->assertFalse(CartModel::remove($stranger, $variantId));

        $this->assertSame(4, CartModel::getForUser($owner)[0]['quantity']);
        $this->assertSame([], CartModel::getForUser($stranger));
    }

    public function testClearEmptiesOnlyThatUsersCart(): void
    {
        $first  = $this->makeUser();
        $second = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($first, $productId, $variantId, 1);
        CartModel::add($second, $productId, $variantId, 2);

        CartModel::clear($first);

        $this->assertSame([], CartModel::getForUser($first));
        $this->assertCount(1, CartModel::getForUser($second));
    }

    // ════════════════════════════════════════════════════════
    // What the read reflects from the database
    // ════════════════════════════════════════════════════════

    public function testAPriceChangeShowsInTheCartWithoutTouchingIt(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct(100.00, 0.00, 10);

        CartModel::add($userId, $productId, $variantId, 1);
        $this->assertSame(100.00, CartModel::getForUser($userId)[0]['price']);

        $stmt = $this->pdo->prepare('UPDATE product_variants SET price = ? WHERE id = ?');
        $stmt->execute([75.00, $variantId]);

        // There is no price column in cart_items, deliberately: a value stored away from
        // its source becomes a second source of truth, and one day somebody reads it. And the
        // live read makes a price change visible in the cart before checkout rather than at
        // it.
        $this->assertSame(75.00, CartModel::getForUser($userId)[0]['price']);
    }

    public function testAHiddenProductDropsOutOfTheCart(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($userId, $productId, $variantId, 1);
        $this->assertCount(1, CartModel::getForUser($userId));

        $stmt = $this->pdo->prepare('UPDATE products SET is_visible = 0 WHERE id = ?');
        $stmt->execute([$productId]);

        // It disappears from the cart rather than reaching checkout and being refused there
        // — a late refusal at the last step is worse than an early disappearance.
        $this->assertSame([], CartModel::getForUser($userId));
    }

    public function testDeletingAVariantRemovesItsCartLines(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($userId, $productId, $variantId, 1);

        $stmt = $this->pdo->prepare('DELETE FROM product_variants WHERE id = ?');
        $stmt->execute([$variantId]);

        // A cart pointing at a deleted variant is not data but a fault waiting for somebody
        // to read it. ON DELETE CASCADE prevents its existing at all.
        $this->assertSame(0, $this->countRows('cart_items'));
    }

    public function testTheCounterSumsQuantitiesNotLines(): void
    {
        $userId = $this->makeUser();
        [$p1, $v1] = $this->makeProduct();
        [$p2, $v2] = $this->makeProduct();

        CartModel::add($userId, $p1, $v1, 3);
        CartModel::add($userId, $p2, $v2, 4);

        // The badge says "how many units", not "how many colours".
        $this->assertSame(7, CartModel::countItems($userId));
    }
    public function testAVariantFromAnotherProductIsRejected(): void
    {
        $userId = $this->makeUser();
        [$firstProduct, ]      = $this->makeProduct();
        [, $otherVariant]      = $this->makeProduct(500.00);

        // Each of the two foreign keys checks only that its own row exists, and nothing
        // ties the two together. And without this check the cart displayed **one product's
        // name with another product's price** — measured on a live server before the fix.
        $this->assertFalse(CartModel::add($userId, $firstProduct, $otherVariant, 1));
        $this->assertSame(0, $this->countRows('cart_items'));
    }

    public function testANonexistentVariantIsRejected(): void
    {
        $userId = $this->makeUser();
        [$productId, ] = $this->makeProduct();

        $this->assertFalse(CartModel::add($userId, $productId, 999999, 1));
        $this->assertSame(0, $this->countRows('cart_items'));
    }
    // ════════════════════════════════════════════════════════
    // The stock ceiling — a report from real use
    // ════════════════════════════════════════════════════════

    public function testTheCartNeverHoldsMoreThanTheAvailableStock(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct(100.00, 0.00, 5);

        // "If there are only 5 of the iPhone and I click add quickly… the number keeps
        // going up" — a report from real use, reproduced on a live server: ten parallel adds
        // → the cart holds 10 and the stock is 5.
        //
        // The ceiling was MAX_QTY alone, and the only guard against exceeding the stock lived
        // in the browser — and that collapses under rapid clicking, because every click reads
        // the mirror before the previous one's reply arrives.
        $this->assertTrue(CartModel::add($userId, $productId, $variantId, 50));
        $this->assertSame(5, CartModel::getForUser($userId)[0]['quantity']);
    }

    public function testRepeatedAddsStopAtTheStockCeiling(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct(100.00, 0.00, 3);

        for ($i = 0; $i < 10; $i++) {
            CartModel::add($userId, $productId, $variantId, 1);
        }

        // The ceiling is inside the SQL, in the addition statement itself, so ten concurrent
        // statements all settle at the stock with no race.
        $this->assertSame(3, CartModel::getForUser($userId)[0]['quantity']);
    }

    public function testAnOutOfStockVariantCannotEnterTheCart(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct(100.00, 0.00, 0);

        // A ceiling of zero means a row with a quantity of zero — a meaningless state.
        // Refusing is clearer.
        $this->assertFalse(CartModel::add($userId, $productId, $variantId, 1));
        $this->assertSame(0, $this->countRows('cart_items'));
    }

    // ════════════════════════════════════════════════════════
    // The stock ceiling on updates — not on adds alone
    // ════════════════════════════════════════════════════════

    public function testSetQuantityIsCappedByStock(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct(100.00, 0.00, 2);

        CartModel::add($userId, $productId, $variantId, 1);

        // This used to go through as it was: `setQuantity` checked MAX_QTY alone, so it
        // wrote 100 onto a variant with a stock of 2. And the door is open from the network
        // directly through POST /cart/update — bypassing the interface is not even required.
        //
        // And the ceiling in `add` had been in place since the "the number keeps going up"
        // report; the hole was that the update path never took it.
        $this->assertTrue(CartModel::setQuantity($userId, $variantId, 100));
        $this->assertSame(2, CartModel::getForUser($userId)[0]['quantity']);
    }

    public function testSetQuantityBelowStockIsWrittenAsAsked(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct(100.00, 0.00, 9);

        CartModel::add($userId, $productId, $variantId, 1);

        // The ceiling is a ceiling rather than a fixed value: anything below the stock
        // passes unchanged.
        $this->assertTrue(CartModel::setQuantity($userId, $variantId, 4));
        $this->assertSame(4, CartModel::getForUser($userId)[0]['quantity']);
    }

    public function testSetQuantityDropsTheLineWhenStockRanOut(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct(100.00, 0.00, 5);

        CartModel::add($userId, $productId, $variantId, 3);

        // The stock running out after the line entered the cart — the real-world case:
        // another customer completed their purchase while the cart was open.
        $this->pdo->prepare('UPDATE product_variants SET stock_quantity = 0 WHERE id = ?')
            ->execute([$variantId]);

        // Keeping the line at its old quantity means a cart promising what does not exist,
        // and `add` already refuses a sold-out variant — so it will not do for one path to
        // guard it and the other to let it through.
        $this->assertTrue(CartModel::setQuantity($userId, $variantId, 1));
        $this->assertSame(0, $this->countRows('cart_items'));
    }
}
