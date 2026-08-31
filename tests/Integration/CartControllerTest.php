<?php

namespace Tests\Integration;

use App\Controllers\CartController;
use Tests\Support\ControllerTestCase;

/**
 * CartController, called in this process rather than over HTTP.
 *
 * The first controller in the project to be tested this way. Everything under
 * app/Controllers had stood at 0% coverage — not because the endpoints were untested, but
 * because Controller::respond() ends a request with `exit`, which takes the test runner
 * down with it. The CLI-only throw in respond() is what lets an action be called and its
 * answer read.
 *
 * The cart is the right place to start: five actions, all of them ending through
 * respond(), covering the three shapes worth proving — the guard refuses, the validation
 * refuses, and the happy path changes the database.
 */
final class CartControllerTest extends ControllerTestCase
{
    private function insertProduct(string $name = 'Test Product', float $price = 99.99): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, stock_quantity, is_visible) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$name, $price, 25]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertVariant(int $productId, int $stock = 10, float $price = 99.99): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, stock_quantity)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$productId, 'Black', $price, $stock]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertUser(string $email = 'shopper@example.com'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)'
        );
        $stmt->execute(['Shopper', $email, 'not-a-real-hash']);
        return (int) $this->pdo->lastInsertId();
    }

    private function signIn(int $userId): void
    {
        $_SESSION['user_id'] = $userId;
    }

    // ── checkStock: public, no guard ─────────────────────────

    public function testCheckStockRefusesAnythingThatIsNotAPost(): void
    {
        $json = $this->callJson([new CartController(), 'checkStock'], [], [], 'GET');

        $this->assertFalse($json['success']);
        $this->assertSame('Method not allowed.', $json['message']);
    }

    public function testCheckStockRefusesAnEmptyVariantList(): void
    {
        $json = $this->callJson([new CartController(), 'checkStock']);

        $this->assertFalse($json['success']);
        $this->assertSame('No variant IDs provided.', $json['message']);
    }

    /**
     * The endpoint the product cards call to refresh a price left open in a stale tab, so
     * what matters is that it reports the database's number rather than the page's.
     */
    public function testCheckStockReturnsTheLiveStockForTheRequestedVariants(): void
    {
        $productId = $this->insertProduct();
        $variantId = $this->insertVariant($productId, 7);

        $json = $this->callJson(
            [new CartController(), 'checkStock'],
            ['variant_ids' => [$variantId]]
        );

        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('items', $json);
        $this->assertCount(1, $json['items']);
        $this->assertSame(7, (int) $json['items'][0]['stock_quantity']);
    }

    // ── add: guarded, and the guard is the point ─────────────

    public function testAddRefusesARequestWithoutACsrfToken(): void
    {
        $json = $this->callJson([new CartController(), 'add'], ['product_id' => 1]);

        $this->assertFalse($json['success']);
        $this->assertSame('csrf_invalid', $json['error_code']);
    }

    /**
     * A signed-out visitor with a perfectly valid token is still refused — the token
     * proves the request came from the page, not that anybody is signed in.
     */
    public function testAddRefusesASignedOutVisitorEvenWithAValidToken(): void
    {
        $json = $this->callJson(
            [new CartController(), 'add'],
            $this->withCsrf(['product_id' => 1, 'variant_id' => 1, 'qty' => 1])
        );

        $this->assertFalse($json['success']);
        $this->assertSame(0, $this->countRows('cart_items'));
    }

    public function testAddRefusesAProductIdOfZero(): void
    {
        $this->signIn($this->insertUser());

        $json = $this->callJson(
            [new CartController(), 'add'],
            $this->withCsrf(['product_id' => 0, 'variant_id' => 0, 'qty' => 1])
        );

        $this->assertFalse($json['success']);
        $this->assertSame('Invalid product.', $json['message']);
        $this->assertSame(0, $this->countRows('cart_items'));
    }

    public function testAddPutsTheVariantInTheUsersCart(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $variantId = $this->insertVariant($productId);
        $this->signIn($userId);

        $json = $this->callJson(
            [new CartController(), 'add'],
            $this->withCsrf(['product_id' => $productId, 'variant_id' => $variantId, 'qty' => 2])
        );

        $this->assertTrue($json['success'], 'The response was: ' . json_encode($json));
        $this->assertSame(1, $this->countRows('cart_items'));

        $row = $this->pdo->query('SELECT user_id, variant_id, quantity FROM cart_items')->fetch();
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame($variantId, (int) $row['variant_id']);
        $this->assertSame(2, (int) $row['quantity']);
    }
}
