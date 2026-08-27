<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ProductModel;
use OpenApi\Attributes as OA;

/**
 * CartController — التحقق من توفر المخزون
 * السلة تُدار client-side بالكامل (JS/localStorage)
 * الـ endpoint الوحيد هنا هو فحص المخزون (منقول من handlers/check_cart_stock.php)
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
        description: 'السلة تُدار كاملة في المتصفح (localStorage)؛ هذه النقطة تتحقق من '
                   . 'المخزون والسعر الحاليين قبل إتمام الطلب. المنتجات المخفية لا تُرجَع.',
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

                السلّة محفوظة في متصفّح الزائر، فقد تحمل أسعاراً ومخزوناً
                قديمين. هذه النقطة تُرجع الحقيقة من القاعدة قبل الدفع.
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
}
