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
        summary: 'Visible product list with pagination',
        description: 'Search, sorting and price filtering all happen in the browser '
                   . '(js/features/products-catalog.js), so there are no filter parameters here.',
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

            // With no variants, _display is built from the base product row
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
            'extraHead'    => pageCss('store/pages/products.css'),
            'extraScripts' => jsTag('js/features/notify-stock.js'),
            'userLoggedIn' => isUserLoggedIn(),
            'userName'     => $_SESSION['user_name'] ?? '',
            'notifiedProductIds' => $notifiedProductIds,
        ]);
    }

    #[OA\Get(
        path: '/product',
        summary: 'Product details page',
        description: 'Redirects home when the id is missing or the product does not exist. '
                   . "The displayed variant is chosen from the visitor's gender (pickDisplayVariant).",
        tags: ['Store - Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'HTML page'),
            new OA\Response(response: 302, description: 'Redirect home — missing id or unknown product'),
        ]
    )]
    #[OA\Post(
        path: '/product',
        summary: "Save the user's product review, then render the page",
        description: 'The same method serves GET and POST. Requires a logged-in user and a CSRF token. '
                   . 'An admin in store-browsing mode cannot leave a review.',
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

        // Redirect home when the product is missing, to avoid loading a 404 page that does not exist
        if (!$p) {
            header('Location: ' . URLROOT);
            exit;
        }

        // Review handling (POST)
        $reviewMsg = $reviewErr = '';
        if (
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && isset($_POST['submit_review'])
            && isUser()
            && empty($_SESSION['admin_in_store_mode'] ?? false)
        ) {
            // Deliberately not using beginJsonPost: this never aborts — it puts the
            // text in $reviewErr and carries on rendering the product page. The same
            // method serves GET and POST.
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

        // Fetch the variants, if any
        $variants = ProductModel::getVariants($pid);

        // Resolve the variant to display (falling back to the base product row)
        $visitorGender   = getVisitorGender();
        // The second branch used to be `$variants[0] ?? []` — which is unreachable:
        // we only get here when $variants is empty, so there is never an index 0.
        $selectedVariant = !empty($variants)
            ? pickDisplayVariant($variants, $visitorGender)
            : [];

        // Review handling
        $reviews   = ProductModel::getReviews($pid);
        $avgRating = count($reviews) ? round(array_sum(array_column($reviews, 'rating')) / count($reviews), 1) : 0;

        $myReview = null;
        if (isUser()) {
            $myReview = ProductModel::getUserReview($pid, getCurrentUserId());
        }

        // Related products
        $related = ProductModel::getRelated($pid, $p['manufacturer'] ?? null);

        // Pricing and stock, with a fallback: with no variant these read straight off the base product
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

// Hand the data to the view through $this->view
        $this->view('product/product_dit', [
            'title'           => $p['name'] ?? 'Product Details',
            'desc'            => substr($p['description'] ?? '', 0, 155),
            'pageImage'       => $imgSrc,
            'extraHead'       => '<meta property="og:type" content="product">' . "\n"
                                 . pageCss('store/pages/product-details.css'),
            'extraScripts'    => jsTag('js/features/product-details.js')
                                 . jsTag('js/features/notify-stock.js'),
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
