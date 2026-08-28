<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\BrandingModel;
use App\Models\AdminModel;
use App\Services\SliderFormParser;
use OpenApi\Attributes as OA;

/**
 * AdminBrandingController — سلايدر الصفحة الرئيسية.
 *
 * ⚠️ حدود الشرائح والصور (MAX_SLIDES / MAX_ITEMS_PER_SLIDE) انتقلت إلى
 * SliderFormParser مع التحقق الذي يستعملها. بقاؤها هنا كان سيعني رقمين
 * في موضعين — وأسوأ ما فيه أن أحدهما يتغيّر وحده فيصير الحدّ المُعلَن
 * غير الحدّ المُطبَّق.
 */
class AdminBrandingController extends AdminController
{
    #[OA\Get(
        path: '/admin/branding',
        summary: 'عرض صفحة إدارة السلايدر',
        tags: ['Admin - Branding'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 302, ref: '#/components/responses/RedirectToLogin'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function index(): void
    {
        Middleware::requirePermission('can_manage_branding');

        $flashMsg = $_SESSION['flash_msg'] ?? '';
        $flashErr = $_SESSION['flash_err'] ?? '';
        unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

        $this->adminView('branding/index', [
            'pageTitle' => 'Manage Slider',
            'sliders'   => BrandingModel::getFullSliderData(),
            'flashMsg'  => $flashMsg,
            'flashErr'  => $flashErr,
            'extraHead' => '<link rel="stylesheet" href="' . URLROOT . '/css/admin/pages/branding.css">',
        ]);
    }

    #[OA\Get(
        path: '/admin/branding/products/search',
        summary: 'بحث حي عن منتج لاختياره بالسلايدر (AJAX)',
        tags: ['Admin - Branding'],
        security: [['adminSessionAuth' => []]],
        parameters: [new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'نتيجة العملية. الحقل success يفصل النجاح عن الفشل — كود HTTP يبقى 200 في الحالتين. وعند فشل CSRF يحمل الجسم error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
            new OA\Response(response: 401, ref: '#/components/responses/SessionExpired'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
        ]
    )]
    public function searchProducts(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requirePermission('can_manage_branding');

        $q = trim($_GET['q'] ?? '');
        $products = BrandingModel::searchProducts($q);

        // إنذار كاذب: القاعدة تلاحق «صدى مدخل الطلب»، والمطبوع هنا ناتج
        // json_encode لصفوف من القاعدة تحت رأس application/json المضبوط
        // أعلاه. لا $_GET يصل إلى المخرَج، وjson_encode تهرّب ما تُخرجه.
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
        echo json_encode(['success' => true, 'products' => $products], JSON_UNESCAPED_UNICODE);
        exit;
    }

