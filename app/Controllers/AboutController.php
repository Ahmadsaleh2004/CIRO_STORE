<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\AboutModel;
use App\Models\ProductModel;

/**
 * AboutController — يعالج صفحة /about
 * منقول من PageController::about() وموسَّع بـ AboutModel
 */
class AboutController extends Controller
{
    public function about(): void
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
