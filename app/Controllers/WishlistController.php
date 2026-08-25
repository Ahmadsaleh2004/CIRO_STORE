<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ProductModel;
use App\Models\StockNotificationModel;
use App\Services\StockNotifier;

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

        $products = ProductModel::findStockByIds($ids);

        // حالة "نبّهني لما يتوفر" لكل منتج — تُحسب هنا وليس في findStockByIds
        // لأنها تعتمد على المستخدم الحالي بينما الموديل عام ومستخدَم في أماكن أخرى.
        // نفس منطق $notifiedProductIds في ProductController::index().
        // ملاحظة: هذا الـ endpoint عام (GET بلا تسجيل دخول) — لذلك الزائر غير
        // المسجّل يحصل على false للجميع بدل تسريب حالة مستخدم آخر.
        // الموديل يبتلع أي فشل ويُرجع مصفوفة فارغة، فبيانات المخزون
        // تصل للعميل حتى لو تعذّر جلب حالة "نبّهني".
        $notifiedIds = (isUser() && ($uid = getCurrentUserId()))
            ? StockNotificationModel::productIdsForUserWithin($uid, $ids)
            : [];

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

        // add() تُرجع true فقط عند إضافة صفّ جديد فعلاً، فلا يُشعَر
        // الأدمنية مرتين لو ضغط المستخدم الزر مجدداً.
        if (StockNotificationModel::add($pid, $uid)) {
            StockNotifier::customerRequestedNotification($pid, $uid);
        }

        echo json_encode(['success' => true, 'message' => 'ok']);
        exit;
    }

}