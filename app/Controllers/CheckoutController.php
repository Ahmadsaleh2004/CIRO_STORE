<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Middleware;
use App\Models\CartModel;
use App\Models\OrderModel;
use App\Models\NotificationModel;
use OpenApi\Attributes as OA;

/**
 * CheckoutController — renders the checkout page, creates the order, cancels it.
 * Moved out of the old pages/checkout.php and handlers/order_handler.php.
 */
class CheckoutController extends Controller
{
    /**
     * The largest quantity accepted for one item in one order.
     *
     * The number was chosen to be wider than any sensible retail order and far
     * narrower than the range of `int`, so neither a decimal multiplication nor an
     * `unsigned` column ever reaches its edge. A store selling wholesale raises the
     * number here, in one place.
     */
    private const MAX_ITEM_QTY = 100;

    // ════════════════════════════════════════════════════════
    // GET /checkout — render the checkout page
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/checkout',
        summary: 'Checkout page (three steps)',
        tags: ['Store - Checkout'],
        security: [['userSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'HTML page'),
            new OA\Response(response: 302, description: 'Redirect home with the login modal opened when not signed in'),
        ]
    )]
    public function index(): void
    {
        Middleware::requireLogin();

        $userId    = (int)$_SESSION['user_id'];
        $addresses = OrderModel::getUserAddresses($userId);
        $csrf      = generateCsrfToken();
        $idempotencyKey = bin2hex(random_bytes(16));

        $this->view('checkout/checkout', [
            'title'       => 'Checkout',
            'desc'        => 'Complete your order at Cairo Store.',
            'activePage'  => '',
            'robots'      => 'noindex, nofollow',
            'extraHead'   => pageCss('store/pages/checkout.css'),
            // The page logic now lives in an external file. There used to be a
            // <script> line here injecting window.CHECKOUT_IDEMPOTENCY_KEY — no longer
            // needed: the key reaches the view as a variable and is printed into
            // data-checkout-idempotency.
            'extraScripts' => jsTag('js/features/checkout.js'),
            'addresses'   => $addresses,
            'csrf'        => $csrf,
            'idempotencyKey' => $idempotencyKey,
            'returnPolicy' => '14-day return policy on all products in original condition.',
            'userLoggedIn' => true,
            'userName'    => $_SESSION['user_name'] ?? '',
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /checkout — create the order
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/checkout',
        summary: 'Create the order from the cart contents',
        description: 'The client sends **what and how many** and nothing else. Every monetary '
                   . 'value is read from the database inside the same transaction that reserves '
                   . 'stock. The shown_price field is compared and never stored: if it differs '
                   . 'from the database price the whole order is refused and the server answers '
                   . 'error_code=price_changed together with the correct prices.',
        tags: ['Store - Checkout'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items', 'address_id', 'idempotency_key', 'csrf_token'],
                properties: [
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            required: ['product_id', 'qty'],
                            properties: [
                                new OA\Property(property: 'product_id', type: 'integer'),
                                new OA\Property(property: 'variant_id', type: 'integer', nullable: true),
                                new OA\Property(property: 'qty', type: 'integer', minimum: 1, maximum: 100),
                                new OA\Property(
                                    property: 'shown_price',
                                    type: 'number',
                                    format: 'float',
                                    description: 'The price the customer was shown — for comparison only; never stored and never computed from'
                                ),
                            ],
                            type: 'object'
                        )
                    ),
                    new OA\Property(property: 'address_id', type: 'integer'),
                    new OA\Property(property: 'payment_method', type: 'string', default: 'cash_on_delivery'),
                    new OA\Property(property: 'idempotency_key', type: 'string'),
                    new OA\Property(property: 'csrf_token', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. Success carries order_id and redirect. Failure carries '
                           . 'an error_code out of: price_changed · unavailable · out_of_stock · '
                           . 'error · csrf_invalid. Only price_changed additionally carries items '
                           . 'with the correct prices.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
    )]
    public function placeOrder(): void
    {
        $this->beginJsonPost();
        Middleware::requireLogin();

        // Read the JSON body sent by cart.js (fetchWithCsrfRetry)
        $post = $this->requestData();

        $userId         = (int)$_SESSION['user_id'];
        $addressId      = (int)($post['address_id']      ?? 0);
        $paymentMethod  = $post['payment_method']         ?? 'cash_on_delivery';
        $idempotencyKey = trim($post['idempotency_key']  ?? '');
        $items          = $post['items']                  ?? [];

        if (!$addressId) {
            $this->respond(false, 'Please select a delivery address.');
        }
        if (empty($items) || !is_array($items)) {
            $this->respond(false, 'Your cart is empty.');
        }
        if (!$idempotencyKey) {
            $this->respond(false, 'Missing idempotency key.');
        }

        // Verify the address belongs to this user
        $userAddresses = OrderModel::getUserAddresses($userId);
        $validAddress  = array_filter($userAddresses, fn($a) => (int)$a['id'] === $addressId);
        if (empty($validAddress)) {
            $this->respond(false, 'Invalid address selected.');
        }

        // ── Sanitise the order items ─────────────────────────
        //
        // ⚠️ The field names here **did not match what the browser sends**, and that
        // was a total outage rather than a vulnerability: the cart is built in
        // localStorage as {id, variant_id, quantity, price} — from three places that
        // agree
        // (products-catalog.js · product-details.js · wishlist.js) —
        // while this line read `product_id` and `qty`. So $productId was 0 for every
        // item, each one fell through at `continue`, $cleanItems came out empty, and
        // **every** customer received "Invalid items in cart" after filling in three
        // steps. Nobody could complete an order at all.
        //
        // The fault is older than every cleanup pass — present in the baseline commit
        // 841d64d itself — and it survived because no test covered this path (which is
        // what item 1.7 addresses).
        //
        // The fix belongs in the client, not here: checkout.js now translates the
        // cart's shape into the API shape documented in OpenAPI before sending.
        // Widening the server to accept both names would have cemented both spellings
        // forever.
        $cleanItems = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $variantId = (int)($item['variant_id'] ?? 0);
            $qty       = (int)($item['qty']         ?? 0);

            // What the browser displayed to the customer. Passed for comparison alone —
            // OrderModel computes nothing from it and stores none of it. See the detailed
            // comment above placeOrder.
            $shownPrice = (float)($item['shown_price'] ?? -1);

            // The upper bound is not decoration: qty feeds a decimal multiplication and
            // an unsigned stock_quantity. An absurd quantity should be refused here,
            // plainly, rather than left to the stock check to answer "out of stock".
            //
            // And `variant_id` is required: the store enforces at least one variant per
            // product (storeAdd and storeEdit refuse without one), and every add-to-cart
            // path passes it. See the comment in placeOrder.
            if ($productId <= 0 || $variantId <= 0 || $qty <= 0 || $qty > self::MAX_ITEM_QTY) {
                continue;
            }

            $cleanItems[] = [
                'product_id'  => $productId,
                'variant_id'  => $variantId,
                'qty'         => $qty,
                'shown_price' => $shownPrice,
            ];
        }

        if (empty($cleanItems)) {
            $this->respond(false, 'Invalid items in cart.');
        }

        $result = OrderModel::placeOrder(
            $userId,
            $addressId,
            $cleanItems,
            $paymentMethod,
            $idempotencyKey
        );

        // ── A refusal states its reason ──────────────────────
        //
        // There used to be one answer for every failure: "Could not place order. Some
        // items may be out of stock." — said equally of a database outage and of a
        // product that was hidden. A message that guesses at the cause sends the
        // customer off to fix something that is not broken.
        if ($result['status'] !== OrderModel::PLACE_OK) {
            $this->respondPlaceFailure($result);
        }

        $orderId = $result['order_id'];

        // ── Empty the cart ───────────────────────────────────
        //
        // After the order succeeds, not before: emptying early means any later failure
        // (a changed price, exhausted stock) leaves the customer with neither a cart
        // nor an order.
        //
        // ⚠️ Nor is it emptied on the repeated idempotency path — and that is
        // deliberate: a duplicate click returns the same order, whose cart was already
        // emptied the first time. Emptying here has no second effect, which is exactly
        // right.
        CartModel::clear($userId);

        // Notify the user
        NotificationModel::insert(
            $userId,
            '✅ Order Placed Successfully',
            "Your order #{$orderId} has been placed and is being processed.",
            null,
            'order',
            $orderId
        );

        $this->respond(true, 'Order placed successfully!', [
            'order_id' => $orderId,
            'redirect' => URLROOT . '/checkout/confirmation?order_id=' . $orderId,
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /checkout/cancel-order
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/checkout/cancel-order',
        summary: 'Cancel an order, by the user',
        description: 'Cancelling is allowed only before an admin takes the order. The same shared '
                   . 'button in views/shared/order-cancel-button.php serves the admin interface '
                   . 'through a different endpoint.',
        tags: ['Store - Checkout'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['order_id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'order_id', type: 'integer'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
    )]
    public function cancelOrder(): void
    {
        $this->beginJsonPost();
        Middleware::requireLogin();

        $post = $this->requestData();

        $orderId = (int)($post['order_id'] ?? 0);
        $userId  = (int)$_SESSION['user_id'];

        if (!$orderId) {
            $this->respond(false, 'Missing order ID.');
        }

        $cancelled = OrderModel::cancelOrder($orderId, $userId);

        if (!$cancelled) {
            $this->respond(false, 'Cannot cancel this order. It may have already been processed.');
        }

        NotificationModel::insert(
            $userId,
            '🚫 Order Cancelled',
            "Your order #{$orderId} has been cancelled and stock has been restored.",
            null,
            'order',
            $orderId
        );

        $this->respond(true, 'Order cancelled successfully.', ['order_id' => $orderId]);
    }

    // ════════════════════════════════════════════════════════
    // GET /checkout/confirmation
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/checkout/confirmation',
        summary: 'Order confirmation page, after checkout',
        tags: ['Store - Checkout'],
        security: [['userSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function confirmation(): void
    {
        Middleware::requireLogin();

        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) {
            header('Location: ' . URLROOT);
            exit;
        }

        $this->view('checkout/confirmation', [
            'title'       => 'Order Confirmed',
            'desc'        => 'Your order has been placed successfully.',
            'activePage'  => '',
            'robots'      => 'noindex, nofollow',
            'extraHead'   => pageCss('store/pages/confirmation.css'),
            'orderId'     => $orderId,
            'userLoggedIn' => true,
            'userName'    => $_SESSION['user_name'] ?? '',
        ]);
    }

    /**
     * Translates a placeOrder failure code into a JSON response, then halts.
     *
     * The code travels to the client in `error_code` — under the same contract
     * `csrf_invalid` established: **what the machine reads is a stable code, and
     * what a human reads is free text that can change without breaking anyone.**
     * Without that separation `checkout.js` would have had to match on the message
     * text to know when to refresh the cart's prices — which is precisely the trap
     * the CSRF wrapper fell into three times.
     *
     * @param array{status:string, items?:list<array<string,mixed>>} $result
     */
    private function respondPlaceFailure(array $result): never
    {
        if ($result['status'] === OrderModel::PLACE_PRICE_CHANGED) {
            // The correct prices travel with the refusal rather than in a follow-up
            // request: the customer is standing at the screen right now, and making them
            // reload to discover the new figures is a wasted step — and an opportunity
            // to leave.
            $this->respond(
                false,
                'Some prices changed while your cart was open. Please review your cart before ordering.',
                [
                    'error_code' => OrderModel::PLACE_PRICE_CHANGED,
                    'items'      => $result['items'] ?? [],
                ]
            );
        }

        $messages = [
            OrderModel::PLACE_UNAVAILABLE =>
                'An item in your cart is no longer available. Please review your cart.',
            OrderModel::PLACE_OUT_OF_STOCK =>
                'Not enough stock for one of the items in your cart.',
            OrderModel::PLACE_ERROR =>
                'We could not place your order right now. Please try again in a moment.',
        ];

        $this->respond(
            false,
            $messages[$result['status']] ?? $messages[OrderModel::PLACE_ERROR],
            ['error_code' => $result['status']]
        );
    }
}
