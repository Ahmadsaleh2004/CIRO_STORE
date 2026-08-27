<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ProductModel;
use App\Models\CategoryModel;
use App\Models\StockNotificationModel;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    #[OA\Get(
        path: '/products',
        summary: 'قائمة المنتجات المرئية مع Pagination',
        description: 'البحث والفرز والفلترة بالسعر تتم كلها في المتصفح '
                   . '(js/features/products-catalog.js)، فلا بارامترات فلترة هنا.',
        tags: ['Store - Products'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function index(): void
    {
        $perPage     = 9;
        $currentPage = max(1, (int)($_GET['page'] ?? 1));
        $totalCount  = ProductModel::countVisible();
        $totalPages  = max(1, (int)ceil($totalCount / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset      = ($currentPage - 1) * $perPage;

        $rows = ProductModel::findVisiblePaginated($perPage, $offset);
        $visitorGender = getVisitorGender();

        // Fetch notified product IDs for current user (if logged in)
        $notifiedProductIds = isUser()
            ? StockNotificationModel::productIdsForUser(getCurrentUserId())
            : [];

        $products = array_map(function (array $p) use ($visitorGender) {
            $variants = ProductModel::getVariants((int)$p['id']);
            $display  = !empty($variants)
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

        $csrf = generateCsrfToken();

        $this->view('product/product', [
            'title'        => 'Products',
            'desc'         => 'Browse all products at Cairo Store.',
            'activePage'   => 'products',
            'products'     => $products,
            'categories'   => CategoryModel::getAllOrdered(),
            'totalPages'   => $totalPages,
            'currentPage'  => $currentPage,
            'csrf'         => $csrf,
            'isAdminProd'  => isAdmin(),
            'msg'          => $_GET['msg'] ?? '',
            'extraHead'    => '<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/products.css">',
            'extraScripts' => '<script src="' . URLROOT . '/js/features/notify-stock.js" defer></script>',
            'userLoggedIn' => isUserLoggedIn(),
            'userName'     => $_SESSION['user_name'] ?? '',
            'notifiedProductIds' => $notifiedProductIds,
        ]);
    }

    #[OA\Get(
        path: '/product',
        summary: 'صفحة تفاصيل منتج',
        description: 'تُعيد التوجيه للرئيسية إن كان المعرّف مفقوداً أو المنتج غير موجود. '
                   . 'الـvariant المعروض يُختار حسب جنس الزائر (pickDisplayVariant).',
        tags: ['Store - Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML'),
            new OA\Response(response: 302, description: 'تحويل للرئيسية — معرّف مفقود أو منتج غير موجود'),
        ]
    )]
    #[OA\Post(
        path: '/product',
        summary: 'حفظ تقييم المستخدم للمنتج ثم عرض الصفحة',
        description: 'نفس الدالة تخدم GET وPOST. يتطلّب تسجيل دخول مستخدم وتوكن CSRF. '
                   . 'الأدمن في وضع تصفّح المتجر لا يستطيع التقييم.',
        tags: ['Store - Products'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['submit_review', 'rating', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'submit_review', type: 'string'),
                        new OA\Property(property: 'rating', type: 'integer', maximum: 5, minimum: 1),
                        new OA\Property(property: 'comment', type: 'string'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function show(): void
    {
        $pid = (int)($_GET['id'] ?? 0);
        if (!$pid) {
            header('Location: ' . URLROOT);
            exit;
        }

        $p = ProductModel::findById($pid);

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
            && isUser()
            && empty($_SESSION['admin_in_store_mode'] ?? false)
        ) {
            // استُثني من beginJsonPost: لا يفشل أصلاً — يضع النص في
            // $reviewErr ويُكمل عرض صفحة المنتج. تخدم GET وPOST معاً.
            if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                $reviewErr = 'Invalid session, please refresh the page and try again.';
            } else {
                $rating  = (int)($_POST['rating'] ?? 0);
                $comment = trim($_POST['comment'] ?? '');
                $result  = ProductModel::saveReview($pid, getCurrentUserId(), $rating, $comment);
                if ($result['ok']) {
                    $reviewMsg = $result['message'];
                } else {
                    $reviewErr = $result['message'];
                }
            }
        }

        // جلب الـ Variants إن وجدت
        $variants = ProductModel::getVariants($pid);

        // تجهيز الـ Variant المعروض (إن وجد، وإلا نستخدم بيانات المنتج الأساسية)
        $visitorGender   = getVisitorGender();
        // الفرع الثاني كان `$variants[0] ?? []` — وهو مستحيل: نصل إليه
        // فقط حين تكون $variants فارغة، فلا عنصر صفر فيها أبداً.
        $selectedVariant = !empty($variants)
            ? pickDisplayVariant($variants, $visitorGender)
            : [];

        // معالجة التقييمات
        $reviews   = ProductModel::getReviews($pid);
        $avgRating = count($reviews) ? round(array_sum(array_column($reviews, 'rating')) / count($reviews), 1) : 0;

        $myReview = null;
        if (isUser()) {
            $myReview = ProductModel::getUserReview($pid, getCurrentUserId());
        }

        // المنتجات المشابهة
        $related = ProductModel::getRelated($pid, $p['manufacturer'] ?? null);

        // الأسعار والمخزون مع المرونة (في حال عدم وجود الـ Variant يتم القراءة من المنتج الرئيسي مباشرة)
        $price      = (float)($selectedVariant['price'] ?? $p['price'] ?? 0);
        $discount   = (float)($selectedVariant['discount_percentage'] ?? $p['discount_percentage'] ?? 0);
        $afterDisc  = (float)($selectedVariant['price_after_discount'] ?? $p['price_after_discount'] ?? $price);
        $finalPrice = $discount > 0 ? $afterDisc : $price;
        $stock      = (int)($selectedVariant['stock_quantity'] ?? $p['stock_quantity'] ?? 0);
        $imgSrc     = fixImagePath($selectedVariant['image_path'] ?? $p['image_path'] ?? '');
        $csrf       = generateCsrfToken();

        // Check if current user already requested notification for this product
        $alreadyRequested = isUser()
            && StockNotificationModel::exists($pid, getCurrentUserId());

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
