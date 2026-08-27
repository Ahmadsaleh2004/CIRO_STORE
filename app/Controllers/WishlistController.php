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
     * عرض صفحة الويش ليست (المحتوى نفسه بيتبني بالكامل من js/wishlist.js عن طريق localStorage)
     */
    #[OA\Get(
        path: '/wishlist',
        summary: 'صفحة قائمة الأمنيات',
        description: 'المحتوى يُبنى كاملاً في المتصفح من localStorage (js/features/wishlist.js)؛ '
                   . 'الخادم يُرجع الهيكل فقط.',
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
            'extraHead'     => '<link rel="stylesheet" href="' . URLROOT . '/css/store/pages/wishlist.css">',
            // بلا extraScripts: فوتر المتجر يحمّل js/features/wishlist.js على كل
            // صفحة أصلاً. تحميله هنا أيضاً كان يضع وسمين للملف نفسه، فيُنفَّذ
            // مرتين (266 سطراً تُحلَّل مرتين، ومعالج DOMContentLoaded يعمل مرتين).
            // لم يظهر عطل لأن العرض يستبدل innerHTML كاملاً، لكنه هدر خالص.
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
    #[OA\Get(
        path: '/handlers/product_stock_handler.php',
        summary: 'مخزون وأسعار مجموعة منتجات (لتحديث بطاقات قائمة الأمنيات)',
        description: 'نقطة عامة لا تتطلّب تسجيل دخول. الزائر غير المسجّل يحصل على '
                   . 'already_notified=false للجميع بدل تسريب حالة مستخدم آخر. '
                   . 'الحد الأقصى 200 معرّف في الطلب الواحد.',
        tags: ['Store - Wishlist'],
        parameters: [
            new OA\Parameter(
                name: 'ids',
                in: 'query',
                required: true,
                description: 'ids[]=1&ids[]=2 — حتى 200 معرّف',
                schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON — {success, message, products{}} مفهرسة بمعرّف المنتج، '
                           . 'وكل عنصر يحمل stock_quantity و price و is_visible و already_notified.'
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
    #[OA\Post(
        path: '/handlers/notify_handler.php',
        summary: 'تسجيل طلب "نبّهني عند التوفّر" لمنتج',
        description: 'يتطلّب تسجيل دخول مستخدم. الطلب المكرّر لا ينشئ صفاً جديداً ولا '
                   . 'يُشعِر الأدمنية مرة ثانية.',
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
                description: 'نتيجة العملية. الحقل success يفصل النجاح عن الفشل — كود HTTP يبقى 200 في الحالتين. وعند فشل CSRF يحمل الجسم error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
    )]
    public function notify(): void
    {
        // كانت هذه الدالة تكتب فحوصها الثلاثة بيدها بـhttp_response_code
        // و echo json_encode و exit. المشكلة لم تكن التكرار بل رسالة فشل
        // CSRF: 'Invalid session, please refresh the page.'
        //
        // js/core/csrf.js يكتشف فشل التوكن بـ
        //     message.startsWith('Invalid CSRF token')
        // ليجلب توكناً جديداً ويُعيد المحاولة مرة واحدة. الرسالة أعلاه لا
        // تبدأ بتلك البادئة، فكانت إعادة المحاولة **لا تُفعَّل أبداً** لزر
        // «نبّهني عند التوفّر» رغم أن notify-stock.js يستدعي
        // fetchWithCsrfRetry — أي أن المستخدم بتوكن منتهٍ كان يرى فشلاً
        // نهائياً حيث ترى بقية الأزرار تعافياً صامتاً.
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

        // add() تُرجع true فقط عند إضافة صفّ جديد فعلاً، فلا يُشعَر
        // الأدمنية مرتين لو ضغط المستخدم الزر مجدداً.
        if (StockNotificationModel::add($pid, $uid)) {
            StockNotifier::customerRequestedNotification($pid, $uid);
        }

        echo json_encode(['success' => true, 'message' => 'ok']);
        exit;
    }
}
