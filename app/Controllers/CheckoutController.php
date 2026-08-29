<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Middleware;
use App\Models\OrderModel;
use App\Models\NotificationModel;
use OpenApi\Attributes as OA;

/**
 * CheckoutController — عرض صفحة الدفع + إنشاء الطلب + إلغاؤه
 * منقول من pages/checkout.php + handlers/order_handler.php القديمين
 */
class CheckoutController extends Controller
{
    /**
     * أقصى كمية مقبولة لعنصر واحد في طلب واحد.
     *
     * الرقم اختير ليكون أوسع من أي طلب تجزئة معقول وأضيق بكثير من مدى
     * `int`، فلا يصل ضربٌ عشري ولا عمود `unsigned` إلى حافّته. متجرٌ
     * يبيع بالجملة يرفع الرقم هنا في موضع واحد.
     */
    private const MAX_ITEM_QTY = 100;

    // ════════════════════════════════════════════════════════
    // GET /checkout — عرض صفحة الدفع
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/checkout',
        summary: 'صفحة إتمام الطلب (ثلاث خطوات)',
        tags: ['Store - Checkout'],
        security: [['userSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML'),
            new OA\Response(response: 302, description: 'تحويل للرئيسية مع فتح نافذة الدخول إن لم يكن مسجّلاً'),
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
            'extraHead'   => '<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/checkout.css">',
            // منطق الصفحة صار في ملف خارجي. كان هنا سطر <script> يحقن
            // window.CHECKOUT_IDEMPOTENCY_KEY — لم يعد له داع: المفتاح
            // يصل الـview كمتغيّر ويُطبع في data-checkout-idempotency.
            'extraScripts' => '<script src="' . URLROOT . '/js/features/checkout.js" defer></script>',
            'addresses'   => $addresses,
            'csrf'        => $csrf,
            'idempotencyKey' => $idempotencyKey,
            'returnPolicy' => '14-day return policy on all products in original condition.',
            'userLoggedIn' => true,
            'userName'    => $_SESSION['user_name'] ?? '',
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /checkout — إنشاء الطلب
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/checkout',
        summary: 'إنشاء الطلب من محتويات السلة',
        description: 'العميل يرسل **ماذا وكم** فقط. كل قيمة مالية تُقرأ من قاعدة البيانات '
                   . 'داخل المعاملة نفسها التي تحجز المخزون. وحقل shown_price يُقارَن ولا '
                   . 'يُخزَّن: إن اختلف عن سعر القاعدة يُرفض الطلب كاملاً ويردّ الخادم '
                   . 'error_code=price_changed مع الأسعار الصحيحة.',
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
                                    description: 'السعر الذي عُرض على الزبون — للمقارنة وحدها، لا يُخزَّن ولا يُحسب منه شيء'
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
                description: 'نتيجة العملية. النجاح يحمل order_id وredirect. والفشل يحمل '
                           . 'error_code من: price_changed · unavailable · out_of_stock · '
                           . 'error · csrf_invalid. وprice_changed وحده يحمل معه items '
                           . 'بالأسعار الصحيحة.',
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

        // قراءة body JSON من cart.js (fetchWithCsrfRetry)
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

        // التحقق من ملكية العنوان
        $userAddresses = OrderModel::getUserAddresses($userId);
        $validAddress  = array_filter($userAddresses, fn($a) => (int)$a['id'] === $addressId);
        if (empty($validAddress)) {
            $this->respond(false, 'Invalid address selected.');
        }

        // ── تنظيف عناصر الطلب ────────────────────────────────
        //
        // ⚠️ أسماء الحقول هنا كانت **لا تطابق ما يرسله المتصفح**، وكان
        // ذلك عطلاً كاملاً لا ثغرة: السلّة تُبنى في localStorage بالشكل
        // {id, variant_id, quantity, price} — من ثلاثة مواضع متفقة
        // (products-catalog.js · product-details.js · wishlist.js) —
        // بينما كان هذا السطر يقرأ `product_id` و`qty`. فكان
        // $productId = 0 لكل عنصر، فيسقط عند `continue`، فتخرج
        // $cleanItems فارغة، فيتلقّى **كل** زبون «Invalid items in cart»
        // بعد أن يملأ ثلاث خطوات. ولم يكن أحد يقدر على إتمام طلب.
        //
        // والعطل أقدم من كل عمليات التنظيف — موجود في كومِت الأساس
        // 841d64d نفسه، ونجا لأن لا اختبار يغطّي هذا المسار (وهو ما
        // يعالجه البند 1.7).
        //
        // العلاج في العميل لا هنا: checkout.js صار يترجم شكل السلّة إلى
        // شكل الـAPI الموثَّق في OpenAPI قبل الإرسال. توسيع الخادم
        // ليقبل الاسمين كان سيثبّت التسميتين معاً إلى الأبد.
        $cleanItems = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $variantId = (int)($item['variant_id'] ?? 0);
            $qty       = (int)($item['qty']         ?? 0);

            // ما عرضه المتصفح على الزبون. يُمرَّر للمقارنة وحدها —
            // OrderModel لا يحسب منه شيئاً ولا يخزّنه. راجع التعليق
            // المفصّل فوق placeOrder.
            $shownPrice = (float)($item['shown_price'] ?? -1);

            // الحدّ الأعلى ليس تجميلاً: qty يدخل في ضرب عشري وفي
            // stock_quantity غير المُوقَّع. كمية سخيفة يجب أن تُرفض هنا
            // بوضوح لا أن تُترك لفحص المخزون ليردّ «نفد المخزون».
            //
            // و`variant_id` مطلوب: المتجر يفرض أن لكل منتج variant
            // واحداً على الأقل (storeAdd و storeEdit ترفضان دونه)، وكل
            // مسارات الإضافة للسلّة تمرّره. راجع التعليق في placeOrder.
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

        // ── الرفض يقول سببه ──────────────────────────────────
        //
        // كان الردّ واحداً لكل فشل: «Could not place order. Some items
        // may be out of stock.» — وهي تُقال أيضاً عن عطل قاعدة بيانات
        // وعن منتج أُخفي. رسالة تخمّن السبب تُرسل الزبون ليصلح ما ليس
        // مكسوراً.
        if ($result['status'] !== OrderModel::PLACE_OK) {
            $this->respondPlaceFailure($result);
        }

        $orderId = $result['order_id'];

        // إرسال إشعار للمستخدم
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
        summary: 'إلغاء طلب من طرف المستخدم',
        description: 'يُسمح بالإلغاء فقط قبل أن يتولّى الطلب أدمن. نفس الزر المشترك في '
                   . 'views/shared/order-cancel-button.php يخدم واجهة الأدمن بنقطة أخرى.',
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
                description: 'نتيجة العملية. الحقل success يفصل النجاح عن الفشل — كود HTTP يبقى 200 في الحالتين. وعند فشل CSRF يحمل الجسم error_code=csrf_invalid.',
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
        summary: 'صفحة تأكيد الطلب بعد إتمامه',
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
            'extraHead'   => '<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/confirmation.css">',
            'orderId'     => $orderId,
            'userLoggedIn' => true,
            'userName'    => $_SESSION['user_name'] ?? '',
        ]);
    }

    /**
     * يترجم رمز فشل placeOrder إلى ردّ JSON، ويوقف التنفيذ.
     *
     * الرمز يسافر إلى العميل في `error_code` — بنفس العقد الذي أرساه
     * `csrf_invalid`: **ما تقرأه الآلة رمزٌ ثابت، وما يقرأه الإنسان نصٌّ
     * حرّ يتغيّر بلا كسر أحد.** وبلا هذا الفصل كان `checkout.js` سيضطر
     * لمطابقة نصّ الرسالة ليعرف متى يحدّث أسعار السلّة — وهو بالضبط
     * الفخّ الذي أوقع غلاف CSRF ثلاث مرّات.
     *
     * @param array{status:string, items?:list<array<string,mixed>>} $result
     */
    private function respondPlaceFailure(array $result): never
    {
        if ($result['status'] === OrderModel::PLACE_PRICE_CHANGED) {
            // الأسعار الصحيحة تُرسَل مع الرفض لا في طلب تالٍ: الزبون
            // واقف أمام الشاشة الآن، وجعله يعيد التحميل ليكتشف الجديد
            // خطوةٌ ضائعة — وفرصة ليغادر.
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
