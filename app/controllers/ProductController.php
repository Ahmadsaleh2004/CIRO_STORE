<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Models\Product_dit;
use App\Models\CategoryModel;

class ProductController extends Controller
{
    public function index(): void
    {
        $perPage     = 9;
        $currentPage = max(1, (int)($_GET['page'] ?? 1));
        $totalCount  = Product_dit::countVisible();
        $totalPages  = max(1, (int)ceil($totalCount / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset      = ($currentPage - 1) * $perPage;

        $rows = Product_dit::findVisiblePaginated($perPage, $offset);

        $db = Database::connect();
        $visitorGender = function_exists('getVisitorGender') ? getVisitorGender($db) : null;

        // Fetch notified product IDs for current user (if logged in)
        $notifiedProductIds = [];
        if (function_exists('isUser') && isUser()) {
            $stmt = Database::connect()->prepare(
                "SELECT product_id FROM stock_notifications WHERE user_id = ?"
            );
            $stmt->execute([getCurrentUserId()]);
            $notifiedProductIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }

        $products = array_map(function (array $p) use ($visitorGender) {
            $variants = Product_dit::getVariants((int)$p['id']);
            $display  = (!empty($variants) && function_exists('pickDisplayVariant'))
                ? pickDisplayVariant($variants, $visitorGender)
                : null;

            // في حال عدم وجود Variants نبني _display من بيانات المنتج الأساسية
            $p['_display'] = $display ?? [
                'id'                    => null,
                'price'                 => $p['price'] ?? 0,
                'discount_percentage'   => $p['discount_percentage'] ?? 0,
                'price_after_discount'  => $p['price_after_discount'] ?? ($p['price'] ?? 0),
                'stock_quantity'        => $p['stock_quantity'] ?? 0,
                'image_path'            => $p['image_path'] ?? '',
                'color_name'            => null,
            ];

            return $p;
        }, $rows);

        $csrf = function_exists('generateCsrfToken') ? generateCsrfToken() : '';

        $this->view('product/product', [
            'title'        => 'Products',
            'desc'         => 'Browse all products at Cairo Store.',
            'activePage'   => 'products',
            'products'     => $products,
            'categories'   => CategoryModel::getAllOrdered(),
            'totalPages'   => $totalPages,
            'currentPage'  => $currentPage,
            'csrf'         => $csrf,
            'isAdminProd'  => function_exists('isAdmin') ? isAdmin() : false,
            'msg'          => $_GET['msg'] ?? '',
            'extraHead'    => '<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/products.css">',
            'extraScripts' => '<script src="' . URLROOT . '/js/features/notify-stock.js" defer></script>',
            'userLoggedIn' => isUserLoggedIn(),
            'userName'     => $_SESSION['user_name'] ?? '',
            'notifiedProductIds' => $notifiedProductIds,
        ]);
    }

    public function show(): void
    {
        $pid = (int)($_GET['id'] ?? 0);
        if (!$pid) {
            header('Location: ' . URLROOT);
            exit;
        }

        $p = Product_dit::findById($pid);

        // توجيه للرئيسية في حال عدم وجود المنتج لتجنب خطأ تحميل ملف 404 المفقود
        if (!$p) {
            header('Location: ' . URLROOT);
            exit;
        }

        // معالجة التقييمات (POST)
        $reviewMsg = $reviewErr = '';
        if (
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && isset($_POST['submit_review'])
            && function_exists('isUser') && isUser()
            && empty($_SESSION['admin_in_store_mode'] ?? false)
        ) {
            if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                $reviewErr = 'Invalid session, please refresh the page and try again.';
            } else {
                $rating  = (int)($_POST['rating'] ?? 0);
                $comment = trim($_POST['comment'] ?? '');
                $result  = Product_dit::saveReview($pid, getCurrentUserId(), $rating, $comment);
                if ($result['ok']) {
                    $reviewMsg = $result['message'];
                } else {
                    $reviewErr = $result['message'];
                }
            }
        }

        // جلب الـ Variants إن وجدت
        $variants = Product_dit::getVariants($pid);
        
        // تجهيز الـ Variant المعروض (إن وجد، وإلا نستخدم بيانات المنتج الأساسية)
        $db = Database::connect();
        $visitorGender   = function_exists('getVisitorGender') ? getVisitorGender($db) : 'M';
        $selectedVariant = (!empty($variants) && function_exists('pickDisplayVariant')) 
            ? pickDisplayVariant($variants, $visitorGender) 
            : ($variants[0] ?? []);

        if (function_exists('isUser') && isUser() && function_exists('updateUserActivity')) {
            updateUserActivity();
        }

        // معالجة التقييمات
        $reviews   = Product_dit::getReviews($pid);
        $avgRating = count($reviews) ? round(array_sum(array_column($reviews, 'rating')) / count($reviews), 1) : 0;

        $myReview = null;
        if (function_exists('isUser') && isUser() && function_exists('getCurrentUserId')) {
            $myReview = Product_dit::getUserReview($pid, getCurrentUserId());
        }

        // المنتجات المشابهة
        $related = Product_dit::getRelated($pid, $p['manufacturer'] ?? null);

        // الأسعار والمخزون مع المرونة (في حال عدم وجود الـ Variant يتم القراءة من المنتج الرئيسي مباشرة)
        $price      = (float)($selectedVariant['price'] ?? $p['price'] ?? 0);
        $discount   = (float)($selectedVariant['discount_percentage'] ?? $p['discount_percentage'] ?? 0);
        $afterDisc  = (float)($selectedVariant['price_after_discount'] ?? $p['price_after_discount'] ?? $price);
        $finalPrice = $discount > 0 ? $afterDisc : $price;
        $stock      = (int)($selectedVariant['stock_quantity'] ?? $p['stock_quantity'] ?? 0);
        $imgSrc     = fixImagePath($selectedVariant['image_path'] ?? $p['image_path'] ?? '');
        $csrf       = function_exists('generateCsrfToken') ? generateCsrfToken() : '';

        // Check if current user already requested notification for this product
        $alreadyRequested = false;
        if (function_exists('isUser') && isUser()) {
            $stmt = Database::connect()->prepare(
                "SELECT id FROM stock_notifications WHERE product_id = ? AND user_id = ? LIMIT 1"
            );
            $stmt->execute([$pid, getCurrentUserId()]);
            $alreadyRequested = (bool)$stmt->fetch();
        }

        $pageTitle       = $p['name'] ?? 'Product Details';
        $pageDescription = substr($p['description'] ?? '', 0, 155);
        $pageImage       = $imgSrc;

// تمرير البيانات للـ View عبر دالة $this->view في MVC
        $this->view('product/product_dit', [
            'title'           => $p['name'] ?? 'Product Details',
            'desc'            => substr($p['description'] ?? '', 0, 155),
            'pageImage'       => $imgSrc,
            'extraHead'       => '
<meta property="og:type" content="product">
<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/product-details.css">',
            'extraScripts'    => '<script src="' . URLROOT . '/js/features/product-details.js" defer></script><script src="' . URLROOT . '/js/features/notify-stock.js" defer></script>',
            'activePage'      => 'products',
            'p'               => $p,
            'variants'        => $variants,
            'selectedVariant' => $selectedVariant,
            'reviews'         => $reviews,
            'avgRating'       => $avgRating,
            'myReview'        => $myReview,
            'related'         => $related,
            'price'           => $price,
            'discount'        => $discount,
            'finalPrice'      => $finalPrice,
            'stock'           => $stock,
            'imgSrc'          => $imgSrc,
            'csrf'            => $csrf,
            'alreadyRequested' => $alreadyRequested,
            'reviewMsg'       => $reviewMsg,
            'reviewErr'       => $reviewErr,
            'userLoggedIn'    => isUserLoggedIn(),
            'userName'        => $_SESSION['user_name'] ?? ''
        ]);
    }
}