<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\DatabaseUnavailable;
use App\Models\AboutModel;
use App\Models\ProductModel;
use OpenApi\Attributes as OA;

/**
 * AboutController — serves the /about page.
 * Moved out of PageController::about() and extended with AboutModel.
 */
class AboutController extends Controller
{
    #[OA\Get(
        path: "/about",
        summary: "About page — the store's static details plus the visible product count",
        tags: ["Store - Pages"],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]    public function about(): void
    {
        $model = new AboutModel();

        // The store's static details live in AboutModel
        $storeInfo = $model->getStoreInfo();

        // Visible product count, read from the database.
        //
        // The only thing on this page that needs the database — so when the database is
        // unreachable the page renders without the number instead of answering 503 for the
        // sake of one statistic. The view leaves the tile out when the count is null.
        try {
            $productsCount = ProductModel::countVisible();
        } catch (DatabaseUnavailable $e) {
            error_log('[Cairo Store] about: product count skipped — ' . $e->getMessage());
            $productsCount = null;
        }

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
