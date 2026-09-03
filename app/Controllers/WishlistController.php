<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ProductModel;
use App\Models\StockNotificationModel;
use App\Services\StockNotifier;
use OpenApi\Attributes as OA;

class WishlistController extends Controller
{
    /**
     * Render the wishlist page (its contents are built entirely by js/wishlist.js from localStorage).
     */
    #[OA\Get(
        path: '/wishlist',
        summary: 'Wishlist page',
        description: 'The contents are built entirely in the browser from localStorage '
                   . '(js/features/wishlist.js); the server returns only the shell.',
        tags: ['Store - Wishlist'],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function index(): void
    {
        $this->view('page/wishlist', [
            'title'         => 'My Wishlist',
            'desc'          => 'Your saved products at Cairo Store.',
            'activePage'    => 'wishlist',
            'extraHead'     => pageCss('store/pages/wishlist.css'),
            // No extraScripts: the store footer already loads js/features/wishlist.js on
            // every page. Loading it here too emitted two tags for the same file, so it
            // ran twice (266 lines parsed twice, and the DOMContentLoaded handler firing
            // twice). No fault surfaced, because rendering replaces innerHTML wholesale,
            // but it was pure waste.
            'isRegularUser' => isUser(),
            'csrf'          => generateCsrfToken(),
            'userLoggedIn'  => isUserLoggedIn(),
            'userName'      => $_SESSION['user_name'] ?? ''
        ]);
    }

    /**
     * GET /handlers/product_stock_handler.php?ids[]=1&ids[]=2
     * Returns live stock and price data as JSON.
     * The old path is kept exactly as it was so js/wishlist.js works untouched.
     */
    #[OA\Get(
        path: '/handlers/product_stock_handler.php',
        summary: 'Stock and pricing for a set of products (to refresh wishlist cards)',
        description: 'A public endpoint requiring no login. A signed-out visitor gets '
                   . 'already_notified=false for everything, rather than leaking another '
                   . "user's state. At most 200 ids per request.",
        tags: ['Store - Wishlist'],
        parameters: [
            new OA\Parameter(
                name: 'ids',
                in: 'query',
                required: true,
                description: 'ids[]=1&ids[]=2 — up to 200 ids',
                schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON — {success, message, products{}} keyed by product id, each entry '
                           . 'carrying stock_quantity, price, is_visible and already_notified.'
            ),
        ]
    )]
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

        // The "notify me when available" flag per product — computed here rather than
        // in findStockByIds, because it depends on the current user while the model is
        // generic and used elsewhere. Same logic as $notifiedProductIds in
        // ProductController::index().
        // Note: this endpoint is public (a GET with no login), so a signed-out visitor
        // gets false for everything rather than leaking another user's state.
        // The model swallows any failure and returns an empty array, so the stock data
        // still reaches the client even if the "notify me" lookup fails.
        $notifiedIds = (isUser() && ($uid = getCurrentUserId()))
            ? StockNotificationModel::productIdsForUserWithin($uid, $ids)
            : [];

        foreach ($products as $pid => $row) {
            $products[$pid]['already_notified'] = in_array((int)$pid, $notifiedIds, true);
        }

        // False positive: what is printed is product rows from the database, passed
        // through json_encode under an application/json header. The ids that come from
        // the request are used for the query alone, and none of them reaches the output
        // raw.
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
        echo json_encode(['success' => true, 'message' => 'ok', 'products' => $products]);
        exit;
    }

    /**
     * POST /handlers/notify_handler.php
     * "Notify me when available" — records the request in the stock_notifications
     * table and returns JSON for the AJAX caller.
     */
    #[OA\Post(
        path: '/handlers/notify_handler.php',
        summary: 'Register a "notify me when available" request for a product',
        description: 'Requires a logged-in user. A repeat request creates no new row and does '
                   . 'not notify the admins a second time.',
        tags: ['Store - Wishlist'],
        security: [['userSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['product_id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'product_id', type: 'integer'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
    )]
    public function notify(): void
    {
        // This method used to write its three checks by hand with
        // http_response_code, echo json_encode and exit. The problem was not the
        // duplication but the CSRF failure message:
        // 'Invalid session, please refresh the page.'
        //
        // js/core/csrf.js detects a token failure with
        //     message.startsWith('Invalid CSRF token')
        // in order to fetch a fresh token and retry exactly once. The message above
        // does not start with that prefix, so the retry **never fired at all** for
        // the "notify me when available" button, even though notify-stock.js calls
        // fetchWithCsrfRetry — meaning a user with an expired token saw an outright
        // failure where every other button recovered silently.
        $this->beginJsonPost();

        if (!isUser()) {
            $this->respond(false, 'Please log in first.');
        }

        $pid = (int)($_POST['product_id'] ?? 0);
        $uid = getCurrentUserId();

        if (!$pid) {
            echo json_encode(['success' => false, 'message' => 'Invalid product.']);
            exit;
        }

        // add() returns true only when a row is genuinely inserted, so the admins are
        // not notified twice if the user presses the button again.
        if (StockNotificationModel::add($pid, $uid)) {
            StockNotifier::customerRequestedNotification($pid, $uid);
        }

        echo json_encode(['success' => true, 'message' => 'ok']);
        exit;
    }
}
