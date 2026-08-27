<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\AboutModel;
use App\Models\ProductModel;
use OpenApi\Attributes as OA;

/**
 * AboutController — يعالج صفحة /about
 * منقول من PageController::about() وموسَّع بـ AboutModel
 */
class AboutController extends Controller
{
    #[OA\Get(
        path: "/about",
        summary: "صفحة \"من نحن\" — بيانات المتجر الثابتة مع عدد المنتجات المرئية",
        tags: ["Store - Pages"],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]    public function about(): void
    {
        $model = new AboutModel();

        // بيانات المتجر الثابتة مُخزَّنة في AboutModel
        $storeInfo = $model->getStoreInfo();

        // عدد المنتجات المرئية من قاعدة البيانات
        $productsCount = ProductModel::countVisible();

        $this->view('page/about', [
            'title'         => 'About Us',
            'desc'          => 'Learn more about Cairo Store.',
            'activePage'    => 'about',
            'founded'       => $storeInfo['founded'],
            'location'      => $storeInfo['location'],
            'employees'     => $storeInfo['employees'],
            'phone'         => $storeInfo['phone'],
            'workingHours'  => $storeInfo['workingHours'],
            'productsCount' => $productsCount,
            'userLoggedIn'  => isUserLoggedIn(),
            'userName'      => $_SESSION['user_name'] ?? '',
        ]);
    }
}
