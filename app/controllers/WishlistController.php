<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Models\Product_dit;
use App\Models\AdminModel;

class WishlistController extends Controller
{
    /**
     * عرض صفحة الويش ليست (المحتوى نفسه بيتبني بالكامل من js/wishlist.js عن طريق localStorage)
     */
    public function index(): void
    {
        $this->view('page/wishlist', [
            'title'         => 'My Wishlist',
            'desc'          => 'Your saved products at Cairo Store.',
            'activePage'    => 'wishlist',
            'extraHead'     => '<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/wishlist.css">',
            'extraScripts'  => '<script src="' . URLROOT . '/js/features/wishlist.js" defer></script>',
            'isRegularUser' => isUser(),
            'csrf'          => generateCsrfToken(),
            'userLoggedIn'  => isUserLoggedIn(),
            'userName'      => $_SESSION['user_name'] ?? ''
        ]);
    }

    /**
     * GET /handlers/product_stock_handler.php?ids[]=1&ids[]=2
     * يرجّع بيانات المخزون/السعر الحيّة بصيغة JSON.
     * سايب نفس المسار القديم بالظبط عشان js/wishlist.js يشتغل من غير أي تعديل عليه.
     */
    public function stock(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
            exit;
        }

        $ids = $_GET['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            echo json_encode(['success' => false, 'message' => 'No product IDs provided.']);
            exit;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        $ids = array_slice($ids, 0, 200);

        if (empty($ids)) {
            echo json_encode(['success' => false, 'message' => 'No valid product IDs provided.']);
            exit;
        }

        $products = Product_dit::findStockByIds($ids);

        // حالة "نبّهني لما يتوفر" لكل منتج — تُحسب هنا وليس في findStockByIds
        // لأنها تعتمد على المستخدم الحالي بينما الموديل عام ومستخدَم في أماكن أخرى.
        // نفس منطق $notifiedProductIds في ProductController::index().
        // ملاحظة: هذا الـ endpoint عام (GET بلا تسجيل دخول) — لذلك الزائر غير
        // المسجّل يحصل على false للجميع بدل تسريب حالة مستخدم آخر.
        $notifiedIds = [];
        if (isUser() && ($uid = getCurrentUserId())) {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = Database::connect()->prepare(
                    "SELECT product_id FROM stock_notifications
                     WHERE user_id = ? AND product_id IN ({$placeholders})"
                );
                $stmt->execute(array_merge([$uid], $ids));
                $notifiedIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            } catch (\Throwable $e) {
                // الفشل هنا لا يجب أن يُسقط بيانات المخزون — نكمل بحالة "غير مُطلَب"
                error_log('WishlistController::stock notify-state Error: ' . $e->getMessage());
            }
        }

        foreach ($products as $pid => $row) {
            $products[$pid]['already_notified'] = in_array((int)$pid, $notifiedIds, true);
        }

        echo json_encode(['success' => true, 'message' => 'ok', 'products' => $products]);
        exit;
    }

    /**
     * POST /handlers/notify_handler.php
     * "نبّهني لما يتوفر" — بيخزّن الطلب في جدول stock_notifications
     * يرجع JSON للأجاكس
     */
    public function notify(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
            exit;
        }

        if (!isUser()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Please log in first.']);
            exit;
        }

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid session, please refresh the page.']);
            exit;
        }

        $pid = (int)($_POST['product_id'] ?? 0);
        $uid = getCurrentUserId();

        if (!$pid) {
            echo json_encode(['success' => false, 'message' => 'Invalid product.']);
            exit;
        }

        $db = Database::connect();
        $exists = $db->prepare("SELECT id FROM stock_notifications WHERE product_id = ? AND user_id = ? LIMIT 1");
        $exists->execute([$pid, $uid]);

        if (!$exists->fetch()) {
            $db->prepare("INSERT INTO stock_notifications (product_id, user_id) VALUES (?, ?)")
               ->execute([$pid, $uid]);

            // إشعار الأدمنية المخوّلين
            $this->notifyAdminsAboutStockRequest($pid, $uid);
        }

        echo json_encode(['success' => true, 'message' => 'ok']);
        exit;
    }

    /**
     * يُرسل إشعارًا لكل أدمن لديه صلاحية can_manage_products، ما عدا الأدمن الأساسي (Role A).
     */
    private function notifyAdminsAboutStockRequest(int $productId, int $requestingUserId): void
    {
        $db = Database::connect();

        $prodStmt = $db->prepare("SELECT name FROM products WHERE id = ? LIMIT 1");
        $prodStmt->execute([$productId]);
        $prodName = $prodStmt->fetchColumn() ?: "Product #{$productId}";

        $userStmt = $db->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$requestingUserId]);
        $userName = $userStmt->fetchColumn() ?: 'A customer';

        $countStmt = $db->prepare("SELECT COUNT(*) FROM stock_notifications WHERE product_id = ?");
        $countStmt->execute([$productId]);
        $requestCount = (int)$countStmt->fetchColumn();

        $message = "{$userName} requested to be notified when this product is back in stock ({$requestCount})";

        // كل الأدمنية (B/C/D) الذين لديهم صلاحية can_manage_products — Role A مستثنى
        $adminIds = AdminModel::findByPermsAndRanks(['can_manage_products'], ['B', 'C', 'D']);

        foreach ($adminIds as $adminId) {
            AdminModel::sendNotification(
                (int)$adminId,
                $prodName,
                $message,
                'stock_notify_request',
                'product',
                $productId
            );
        }
    }
}