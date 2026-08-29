<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Middleware;
use App\Models\CartModel;
use App\Models\ProductModel;
use OpenApi\Attributes as OA;

/**
 * CartController — السلّة وفحص المخزون.
 *
 * ⚠️ كانت السلّة تُدار في المتصفّح بالكامل (localStorage)، فلا تتبع
 * المستخدم بين أجهزته، وتضيع بمسح بيانات المتصفّح — وضياع سلّة مليئة
 * خسارة بيع لا إزعاج واجهة. صارت على الخادم في CartModel.
 *
 * ولا سلّة زائر: زرّ السلّة وزرّ «أضف للسلّة» محروسان بتسجيل الدخول في
 * القوالب الثلاثة، وغير المسجَّل يُدفع إلى نافذة الدخول. ولذلك كل نقطة
 * هنا تحمل حارس `auth` في جدول المسارات.
 */
class CartController extends Controller
{
    // ════════════════════════════════════════════════════════
    // POST /cart/check-stock
    // يستقبل: variant_ids[] (مصفوفة معرّفات الـ Variants)
    // يُرجع: بيانات المخزون والسعر الحالي لكل Variant
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/cart/check-stock',
        summary: 'التحقق من توفّر وأسعار الـvariants الموجودة في السلة',
        description: 'تُستدعى من صفحات المنتجات وقائمة الأمنيات لتحديث بطاقاتها بالمخزون '
                   . 'والسعر الحيَّين. المنتجات المخفية لا تُرجَع.',
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
                            description: 'معرّفات الـvariants المطلوب فحصها'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: <<<'TXT'
                المخزون الحيّ للنسخ المطلوبة.

                بطاقةٌ معروضة على صفحة بقيت مفتوحة قد تحمل سعراً ومخزوناً
                قديمين. هذه النقطة تُرجع الحقيقة من القاعدة.
                TXT,
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                        new OA\Schema(
                            properties: [
                                new OA\Property(
                                    property: 'items',
                                    type: 'array',
                                    description: 'نسخة لكل variant_id مطلوب. النسخ المحذوفة تسقط من الناتج.',
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

        // الموديل يبتلع أي فشل ويسجّله ويُرجع مصفوفة فارغة، فلا حاجة
        // لـtry/catch هنا — الاستجابة تبقى JSON صالحاً في كل الحالات.
        $results = ProductModel::findVariantsStock($variantIds);

        $this->respond(true, 'Stock data retrieved.', ['items' => $results]);
    }

    // ════════════════════════════════════════════════════════
    // GET /cart — سلّة المستخدم
    // ════════════════════════════════════════════════════════
    #[OA\Get(
        path: '/cart',
        summary: 'سلّة المستخدم الحالي',
        description: 'الأسعار والمخزون تُقرأ من القاعدة عند كل طلب لا من وقت الإضافة، '
                   . 'فتظهر أي تغيّرات في السلّة قبل صفحة الدفع لا عندها.',
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
        summary: 'إضافة كمية إلى السلّة (تُجمَع مع الموجود)',
        description: 'إضافة نفس الـvariant مرّتين تُحدّث كمية سطر واحد لا تُنشئ ثانياً. '
                   . 'ولا يُتحقَّق من المخزون هنا: السلّة نيّة لا حجز، والحجز يقع في /checkout.',
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
            // أشيع سبب: variant لم يعد موجوداً بين عرض الصفحة والنقر،
            // أو كمية خارج الحدّ. الرسالة واحدة للاثنين — التفصيل في
            // السجلّ لا في الاستجابة.
            $this->respond(false, 'Could not add this item to your cart.');
        }

        $this->respondWithCart($userId, 'Added to cart.');
    }

    // ════════════════════════════════════════════════════════
    // POST /cart/update — ضبط كمية سطر ضبطاً مطلقاً
    // ════════════════════════════════════════════════════════
    #[OA\Post(
        path: '/cart/update',
        summary: 'ضبط كمية سطر في السلّة (الصفر يحذفه)',
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
        summary: 'حذف سطر من السلّة',
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
     * يردّ بالسلّة كاملةً بعد أي تعديل.
     *
     * ── لماذا السلّة كلّها لا تأكيدٌ فقط ──────────────────────────
     *
     * لأن العميل بلا حالة الآن: الخادم هو المرجع الوحيد. وردّ «تمّ»
     * وحده يجبر العميل على طلب ثانٍ ليعرف ما صارت إليه السلّة — طلبان
     * لكل نقرة زيادة أو نقصان.
     *
     * والأهمّ أنه يحلّ تعارض التبويبين بلا منطق إضافي: كل استجابة تحمل
     * الحالة الكاملة، فالتبويب الذي يعدّل يرى نتيجة تعديله ونتيجة
     * تعديل غيره معاً.
     */
    private function respondWithCart(int $userId, string $message): never
    {
        $this->respond(true, $message, [
            'items' => CartModel::getForUser($userId),
            'count' => CartModel::countItems($userId),
        ]);
    }
}
