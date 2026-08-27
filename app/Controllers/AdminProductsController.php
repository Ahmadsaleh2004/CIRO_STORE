<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\AdminProductModel;
use App\Models\CategoryModel;
use App\Models\AdminModel;
use App\Services\ProductVariantUploader;
use App\Services\StockNotifier;
use OpenApi\Attributes as OA;

/**
 * AdminProductsController — قائمة/إضافة/تعديل/حذف المنتجات + إدارة الكاتوجريز الديناميكية.
 * يرث من AdminController الذي يتحقق من تسجيل دخول الأدمن تلقائياً.
 */
class AdminProductsController extends AdminController
{
    private const PER_PAGE = 12;

    // ═══════════════════════════════════════════════════════════
    // 1) قائمة المنتجات (Manage Products)
    // ═══════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/admin/products',
        summary: 'قائمة المنتجات مع بحث/فلترة بكاتوجري/ترتيب (6 خيارات ثابتة) + Pagination',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cat', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(
                type: 'string',
                enum: ['date_desc', 'date_asc', 'price_desc', 'price_asc', 'stock_desc', 'stock_asc']
            )),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML — يتطلب صلاحية can_manage_products'),
            new OA\Response(response: 403, description: 'ممنوع — لا يملك can_manage_products'),
        ]
    )]
    public function index(): void
    {
        Middleware::requirePermission('can_manage_products');

        $search      = trim($_GET['q'] ?? '');
        $categoryIds = array_values(array_filter(array_map('intval', (array)($_GET['cat'] ?? []))));
        $priceSort   = in_array($_GET['price_sort'] ?? '', ['price_desc', 'price_asc'], true)
                        ? $_GET['price_sort'] : null;
        $stockSort   = in_array($_GET['stock_sort'] ?? '', ['stock_desc', 'stock_asc'], true)
                        ? $_GET['stock_sort'] : null;
        $dateSort    = in_array($_GET['date_sort'] ?? '', ['date_desc', 'date_asc'], true)
                        ? $_GET['date_sort'] : null;
        $page        = max(1, (int)($_GET['page'] ?? 1));

        $total      = AdminProductModel::countFiltered($search, $categoryIds);
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * self::PER_PAGE;

        $products   = AdminProductModel::getPaginated(
            $search,
            $categoryIds,
            $priceSort,
            $stockSort,
            $dateSort,
            self::PER_PAGE,
            $offset
        );
        $categories = CategoryModel::getAllOrdered();

        $flashMsg = $_SESSION['flash_msg'] ?? '';
        $flashErr = $_SESSION['flash_err'] ?? '';
        unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

        $this->adminView('product/index', [
            'pageTitle'    => 'Manage Products',
            'products'     => $products,
            'categories'   => $categories,
            'priceOptions' => AdminProductModel::PRICE_SORT_OPTIONS,
            'stockOptions' => AdminProductModel::STOCK_SORT_OPTIONS,
            'dateOptions'  => AdminProductModel::DATE_SORT_OPTIONS,
            'search'       => $search,
            'categoryIds'  => $categoryIds,
            'priceSort'    => $priceSort,
            'stockSort'    => $stockSort,
            'dateSort'     => $dateSort,
            'page'          => $page,
            'totalPages'    => $totalPages,
            'total'         => $total,
            'totalProducts' => AdminProductModel::countAll(),
            'flashMsg'      => $flashMsg,
            'flashErr'      => $flashErr,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 2) إضافة منتج
    // ═══════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/admin/products/add',
        summary: 'عرض فورم إضافة منتج جديد',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 302, ref: '#/components/responses/RedirectToLogin'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function showAdd(): void
    {
        Middleware::requirePermission('can_manage_products');

        $formErr = $_SESSION['flash_err'] ?? '';
        unset($_SESSION['flash_err']);

        $this->adminView('product/add', [
            'pageTitle'  => 'Add Product',
            'categories' => CategoryModel::getAllOrdered(),
            'formErr'    => $formErr,
        ]);
    }

    #[OA\Post(
        path: '/admin/products/add',
        summary: 'حفظ منتج جديد (يتطلب كاتوجري واحدة على الأقل + صورة إجبارية)',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['name', 'category_ids', 'variants', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'description', type: 'string'),
                        new OA\Property(property: 'manufacturer', type: 'string'),
                        new OA\Property(
                            property: 'category_ids',
                            type: 'array',
                            items: new OA\Items(type: 'integer'),
                            description: 'عنصر واحد على الأقل'
                        ),
                        new OA\Property(
                            property: 'variants',
                            type: 'array',
                            items: new OA\Items(type: 'object', description: 'صف variant واحد: color_name و price و discount و stock و gender و image'),
                            description: 'ألوان/كميات/أسعار المنتج — بنفس بنية المشروع القديم'
                        ),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON — {success, message, product_id?, redirect?}. Always 200, even on validation errors (success:false + a message).',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'product_id', type: 'integer', nullable: true),
                        new OA\Property(property: 'redirect', type: 'string', nullable: true, description: 'URL to navigate to on success'),
                    ]
                )
            )
        ]
    )]
    public function storeAdd(): void
    {
        $this->beginJsonPost();
        Middleware::requirePermission('can_manage_products');

        // ── تحقق من الكاتوجري (قبل أي شيء)
        $categoryIds = array_filter(array_map('intval', $_POST['category_ids'] ?? []));
        if (empty($categoryIds)) {
            $this->jsonError('Please select at least one category.');
        }

        // ── تحقق من الصورة الإجبارية (قبل beginTransaction)
        $variants     = $_POST['variants'] ?? [];
        $variantFiles = $_FILES['variants'] ?? [];

        if (!ProductVariantUploader::hasAnyImage($variantFiles)) {
            $this->jsonError('Product image is required.');
        }

        // ── رفع صور الـ variants وبناء مصفوفة البيانات
        $uploadDir      = ROOTPATH . '/public/images/';
        $parsedVariants = ProductVariantUploader::parse(
            $variants,
            $variantFiles,
            $uploadDir,
            (int)($_POST['default_variant'] ?? 0)
        );

        if (empty($parsedVariants)) {
            $this->jsonError('At least one variant with a valid name and price is required.');
        }

        $adminId  = getCurrentAdminId();
        $postData = [
            'name'         => trim($_POST['name']         ?? ''),
            'description'  => trim($_POST['description']  ?? ''),
            'country'      => trim($_POST['country']       ?? ''),
            'manufacturer' => trim($_POST['manufacturer']  ?? ''),
            'price'        => (float)($parsedVariants[0]['price'] ?? 0),
            'discount'     => (float)($parsedVariants[0]['discount'] ?? 0),
            'gender'       => $parsedVariants[0]['gender'] ?? 'both',
            'image_path'   => $parsedVariants[0]['image_path'] ?? null,
        ];

        if (empty($postData['name'])) {
            $this->jsonError('Product name is required.');
        }

        $productId = AdminProductModel::create($postData, $parsedVariants, array_values($categoryIds), $adminId);

        if (!$productId) {
            // احذف أي صور رُفعت إذا فشل الإنشاء
            ProductVariantUploader::cleanup($parsedVariants, $uploadDir);
            $this->jsonError('Failed to create product. Please check the data and try again.');
        }

        AdminModel::logAction(
            $adminId,
            'add_product',
            'product',
            $productId,
            "Created product: " . $postData['name']
        );

        $this->notifyProductChange($adminId, 'added', $productId, $postData['name']);
        StockNotifier::productOutOfStock($productId, $postData['name']);

        $this->respond(true, 'Product added successfully.', [
            'product_id' => $productId,
            'redirect'   => URLROOT . '/admin/products',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 3) تعديل منتج
    // ═══════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/admin/products/edit',
        summary: 'عرض فورم تعديل منتج موجود',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML للفورم'),
            new OA\Response(response: 302, description: 'إعادة توجيه لـ /admin/products إذا المنتج غير موجود'),
        ]
    )]
    public function showEdit(): void
    {
        Middleware::requirePermission('can_manage_products');

        $id      = (int)($_GET['id'] ?? 0);
        $product = $id ? AdminProductModel::findByIdWithCategories($id) : null;

        if (!$product) {
            $_SESSION['flash_err'] = 'Product not found.';
            header('Location: ' . URLROOT . '/admin/products');
            exit;
        }

        $formErr = $_SESSION['flash_err'] ?? '';
        unset($_SESSION['flash_err']);

        $this->adminView('product/edit', [
            'pageTitle'  => 'Edit: ' . htmlspecialchars($product['name']),
            'product'    => $product,
            'categories' => CategoryModel::getAllOrdered(),
            'formErr'    => $formErr,
        ]);
    }

    #[OA\Post(
        path: '/admin/products/edit',
        summary: 'حفظ تعديل منتج موجود',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['product_id', 'category_ids', 'variants', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'product_id', type: 'integer'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'description', type: 'string'),
                        new OA\Property(property: 'manufacturer', type: 'string'),
                        new OA\Property(property: 'category_ids', type: 'array', items: new OA\Items(type: 'integer')),
                        new OA\Property(
                            property: 'variants',
                            type: 'array',
                            items: new OA\Items(type: 'object', description: 'صف variant واحد: color_name و price و discount و stock و gender و image'),
                        ),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON — {success, message}. Always 200, even on validation errors (success:false + a message). No redirect field — the admin stays on the edit page after a successful update.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            )
        ]
    )]
    public function storeEdit(): void
    {
        $this->beginJsonPost();
        Middleware::requirePermission('can_manage_products');

        $productId   = (int)($_POST['product_id'] ?? 0);
        $categoryIds = array_filter(array_map('intval', $_POST['category_ids'] ?? []));

        if (!$productId) {
            $this->jsonError('Invalid product.');
        }
        if (empty($categoryIds)) {
            $this->jsonError('Please select at least one category.');
        }

        $variants     = $_POST['variants'] ?? [];
        $variantFiles = $_FILES['variants'] ?? [];
        $uploadDir    = ROOTPATH . '/public/images/';

        // جلب الصور القديمة للـ variants للمقارنة بعد التحديث
        $oldImagePaths = AdminProductModel::getVariantImagePaths($productId);

        // هل كان المنتج بالكامل نافذ الكمية قبل هذا التعديل؟
        $wasOutOfStock = (AdminProductModel::getTotalStock($productId) === 0);

        $parsedVariants = ProductVariantUploader::parse(
            $variants,
            $variantFiles,
            $uploadDir,
            (int)($_POST["default_variant"] ?? 0)
        );

        if (empty($parsedVariants)) {
            $this->jsonError('At least one variant with a valid name and price is required.');
        }

        $postData = [
            'name'         => trim($_POST['name']         ?? ''),
            'description'  => trim($_POST['description']  ?? ''),
            'country'      => trim($_POST['country']       ?? ''),
            'manufacturer' => trim($_POST['manufacturer']  ?? ''),
            'price'        => (float)($parsedVariants[0]['price'] ?? 0),
            'discount'     => (float)($parsedVariants[0]['discount'] ?? 0),
            'gender'       => $parsedVariants[0]['gender'] ?? 'both',
        ];

        // صورة المنتج الرئيسية — تُحدَّث فقط إذا رُفعت صورة جديدة للـ variant الأول
        if (!empty($parsedVariants[0]['image_path'])) {
            $postData['image_path'] = $parsedVariants[0]['image_path'];
        }

        if (empty($postData['name'])) {
            $this->jsonError('Product name is required.');
        }

        $adminId = getCurrentAdminId();
        // ثلاث حالات متمايزة كما في delete(): null فشل تقني · false لم
        // يوجد · true حُدِّث. والصور المرفوعة تُنظَّف في الحالتين
        // الفاشلتين كي لا تتراكم ملفات لا يشير إليها صفّ.
        $ok = AdminProductModel::update($productId, $postData, $parsedVariants, array_values($categoryIds), $adminId);

        if ($ok === null) {
            ProductVariantUploader::cleanup($parsedVariants, $uploadDir);
            $this->jsonError('Failed to update product.');
        }
        if ($ok === false) {
            ProductVariantUploader::cleanup($parsedVariants, $uploadDir);
            $this->jsonError('Product not found.');
        }

        // إذا كان نافذًا وعاد للتوفر، أخبر المستخدمين
        if ($wasOutOfStock && AdminProductModel::getTotalStock($productId) > 0) {
            StockNotifier::productBackInStock($productId, $postData['name'], getCurrentAdminId());
        }

        // احذف الصور القديمة التي لم تعد مستخدمة
        $newImagePaths = array_column($parsedVariants, 'image_path');
        foreach ($oldImagePaths as $oldPath) {
            if ($oldPath && !in_array($oldPath, $newImagePaths, true)) {
                // publicFileToDelete يحتوي المسار داخل public/ بـrealpath.
                // كان هنا ltrim وحدها، وهي لا تمنع `..`.
                $disk = publicFileToDelete($oldPath);
                if ($disk !== null) {
                    // $disk ناتج publicFileToDelete: realpath محتوى داخل
                    // public/ وis_file — لا يصل هنا مسار خارجه.
                    // nosemgrep: php.lang.security.unlink-use.unlink-use,php.lang.security.injection.tainted-filename.tainted-filename
                    @unlink($disk);
                }
            }
        }

        AdminModel::logAction(
            $adminId,
            'edit_product',
            'product',
            $productId,
            "Edited product #{$productId}: " . $postData['name']
        );

        $this->notifyProductChange($adminId, 'edited', $productId, $postData['name']);
        StockNotifier::productOutOfStock($productId, $postData['name']);

        $this->respond(true, 'Product updated successfully.');
    }

    // ═══════════════════════════════════════════════════════════
    // 4) حذف منتج (AJAX)
    // ═══════════════════════════════════════════════════════════

    #[OA\Post(
        path: '/admin/products/delete',
        summary: 'حذف منتج (AJAX — JSON)',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
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
                description: 'JSON success/message',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            )
        ]
    )]
    public function delete(): void
    {
        Middleware::requirePermission('can_manage_products');
        $this->beginJsonPost();

        $productId = (int)($_POST['product_id'] ?? 0);
        if (!$productId) {
            $this->respond(false, 'Invalid product ID.');
        }

        // اسم المنتج يُقرأ قبل الحذف — بعد الحذف يستحيل معرفته للسجل/الإشعار
        $productName = AdminProductModel::getNameById($productId) ?? "#{$productId}";

        // احذف الصور من القرص قبل حذف السجلات
        $imagePaths = AdminProductModel::getVariantImagePaths($productId);
        foreach ($imagePaths as $imgPath) {
            // كسابقه: الاحتواء داخل الهيلبر لا في المستدعي.
            $disk = $imgPath ? publicFileToDelete($imgPath) : null;
            if ($disk !== null) {
                // كسابقه: الاحتواء تمّ في publicFileToDelete.
                // nosemgrep: php.lang.security.unlink-use.unlink-use,php.lang.security.injection.tainted-filename.tainted-filename
                @unlink($disk);
            }
        }

        // ثلاث حالات متمايزة: null فشل تقني · false لم يوجد · true حُذف.
        // الفصل بينها مقصود: قبله كانت النقطة تُجيب «نجح» لمنتج غير موجود
        // ثم تكتب صفّ تدقيق وإشعاراً يزعمان حذفاً لم يحدث.
        $deleted = AdminProductModel::delete($productId);
        if ($deleted === null) {
            $this->respond(false, 'Failed to delete product.');
        }
        if ($deleted === false) {
            $this->respond(false, 'Product not found.');
        }

        $adminId = getCurrentAdminId();
        AdminModel::logAction(
            $adminId,
            'delete_product',
            'product',
            $productId,
            "Deleted product #{$productId}: {$productName}"
        );

        $this->notifyProductChange($adminId, 'deleted', $productId, $productName);

        $this->respond(true, 'Product deleted successfully.');
    }

    // ═══════════════════════════════════════════════════════════
    // 5) toggle visibility (AJAX)
    // ═══════════════════════════════════════════════════════════

    #[OA\Post(
        path: '/admin/products/toggle-visibility',
        summary: 'إخفاء/إظهار منتج في المتجر (AJAX — JSON)',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON: {success, is_visible}',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'is_visible', type: 'integer', description: '0 أو 1'),
                    ]
                )
            )
        ]
    )]
    public function toggleVisibility(): void
    {
        Middleware::requirePermission('can_manage_products');
        $this->beginJsonPost();

        $productId = (int)($_POST['product_id'] ?? 0);
        if (!$productId) {
            $this->respond(false, 'Invalid product ID.');
        }

        $newVal = AdminProductModel::toggleVisibility($productId);
        if ($newVal === null) {
            $this->respond(false, 'Product not found.');
        }

        AdminModel::logAction(
            getCurrentAdminId(),
            'toggle_product_visibility',
            'product',
            $productId,
            "is_visible set to {$newVal}"
        );

        $this->respond(true, $newVal ? 'Product is now visible.' : 'Product hidden from store.', [
            'is_visible' => $newVal,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 6) إدارة الكاتوجريز (AJAX)
    // ═══════════════════════════════════════════════════════════

    #[OA\Post(
        path: '/admin/products/categories/suggest',
        summary: 'اقتراح أقرب الكاتوجريز بالمعنى أثناء كتابة الأدمن (منع التكرار)',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    properties: [new OA\Property(property: 'q', type: 'string')]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON: {success, suggestions:[{id,name,similarity}]}'
            )
        ]
    )]
    public function suggestCategory(): void
    {
        // كانت تفحص الصلاحية وحدها بلا توكن CSRF. النقطة قراءة محضة
        // (اقتراح تصنيفات مشابهة) فأثر استغلالها محدود، لكن
        // js/admin/category-picker.js يمرّ عليها بشبكة الأمان، وترك نقطة
        // POST واحدة بلا فحص يعني أن حذف مطابقة نصّ الرسالة من csrf.js
        // كان سيتركها بلا مسار تعافٍ. التوحيد يغلق الاثنين معاً.
        $this->beginJsonPost();
        Middleware::requirePermission('can_manage_products');

        $q = trim($_POST['q'] ?? '');
        $this->respond(true, '', ['suggestions' => CategoryModel::suggestSimilar($q)]);
    }

    #[OA\Post(
        path: '/admin/products/categories/add',
        summary: 'إضافة كاتوجري جديدة (يتطلب اسم غير مكرر)',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['name', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON: {success, message, category:{id,name}}'
            )
        ]
    )]
    public function addCategory(): void
    {
        $this->beginJsonPost();
        Middleware::requirePermission('can_manage_products');

        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $this->respond(false, 'Category name is required.');
        }
        if (CategoryModel::nameExists($name)) {
            $this->respond(false, 'This category already exists.');
        }

        $newId = CategoryModel::create($name);
        if (!$newId) {
            $this->respond(false, 'Failed to create category.');
        }

        AdminModel::logAction(
            getCurrentAdminId(),
            'add_category',
            'category',
            $newId,
            "Added category: {$name}"
        );

        $this->respond(true, 'Category added.', ['category' => ['id' => $newId, 'name' => $name]]);
    }

    #[OA\Post(
        path: '/admin/products/categories/delete',
        summary: 'حذف كاتوجري (غير أساسية) مع نقل منتجاتها لكاتوجري وجهة يختارها الأدمن يدوياً',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['category_id', 'destination_id', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'category_id', type: 'integer'),
                        new OA\Property(
                            property: 'destination_id',
                            type: 'integer',
                            description: 'الكاتوجري التي تُنقل إليها المنتجات — إلزامي'
                        ),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON: {success, message}'
            )
        ]
    )]
    public function deleteCategory(): void
    {
        $this->beginJsonPost();
        Middleware::requirePermission('can_manage_products');

        $catId  = (int)($_POST['category_id']    ?? 0);
        $destId = (int)($_POST['destination_id'] ?? 0);

        if (!$catId || !$destId) {
            $this->respond(false, 'Please choose a destination category to move products to.');
        }
        if (CategoryModel::isCore($catId)) {
            $this->respond(false, 'Core categories (Phone, Computer, Accessories, Gaming) cannot be deleted.');
        }

        $catName = CategoryModel::findById($catId)['name'] ?? "#{$catId}";

        if (!CategoryModel::deleteAndReassign($catId, $destId)) {
            $this->respond(false, 'Failed to delete category.');
        }

        AdminModel::logAction(
            getCurrentAdminId(),
            'delete_category',
            'category',
            $catId,
            "Deleted category '{$catName}', products moved to category #{$destId}"
        );

        $this->respond(true, 'Category deleted and products reassigned.');
    }

    // ═══════════════════════════════════════════════════════════
    // 7) تصدير CSV — يستخدم sendCsv() الموروثة من AdminController
    // ═══════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/admin/products/export-csv',
        summary: 'تصدير قائمة المنتجات كملف CSV (مع تطبيق فلاتر البحث/الكاتوجري الحالية)',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cat', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/CsvDownload'),
            new OA\Response(response: 401, ref: '#/components/responses/SessionExpired'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
        ]
    )]
    public function exportCsv(): void
    {
        Middleware::requirePermission('can_manage_products');

        $search      = trim($_GET['q'] ?? '');
        $categoryIds = array_values(array_filter(array_map('intval', (array)($_GET['cat'] ?? []))));
        $priceSort   = in_array($_GET['price_sort'] ?? '', ['price_desc', 'price_asc'], true)
                        ? $_GET['price_sort'] : null;
        $stockSort   = in_array($_GET['stock_sort'] ?? '', ['stock_desc', 'stock_asc'], true)
                        ? $_GET['stock_sort'] : null;
        $dateSort    = in_array($_GET['date_sort'] ?? '', ['date_desc', 'date_asc'], true)
                        ? $_GET['date_sort'] : null;

        $rows = AdminProductModel::getPaginated(
            $search,
            $categoryIds,
            $priceSort,
            $stockSort,
            $dateSort,
            100000,
            0
        );

        AdminModel::logAction(
            getCurrentAdminId(),
            'export_csv',
            'product',
            0,
            "Exported " . count($rows) . " products."
        );

        $headers = ['ID', 'Name', 'Price ($)', 'Discount (%)', 'Stock', 'Categories', 'Date Added', 'Visible'];
        $csvRows = array_map(fn($r) => [
            $r['id'],
            $r['name'],
            number_format((float)($r['price'] ?? 0), 2),
            $r['discount_percentage'] ?? 0,
            $r['total_stock']         ?? $r['stock_quantity'] ?? 0,
            $r['categories']          ?? '',
            $r['date_added']          ?? '',
            ($r['is_visible'] ?? 1) ? 'Yes' : 'No',
        ], $rows);

        $this->sendCsv('products_' . date('Ymd_His') . '.csv', $headers, $csvRows);
    }

    // ═══════════════════════════════════════════════════════════
    // Helpers خاصة داخلية
    // ═══════════════════════════════════════════════════════════

    /**
     * إشعار تغيير منتج (إضافة/تعديل/حذف) — يرسل:
     *  (1) تأكيد للأدمن المنفّذ نفسه.
     *  (2) تنبيه لكل أدمن يملك can_manage_products ورتبته أعلى STRICTLY من رتبة
     *      المنفّذ، باستثناء رتبة A دائماً (عبر AdminModel::findHigherRankWithPermission).
     *
     * @param string $action 'added' | 'edited' | 'deleted'
     */
    private function notifyProductChange(int $actorAdminId, string $action, int $productId, string $productName): void
    {
        $actorName = $_SESSION['admin_name'] ?? 'An admin';
        $actorRole = getAdminRole();

        $verb = match ($action) {
            'added'   => 'added',
            'edited'  => 'edited',
            'deleted' => 'deleted',
            default   => $action,
        };

        // (1) تأكيد للمنفّذ نفسه
        AdminModel::sendNotification(
            $actorAdminId,
            'Product ' . ucfirst($verb),
            "You {$verb} the product \"{$productName}\" (#{$productId}).",
            'product_' . $action,
            'product',
            $productId,
            $actorAdminId
        );

        // (2) الأدمنية الأعلى رتبة (باستثناء A) ممن يملكون can_manage_products
        $targets = AdminModel::findHigherRankWithPermission('can_manage_products', $actorRole);
        foreach ($targets as $targetId) {
            $targetId = (int)$targetId;
            if ($targetId === $actorAdminId) {
                continue;
            }
            AdminModel::sendNotification(
                $targetId,
                'Product ' . ucfirst($verb),
                "{$actorName} {$verb} the product \"{$productName}\" (#{$productId}).",
                'product_' . $action,
                'product',
                $productId,
                $actorAdminId
            );
        }
    }
}
