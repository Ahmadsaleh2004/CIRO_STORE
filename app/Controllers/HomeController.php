<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ProductModel;
use App\Models\CategoryModel;
use App\Models\BrandingModel;
use OpenApi\Attributes as OA;

class HomeController extends Controller
{
    #[OA\Get(
        path: "/",
        summary: "Home page — slider, categories and visible products",
        description: "The /home route points at this same method.",
        tags: ["Store - Pages"],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]    #[OA\Get(
        path: '/home',
        summary: 'Alias route for the home page — the exact same method',
        tags: ['Store - Pages'],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function index(): void
    {
        // 1. Fetch every visible product from the model
        $products = ProductModel::findVisible();

        // Guard against an empty or not-yet-connected database so the page still renders
        if (empty($products)) {
            $productsJS = [];
        } else {
            // 2. Top 7 by sales → best-seller
            $bestSellerIds = array_slice(
                array_column($products, 'id'),
                0,
                7
            );

            // 3. Newest 7 by date added → new (excluding the best sellers)
            $productsSortedByDate = $products;
            usort($productsSortedByDate, function ($a, $b) {
                return strtotime($b['date_added'] ?? '2000-01-01') - strtotime($a['date_added'] ?? '2000-01-01');
            });

            $newArrivalIds = array_slice(
                array_values(array_filter(
                    array_column($productsSortedByDate, 'id'),
                    fn($id) => !in_array($id, $bestSellerIds)
                )),
                0,
                7
            );

            // 4. Tag resolver
            $getProductTag = function (array $p) use ($bestSellerIds, $newArrivalIds): string {
                if (in_array((int)($p['id'] ?? 0), $bestSellerIds)) {
                    return 'best-seller';
                }
                if (in_array((int)($p['id'] ?? 0), $newArrivalIds)) {
                    return 'new';
                }
                if ((int)($p['stock_quantity'] ?? 0) > 0 && (int)($p['stock_quantity'] ?? 0) <= 5) {
                    return 'limited';
                }
                return 'regular';
            };

            // 5. Build the array handed to JavaScript
            $productsJS = array_values(array_map(function ($p) use ($getProductTag) {
                $price = (float)($p['price'] ?? 0);
                $discount = (float)($p['discount_percentage'] ?? 0);
                $priceAfterDiscount = (float)($p['price_after_discount'] ?? 0);

                $finalPrice = $discount > 0 ? $priceAfterDiscount : $price;

                return [
                    'id'                  => (int)($p['id'] ?? 0),
                    'name'                => $p['name'] ?? '',
                    'price'               => $finalPrice,
                    'image'               => fixImagePath($p['image_path'] ?? ''),
                    'image_path'          => fixImagePath($p['image_path'] ?? ''),
                    'description'         => $p['description'] ?? '',
                    'brand'               => $p['manufacturer'] ?? '',
                    'tag'                 => $getProductTag($p),
                    'discount_percentage' => $discount,
                    'stock_quantity'      => (int)($p['stock_quantity'] ?? 0),
                    'categories'          => $p['categories'] ?? '',
                    'date_added'          => $p['date_added'] ?? null,
                ];
            }, $products));
        }

        // 6. Pass the data to the view
        $homeSliders = BrandingModel::getActiveSlidersForHome();

        $this->view('home', [
            'title'        => 'Home',
            'desc'         => 'Cairo Store — Best Electronics Store with Premium Products and Fast Delivery',
            'extraHead'    => '<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/home.css">
                               <link rel="stylesheet" href="' . URLROOT . '/css/store/pages/home-slider.css">',
            'productsJS'   => $productsJS,
            'homeSliders'  => $homeSliders,
            'categories'   => CategoryModel::getAllOrdered(),
            'userLoggedIn' => isUserLoggedIn(),
            'userName'     => $_SESSION['user_name'] ?? ''
        ]);
    }
}
