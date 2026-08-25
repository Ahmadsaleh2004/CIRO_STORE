<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ProductModel;

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
