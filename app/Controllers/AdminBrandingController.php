<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\BrandingModel;
use App\Models\AdminModel;
use OpenApi\Attributes as OA;

class AdminBrandingController extends AdminController
{
    private const MAX_SLIDES           = 12;  // حد أقصى مقترح — راجع 00 (اقتراح 6)
    private const MAX_ITEMS_PER_SLIDE  = 10;

    #[OA\Get(
        path: '/admin/branding',
        summary: 'عرض صفحة إدارة السلايدر',
        tags: ['Admin - Branding'],
        security: [['adminSessionAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'صفحة HTML')]
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
        responses: [new OA\Response(response: 200, description: 'JSON: {success, products}')]
    )]
    public function searchProducts(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requirePermission('can_manage_branding');

        $q = trim($_GET['q'] ?? '');
        $products = BrandingModel::searchProducts($q);

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
        responses: [new OA\Response(response: 302, description: 'إعادة توجيه مع flash message')]
    )]
    public function save(): void
    {
        Middleware::requirePermission('can_manage_branding');

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->redirectWithError('Invalid CSRF token, please refresh and try again.');
            return;
        }

        $rawSlides   = $_POST['slides'] ?? [];
        $filesSlides = $_FILES['slides'] ?? [];

        if (empty($rawSlides)) {
            $this->redirectWithError('Please add at least one slide before saving.');
            return;
        }
        if (count($rawSlides) > self::MAX_SLIDES) {
            $this->redirectWithError('Too many slides (max ' . self::MAX_SLIDES . ').');
            return;
        }

        $oldImagePaths = BrandingModel::collectAllImagePaths();
        $uploadDir     = ROOTPATH . '/public/images/';
        $newImagePaths = [];   // فقط الملفات المرفوعة حديثاً — للتنظيف عند خطأ التحقق
        $savedImagePaths = []; // كل مسارات الصور بعد المعالجة — لمقارنة اليتيمة

        $preparedSlides = [];

        foreach ($rawSlides as $slideIndex => $slideData) {
            $rawItems = $slideData['items'] ?? [];
            if (empty($rawItems)) {
                continue; // شريحة بلا صور أصلاً = تجاهلها بصمت
            }
            if (count($rawItems) > self::MAX_ITEMS_PER_SLIDE) {
                $this->redirectWithError('A slide has too many images (max ' . self::MAX_ITEMS_PER_SLIDE . ').');
                return;
            }

            $preparedItems = [];

            foreach ($rawItems as $itemIndex => $itemData) {
                $activeMode = ($itemData['active_mode'] ?? 'manual') === 'product' ? 'product' : 'manual';

                $productId          = (int)($itemData['product_id'] ?? 0) ?: null;
                $productLinkUrl     = trim($itemData['product_link_url'] ?? '') ?: null;
                $productDescription = trim($itemData['product_description'] ?? '') ?: null;

                $manualLinkUrl     = trim($itemData['manual_link_url'] ?? '') ?: null;
                $manualDescription = trim($itemData['manual_description'] ?? '') ?: null;

                if ($this->isUnsafeUrl($productLinkUrl) || $this->isUnsafeUrl($manualLinkUrl)) {
                    $this->cleanupNewUploads($newImagePaths, $uploadDir);
                    $this->redirectWithError('Unsafe link URL (javascript:/data:/vbscript: are not allowed).');
                    return;
                }

                // الصورة اليدوية: ملف جديد له أولوية، وإلا نحتفظ بالمسار القديم المُرسَل مخفياً
                $manualImagePath = trim($itemData['existing_manual_image'] ?? '') ?: null;

                $fileEntry = $this->extractFileEntry($filesSlides, $slideIndex, $itemIndex);
                if ($fileEntry) {
                    $uploaded = BrandingModel::uploadSliderImage($fileEntry, $uploadDir);
                    if ($uploaded) {
                        $manualImagePath   = $uploaded;
                        $newImagePaths[]   = $uploaded; // رفوعات جديدة فقط — لا تُحذف أبداً صور قديمة موجودة
                    }
                }

                // ── إلزامية: منتج مُختار أو صورة موجودة (على الأقل للوضع الفعّال) ──
                if ($activeMode === 'product' && !$productId) {
                    $this->cleanupNewUploads($newImagePaths, $uploadDir);
                    $this->redirectWithError('Each slide image must have a product selected or an uploaded image.');
                    return;
                }
                if ($activeMode === 'manual' && !$manualImagePath) {
                    $this->cleanupNewUploads($newImagePaths, $uploadDir);
                    $this->redirectWithError('Each slide image must have a product selected or an uploaded image.');
                    return;
                }

                if ($manualImagePath) {
                    $savedImagePaths[] = $manualImagePath;
                }

                $preparedItems[] = [
                    'active_mode'          => $activeMode,
                    'product_id'           => $productId,
                    'product_link_url'     => $productLinkUrl,
                    'product_description'  => $productDescription,
                    'manual_image_path'    => $manualImagePath,
                    'manual_link_url'      => $manualLinkUrl,
                    'manual_description'   => $manualDescription,
                ];
            }

            if (!empty($preparedItems)) {
                $preparedSlides[] = ['items' => $preparedItems];
            }
        }

        if (empty($preparedSlides)) {
            $this->cleanupNewUploads($newImagePaths, $uploadDir);
            $this->redirectWithError('Please add at least one valid slide with at least one image.');
            return;
        }

        $adminId = getCurrentAdminId();
        $ok = BrandingModel::saveAll($preparedSlides, $adminId);

        if (!$ok) {
            $this->cleanupNewUploads($newImagePaths, $uploadDir);
            $this->redirectWithError('Failed to save the slider. Please try again.');
            return;
        }

        // حذف الصور اليتيمة (كانت مستخدمة قديماً ولم تعد ضمن الحفظة الجديدة)
        $orphaned = array_diff($oldImagePaths, $savedImagePaths);
        foreach ($orphaned as $orphanPath) {
            // basename() تجرّد أي مسار من المدخل فلا يبقى منه إلا اسم
            // الملف — لا `..` ولا شرطات ولا مسار مطلق. المسار الناتج
            // محصور في $uploadDir بالبناء. semgrep يرى تدفّق بيانات من
            // قاعدة البيانات إلى unlink ولا يرى أثر basename.
            $disk = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($orphanPath);
            // nosemgrep: php.lang.security.unlink-use.unlink-use
            if (file_exists($disk)) @unlink($disk);
        }

        AdminModel::logAction($adminId, 'update_branding_slider', 'branding', 0, 'Saved home slider content.');

        $this->notifyAboutBrandingChange($adminId);

        $_SESSION['flash_msg'] = '✅ Slider saved successfully.';
        header('Location: ' . URLROOT . '/admin/branding');
        exit;
    }

    // ══════════════════════════════════════════════════════════
    // Helpers خاصة
    // ══════════════════════════════════════════════════════════

    /**
     * استخراج ملف مرفوع واحد من بنية $_FILES المتداخلة العميقة:
     * $_FILES['slides'][...][slideIndex]['items'][itemIndex]['manual_image']
     * PHP يجمع هذه ببنية غريبة — كل مفتاح فرعي (name/type/tmp_name/error/size)
     * يُصبح Array متداخل بنفس شكل أسماء الحقول الأصلية.
     *
     * @return array|null مصفوفة ملف قياسية (tmp_name/name/size/type/error) أو null
     */
    private function extractFileEntry(array $filesSlides, $slideIndex, $itemIndex): ?array
    {
        $tmp = $filesSlides['tmp_name'][$slideIndex]['items'][$itemIndex]['manual_image'] ?? null;
        $err = $filesSlides['error'][$slideIndex]['items'][$itemIndex]['manual_image'] ?? UPLOAD_ERR_NO_FILE;

        if (empty($tmp) || $err !== UPLOAD_ERR_OK) {
            return null;
        }

        return [
            'tmp_name' => $tmp,
            'name'     => $filesSlides['name'][$slideIndex]['items'][$itemIndex]['manual_image']     ?? '',
            'size'     => $filesSlides['size'][$slideIndex]['items'][$itemIndex]['manual_image']     ?? 0,
            'type'     => $filesSlides['type'][$slideIndex]['items'][$itemIndex]['manual_image']     ?? '',
            'error'    => $err,
        ];
    }

    private function cleanupNewUploads(array $paths, string $uploadDir): void
    {
        foreach ($paths as $p) {
            // كسابقتها: basename تحصر الاسم داخل $uploadDir بالبناء.
            $disk = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($p);
            // nosemgrep: php.lang.security.unlink-use.unlink-use
            if (file_exists($disk)) @unlink($disk);
        }
    }

    /**
     * فحص أماني لروابط السلايدر: يرفض أي رابط يبدأ بـ javascript: أو data:
     * أو vbscript: (تجاهل الحالة والمسافات البادئة) — يُستخدم كـ href بالصفحة
     * العامة، لذا أي تنفيذ نصي عبره يشكّل XSS مباشر.
     */
    private function isUnsafeUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }
        $lower = strtolower(ltrim($url));
        foreach (['javascript:', 'data:', 'vbscript:'] as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                return true;
            }
        }
        return false;
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