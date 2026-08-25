<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;

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

        try {
            $db          = Database::connect();
            $placeholders = implode(',', array_fill(0, count($variantIds), '?'));

            $stmt = $db->prepare("
                SELECT
                    pv.id            AS variant_id,
                    p.id             AS product_id,
                    p.name           AS product_name,
                    pv.color_name,
                    pv.price,
                    pv.discount_percentage,
                    pv.price_after_discount,
                    pv.stock_quantity,
                    pv.image_path
                FROM product_variants pv
                JOIN products p ON p.id = pv.product_id
                WHERE pv.id IN ({$placeholders})
                  AND p.is_visible = 1
            ");
            $stmt->execute(array_values($variantIds));
            $results = $stmt->fetchAll();

            $this->respond(true, 'Stock data retrieved.', ['items' => $results]);

        } catch (\Exception $e) {
            error_log("CartController::checkStock Error: " . $e->getMessage());
            $this->respond(false, 'Server error, please try again.');
        }
    }

    /** يُرجع JSON ويوقف التنفيذ */
    private function respond(bool $success, string $message, array $extra = []): never
    {
        echo json_encode(
            array_merge(['success' => $success, 'message' => $message], $extra),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}
