<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Middleware;
use App\Models\OrderModel;
use App\Models\NotificationModel;

/**
 * CheckoutController — عرض صفحة الدفع + إنشاء الطلب + إلغاؤه
 * منقول من pages/checkout.php + handlers/order_handler.php القديمين
 */
class CheckoutController extends Controller
{
    // ════════════════════════════════════════════════════════
    // GET /checkout — عرض صفحة الدفع
    // ════════════════════════════════════════════════════════
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
            'extraScripts'=> '<script>window.CHECKOUT_IDEMPOTENCY_KEY = "' . $idempotencyKey . '";</script>',
            'addresses'   => $addresses,
            'csrf'        => $csrf,
            'returnPolicy'=> '14-day return policy on all products in original condition.',
            'userLoggedIn'=> true,
            'userName'    => $_SESSION['user_name'] ?? '',
        ]);
    }

    // ════════════════════════════════════════════════════════
    // POST /checkout — إنشاء الطلب
    // ════════════════════════════════════════════════════════
    public function placeOrder(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        // قراءة body JSON من cart.js (fetchWithCsrfRetry)
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $post = array_merge($_POST, $body);

        $token = $post['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            $this->respond(false, 'Invalid CSRF token.');
        }

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

        // تنظيف عناصر الطلب
        $cleanItems = [];
        foreach ($items as $item) {
            $variantId = (int)($item['variant_id'] ?? 0);
            $productId = (int)($item['product_id'] ?? 0);
            $qty       = max(1, (int)($item['qty']  ?? 1));
            $price     = (float)($item['price']     ?? 0);
            $color     = htmlspecialchars(trim($item['color_name'] ?? ''));

            if (!$productId || $price <= 0) continue;

            $cleanItems[] = [
                'variant_id' => $variantId ?: null,
                'product_id' => $productId,
                'qty'        => $qty,
                'price'      => $price,
                'color_name' => $color,
            ];
        }

        if (empty($cleanItems)) {
            $this->respond(false, 'Invalid items in cart.');
        }

        $orderId = OrderModel::placeOrder(
            $userId,
            $addressId,
            $cleanItems,
            $paymentMethod,
            $idempotencyKey
        );

        if (!$orderId) {
            $this->respond(false, 'Could not place order. Some items may be out of stock.');
        }

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
    public function cancelOrder(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $post = array_merge($_POST, $body);

        $token = $post['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            $this->respond(false, 'Invalid CSRF token.');
        }

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
            'orderId'     => $orderId,
            'userLoggedIn'=> true,
            'userName'    => $_SESSION['user_name'] ?? '',
        ]);
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
