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
        summary: "الصفحة الرئيسية — السلايدر والأقسام والمنتجات المرئية",
        description: "الراوت /home يشير إلى نفس الدالة.",
        tags: ["Store - Pages"],
        responses: [new OA\Response(response: 200, description: "صفحة HTML")]
    )]    #[OA\Get(
        path: '/home',
        summary: 'مسار بديل للصفحة الرئيسية — نفس الدالة تماماً',
        tags: ['Store - Pages'],
        responses: [new OA\Response(response: 200, description: 'صفحة HTML')]
    )]
    public function index(): void
    {
        // 1. جلب كل المنتجات المرئية من الموديل
        $products = ProductModel::findVisible();

        // في حال كانت قاعدة البيانات فارغة أو غير متصلة بعد، نضمن عدم توقف الكود
        if (empty($products)) {
            $productsJS = [];
        } else {
            // 2. تحديد أعلى 7 بالمبيعات → best-seller
            $bestSellerIds = array_slice(
                array_column($products, 'id'),
                0,
                7
            );

            // 3. تحديد أحدث 7 بتاريخ الإضافة → new (مستثنيًا Best Sellers)
            $productsSortedByDate = $products;
            usort($productsSortedByDate, function($a, $b) {
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

            // 4. دالة تحديد التاغ
            $getProductTag = function(array $p) use ($bestSellerIds, $newArrivalIds): string {
                if (in_array((int)($p['id'] ?? 0), $bestSellerIds)) return 'best-seller';
                if (in_array((int)($p['id'] ?? 0), $newArrivalIds)) return 'new';
                if ((int)($p['stock_quantity'] ?? 0) > 0 && (int)($p['stock_quantity'] ?? 0) <= 5) return 'limited';
                return 'regular';
            };

            // 5. تجهيز مصفوفة الـ JS
            $productsJS = array_values(array_map(function($p) use ($getProductTag) {
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

        // 6. تمرير البيانات إلى الـ View
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