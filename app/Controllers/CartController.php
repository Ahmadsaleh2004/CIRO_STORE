<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Middleware;
use App\Models\CartModel;
use App\Models\ProductModel;
use OpenApi\Attributes as OA;

/**
 * CartController — the cart, and stock checks.
 *
 * ⚠️ The cart used to live entirely in the browser (localStorage), so it did not
 * follow the user between devices and vanished when browser data was cleared —
 * and losing a full cart is a lost sale, not a UI annoyance. It now lives on the
 * server, in CartModel.
 *
 * There is no guest cart: the cart button and the "add to cart" button are both
 * login-guarded in all three templates, and a signed-out visitor is pushed to the
 * login modal. That is why every endpoint here carries the `auth` guard in the
 * route table.
 */
class CartController extends Controller
{
    // ════════════════════════════════════════════════════════
    // POST /cart/check-stock
    // Takes:    variant_ids[] (an array of variant ids)
    // Returns:  current stock and price for each variant
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/cart/check-stock',
        summary: 'Check availability and pricing for the variants in the cart',
        description: 'Called from the product pages and the wishlist to refresh their cards with '
                   . 'live stock and pricing. Hidden products are not returned.',
        tags: ['Store - Cart'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['variant_ids'],
                    properties: [
                        new OA\Property(
                            property: 'variant_ids',
                            type: 'array',
                            items: new OA\Items(type: 'integer'),
                            description: 'The variant ids to check'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: <<<'TXT'
                Live stock for the requested variants.

                A card rendered on a page that has been left open may be showing a
                stale price and stale stock. This endpoint returns the truth from
                the database.
                TXT,
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                        new OA\Schema(
                            properties: [
                                new OA\Property(
                                    property: 'items',
                                    type: 'array',
                                    description: 'One entry per requested variant_id. Deleted variants drop out of the result.',
                                    items: new OA\Items(ref: '#/components/schemas/ProductVariant')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
        ]
    )]
    public function checkStock(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $rawIds     = $_POST['variant_ids'] ?? [];
        $variantIds = array_filter(array_map('intval', (array)$rawIds));

        if (empty($variantIds)) {
            $this->respond(false, 'No variant IDs provided.');
        }

        // The model swallows any failure, logs it, and returns an empty array, so
        // there is no need for a try/catch here — the response stays valid JSON in
        // every case.
        $results = ProductModel::findVariantsStock($variantIds);

        $this->respond(true, 'Stock data retrieved.', ['items' => $results]);
    }

    // ════════════════════════════════════════════════════════
    // GET /cart — the user's cart
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/cart',
        summary: "The current user's cart",
        description: 'Prices and stock are read from the database on every request rather than '
                   . 'captured at add time, so any change shows up in the cart before checkout '
                   . 'rather than at it.',
        tags: ['Store - Cart'],
        security: [['userSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'JSON — {success, items, count}'),
            new OA\Response(response: 401, ref: '#/components/responses/SessionExpired'),
        ]
    )]
    public function index(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requireLogin();

        $userId = (int) $_SESSION['user_id'];

        $this->respond(true, 'OK', [
            'items' => CartModel::getForUser($userId),
            'count' => CartModel::countItems($userId),
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /cart/add
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/cart/add',
        summary: 'Add a quantity to the cart (summed with what is already there)',
        description: 'Adding the same variant twice updates one line rather than creating a second. '
                   . 'Stock is not checked here: a cart is an intention, not a reservation, and the '
                   . 'reservation happens at /checkout.',
        tags: ['Store - Cart'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id', 'variant_id', 'csrf_token'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'integer'),
                    new OA\Property(property: 'variant_id', type: 'integer'),
                    new OA\Property(property: 'qty', type: 'integer', default: 1, minimum: 1, maximum: 100),
                    new OA\Property(property: 'csrf_token', type: 'string'),
                ]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'JSON — {success, items, count}')]
    )]
    public function add(): void
    {
        $this->beginJsonPost();
        Middleware::requireLogin();

        $post      = $this->requestData();
        $userId    = (int) $_SESSION['user_id'];
        $productId = (int) ($post['product_id'] ?? 0);
        $variantId = (int) ($post['variant_id'] ?? 0);
        $qty       = (int) ($post['qty'] ?? 1);

        if ($productId <= 0 || $variantId <= 0) {
            $this->respond(false, 'Invalid product.');
        }

        if (!CartModel::add($userId, $productId, $variantId, $qty)) {
            // The usual causes: a variant that stopped existing between page render and
            // click, or a quantity outside the allowed range. The message is the same
            // for both — the detail belongs in the log, not in the response.
            $this->respond(false, 'Could not add this item to your cart.');
        }

        $this->respondWithCart($userId, 'Added to cart.');
    }

    // ════════════════════════════════════════════════════════
    // POST /cart/update — set a line's quantity absolutely
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/cart/update',
        summary: 'Set a cart line\'s quantity (zero removes it)',
        tags: ['Store - Cart'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['variant_id', 'qty', 'csrf_token'],
                properties: [
                    new OA\Property(property: 'variant_id', type: 'integer'),
                    new OA\Property(property: 'qty', type: 'integer', minimum: 0, maximum: 100),
                    new OA\Property(property: 'csrf_token', type: 'string'),
                ]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'JSON — {success, items, count}')]
    )]
    public function update(): void
    {
        $this->beginJsonPost();
        Middleware::requireLogin();

        $post      = $this->requestData();
        $userId    = (int) $_SESSION['user_id'];
        $variantId = (int) ($post['variant_id'] ?? 0);

        if ($variantId <= 0 || !isset($post['qty'])) {
            $this->respond(false, 'Invalid request.');
        }

        if (!CartModel::setQuantity($userId, $variantId, (int) $post['qty'])) {
            $this->respond(false, 'Could not update this item.');
        }

        $this->respondWithCart($userId, 'Cart updated.');
    }

    // ════════════════════════════════════════════════════════
    // POST /cart/remove
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/cart/remove',
        summary: 'Remove a line from the cart',
        tags: ['Store - Cart'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['variant_id', 'csrf_token'],
                properties: [
                    new OA\Property(property: 'variant_id', type: 'integer'),
                    new OA\Property(property: 'csrf_token', type: 'string'),
                ]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'JSON — {success, items, count}')]
    )]
    public function remove(): void
    {
        $this->beginJsonPost();
        Middleware::requireLogin();

        $userId    = (int) $_SESSION['user_id'];
        $variantId = (int) ($this->requestData()['variant_id'] ?? 0);

        if ($variantId <= 0) {
            $this->respond(false, 'Invalid request.');
        }

        CartModel::remove($userId, $variantId);

        $this->respondWithCart($userId, 'Item removed.');
    }

    /**
     * Responds with the whole cart after any change.
     *
     * ── Why the whole cart and not just an acknowledgement ───────
     *
     * Because the client is stateless now: the server is the only source of
     * truth. An "ok" on its own forces the client into a second request to learn
     * what the cart became — two round trips for every increment or decrement.
     *
     * More importantly it resolves the two-tab conflict with no extra logic:
     * every response carries the complete state, so the tab making a change sees
     * both its own result and the other tab's.
     */
    private function respondWithCart(int $userId, string $message): never
    {
        $this->respond(true, $message, [
            'items' => CartModel::getForUser($userId),
            'count' => CartModel::countItems($userId),
        ]);
    }
}