    #[OA\Post(
        path: '/admin/branding/save',
        summary: 'حفظ كامل السلايدر (Full Replace) — شرائح + عناصر + رفع صور',
        tags: ['Admin - Branding'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(mediaType: 'multipart/form-data')]
        ),
        responses: [
            new OA\Response(response: 302, ref: '#/components/responses/RedirectWithFlash'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
        ]
    )]
    public function save(): void
    {
        Middleware::requirePermission('can_manage_branding');

        // استُثني من beginJsonPost: يفشل بـredirectWithError لا بـJSON.
        // هذه صفحة فورم لا نقطة API — التحويل يقلبها إلى استجابة JSON.
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->redirectWithError('Invalid CSRF token, please refresh and try again.');
            return;
        }
        $oldImagePaths = BrandingModel::collectAllImagePaths();
        $uploadDir     = ROOTPATH . '/public/images/';

        // التحضير كلّه في الخدمة: قراءة $_FILES المتداخلة، والتحقق،
        // والرفع. كان هنا 130 سطراً تخلط ذلك بتنسيق الاستجابة، فكان كل
        // مسار خطأ ينتهي بـheader() وexit — أي غير قابل للاختبار إطلاقاً.
        $parsed = SliderFormParser::parse($_POST['slides'] ?? [], $_FILES['slides'] ?? [], $uploadDir);

        // التنظيف مرّة واحدة عند أي فشل. كان cleanupNewUploads مكرَّراً
        // في خمسة مواضع، ونسيانه في السادس يعني صوراً يتيمة على القرص.
        if ($parsed['error'] !== null) {
            $this->cleanupNewUploads($parsed['uploaded'], $uploadDir);
            $this->redirectWithError($parsed['error']);
            return;
        }

        $adminId = getCurrentAdminId();

        if (!BrandingModel::saveAll($parsed['slides'], $adminId)) {
            $this->cleanupNewUploads($parsed['uploaded'], $uploadDir);
            $this->redirectWithError('Failed to save the slider. Please try again.');
            return;
        }

        $this->deleteOrphanedImages($oldImagePaths, $parsed['images'], $uploadDir);

        AdminModel::logAction($adminId, 'update_branding_slider', 'branding', 0, 'Saved home slider content.');
        $this->notifyAboutBrandingChange($adminId);

        $_SESSION['flash_msg'] = '✅ Slider saved successfully.';
        header('Location: ' . URLROOT . '/admin/branding');
        exit;
    }

    /**
     * يحذف الصور التي كانت مستعملة ولم تعد ضمن الحفظة الجديدة.
     */
    private function deleteOrphanedImages(array $oldPaths, array $keptPaths, string $uploadDir): void
    {
        foreach (array_diff($oldPaths, $keptPaths) as $orphanPath) {
            // basename() تجرّد أي مسار من المدخل فلا يبقى منه إلا اسم
            // الملف — لا `..` ولا شرطات ولا مسار مطلق. المسار الناتج
            // محصور في $uploadDir بالبناء. semgrep يرى تدفّق بيانات من
            // قاعدة البيانات إلى unlink ولا يرى أثر basename.
            $disk = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($orphanPath);
            if (file_exists($disk)) {
                // ⚠️ الكتم على سطر @unlink نفسه. كان موضوعاً فوق `if`،
                // وsemgrep يربط التعليق بالسطر التالي له مباشرةً — فكان
                // يكتم شرط `file_exists` ولا يكتم شيئاً، والنتيجة تُبلَّغ
                // كما لو لم يكن هناك تعليق أصلاً. (مقيس: النتيجة كانت
                // تظهر قبل إعادة الهيكلة وبعدها بالتساوي.)
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                @unlink($disk);
            }
        }
    }

    // ══════════════════════════════════════════════════════════
    // Helpers خاصة
    // ══════════════════════════════════════════════════════════

    private function cleanupNewUploads(array $paths, string $uploadDir): void
    {
        foreach ($paths as $p) {
            // كسابقتها: basename تحصر الاسم داخل $uploadDir بالبناء.
            $disk = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($p);
            if (file_exists($disk)) {
                // الكتم على سطر @unlink نفسه — راجع الشرح في
                // deleteOrphanedImages أعلاه.
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                @unlink($disk);
            }
        }
    }
    private function redirectWithError(string $msg): void
    {
        $_SESSION['flash_err'] = $msg;
        header('Location: ' . URLROOT . '/admin/branding');
        exit;
    }

    /**
     * إشعارات عند تعديل السلايدر:
     * — إشعار تأكيد للمُعدِّل نفسه (سجل)
     * — إشعار لكل أدمن يملك صلاحية can_manage_branding برتبة أعلى من رتبة المُعدِّل،
     *   باستثناء الأدمن الأساسي (getRootAdminId) والمُعدِّل.
     */
    private function notifyAboutBrandingChange(int $adminId): void
    {
        AdminModel::notifyHigherRanksOnAction(
            actorAdminId:  $adminId,
            permission:    'can_manage_branding',
            title:         'Slider Updated',
            selfMessage:   'You saved the home slider content.',
            othersMessage: 'An admin updated the home slider content.',
            type:          'branding_updated',
            relatedType:   'branding',
            relatedId:     0
        );
    }
}
