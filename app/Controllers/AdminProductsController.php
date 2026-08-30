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
 * AdminProductsController — list, add, edit and delete products, plus dynamic
 * category management.
 * Extends AdminController, which verifies the admin login automatically.
 */
class AdminProductsController extends AdminController
{
    private const PER_PAGE = 12;

    // ═══════════════════════════════════════════════════════════
    // 1) Product list (Manage Products)
    // ═══════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/admin/products',
        summary: 'Product list with search, category filtering, sorting (six fixed options) and pagination',
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
            new OA\Response(response: 200, description: 'HTML page — requires the can_manage_products permission'),
            new OA\Response(response: 403, description: 'Forbidden — the admin lacks can_manage_products'),
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
    // 2) Add a product
    // ═══════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/admin/products/add',
        summary: 'Show the new-product form',
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
        summary: 'Save a new product (requires at least one category and a mandatory image)',
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
                            description: 'At least one item'
                        ),
                        new OA\Property(
                            property: 'variants',
                            type: 'array',
                            items: new OA\Items(type: 'object', description: 'One variant row: color_name, price, discount, stock, gender and image'),
                            description: "The product's colours, quantities and prices — in the same shape the old project used"
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

        // ── Validate the category (before anything else)
        $categoryIds = array_filter(array_map('intval', $_POST['category_ids'] ?? []));
        if (empty($categoryIds)) {
            $this->jsonError('Please select at least one category.');
        }

        // ── Validate the mandatory image (before beginTransaction)
        $variants     = $_POST['variants'] ?? [];
        $variantFiles = $_FILES['variants'] ?? [];

        if (!ProductVariantUploader::hasAnyImage($variantFiles)) {
            $this->jsonError('Product image is required.');
        }

        // ── Upload the variant images and build the data array
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
            // Remove any uploaded images if creation failed
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
    // 3) Edit a product
    // ═══════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/admin/products/edit',
        summary: 'Show the edit form for an existing product',
        tags: ['Admin - Manage Products'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'HTML page with the form'),
            new OA\Response(response: 302, description: 'Redirect to /admin/products when the product does not exist'),
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
        summary: 'Save changes to an existing product',
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
                            items: new OA\Items(type: 'object', description: 'One variant row: color_name, price, discount, stock, gender and image'),
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

        // Read the old variant images so they can be compared after the update
        $oldImagePaths = AdminProductModel::getVariantImagePaths($productId);

        // Was the product entirely out of stock before this edit?
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

        // The product's main image — updated only when a new image is uploaded for the first variant
        if (!empty($parsedVariants[0]['image_path'])) {
            $postData['image_path'] = $parsedVariants[0]['image_path'];
        }

        if (empty($postData['name'])) {
            $this->jsonError('Product name is required.');
        }

        $adminId = getCurrentAdminId();
        // Three distinct outcomes, as in delete(): null is a technical failure ·
        // false is not found · true is updated. Uploaded images are cleaned up in both
        // failing cases, so files no row points at do not accumulate.
        $ok = AdminProductModel::update($productId, $postData, $parsedVariants, array_values($categoryIds), $adminId);

        if ($ok === null) {
            ProductVariantUploader::cleanup($parsedVariants, $uploadDir);
            $this->jsonError('Failed to update product.');
        }
        if ($ok === false) {
            ProductVariantUploader::cleanup($parsedVariants, $uploadDir);
            $this->jsonError('Product not found.');
        }

        // If it was out of stock and is back in stock, tell the users
        if ($wasOutOfStock && AdminProductModel::getTotalStock($productId) > 0) {
            StockNotifier::productBackInStock($productId, $postData['name'], getCurrentAdminId());
        }

        // Delete old images that are no longer referenced
        $newImagePaths = array_column($parsedVariants, 'image_path');
        foreach ($oldImagePaths as $oldPath) {
            if ($oldPath && !in_array($oldPath, $newImagePaths, true)) {
                // publicFileToDelete confines the path inside public/ with realpath.
                // There used to be only an ltrim here, and ltrim does not stop `..`.
                $disk = publicFileToDelete($oldPath);
                if ($disk !== null) {
                    // $disk is the output of publicFileToDelete: a realpath contained
                    // inside public/ plus is_file — no path outside it reaches here.
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
    // 4) Delete a product (AJAX)
    // ═══════════════════════════════════════════════════════════

    #[OA\Post(
        path: '/admin/products/delete',
        summary: 'Delete a product (AJAX — JSON)',
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

        // The product name is read before deletion — afterwards it is unknowable, and the audit record and notification both need it
        $productName = AdminProductModel::getNameById($productId) ?? "#{$productId}";

        // Delete the images from disk before deleting the rows
        $imagePaths = AdminProductModel::getVariantImagePaths($productId);
        foreach ($imagePaths as $imgPath) {
            // As above: the containment lives in the helper, not in the caller.
            $disk = $imgPath ? publicFileToDelete($imgPath) : null;
            if ($disk !== null) {
                // As above: containment already happened in publicFileToDelete.
                // nosemgrep: php.lang.security.unlink-use.unlink-use,php.lang.security.injection.tainted-filename.tainted-filename
                @unlink($disk);
            }
        }

        // Three distinct outcomes: null is a technical failure · false is not found ·
        // true is deleted. Separating them is deliberate: before that, the endpoint
        // answered "success" for a product that did not exist, then wrote an audit row
        // and a notification both claiming a deletion that never happened.
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
        summary: 'Hide or show a product in the store (AJAX — JSON)',
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
                        new OA\Property(property: 'is_visible', type: 'integer', description: '0 or 1'),
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

        $adminId = getCurrentAdminId();

        AdminModel::logAction(
            $adminId,
            'toggle_product_visibility',
            'product',
            $productId,
            "is_visible set to {$newVal}"
        );

        // ⚠️ The notification was missing from this path, and this path alone.
        //
        // Adding, editing and deleting a product all call notifyProductChange, while
        // hiding and showing settled for a line in the audit log. The difference is not
        // cosmetic: hiding a product removes it from the entire store — an effect on
        // customers closer to deletion than to an edit — and it passed without any
        // higher-ranked admin learning of it, and without appearing in the actor's own
        // notification bell.
        //
        // The function existed and worked from the start; all it lacked was a call.
        $productName = AdminProductModel::getNameById($productId) ?? "#{$productId}";
        $this->notifyProductChange(
            $adminId,
            $newVal ? 'visible' : 'hidden',
            $productId,
            $productName
        );

        $this->respond(true, $newVal ? 'Product is now visible.' : 'Product hidden from store.', [
            'is_visible' => $newVal,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 6) Category management (AJAX)
    // ═══════════════════════════════════════════════════════════

    #[OA\Post(
        path: '/admin/products/categories/suggest',
        summary: 'Suggest the closest categories by meaning as the admin types, to prevent duplicates',
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
        // This used to check the permission alone, with no CSRF token. The endpoint is
        // a pure read (suggesting similar categories), so the impact of abusing it is
        // limited — but js/admin/category-picker.js reaches it through the safety net,
        // and leaving a single POST endpoint unchecked meant that removing the
        // message-text matching from csrf.js would have left it with no recovery path.
        // Unifying them closes both at once.
        $this->beginJsonPost();
        Middleware::requirePermission('can_manage_products');

        $q = trim($_POST['q'] ?? '');
        $this->respond(true, '', ['suggestions' => CategoryModel::suggestSimilar($q)]);
    }

    #[OA\Post(
        path: '/admin/products/categories/add',
        summary: 'Add a new category (the name must not already exist)',
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
        summary: 'Delete a non-core category, moving its products to a destination category the admin picks by hand',
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
                            description: 'The category the products are moved into — required'
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
    // 7) CSV export — uses sendCsv(), inherited from AdminController
    // ═══════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/admin/products/export-csv',
        summary: 'Export the product list as a CSV file, under the current search and category filters',
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
    // Internal private helpers
    // ═══════════════════════════════════════════════════════════

    /**
     * Notify on a product change (add / edit / delete). Sends:
     *  (1) a confirmation to the acting admin themselves;
     *  (2) an alert to every admin holding can_manage_products whose rank is STRICTLY
     *      above the actor's, always excluding rank A (via
     *      AdminModel::findHigherRankWithPermission).
     *
     * @param string $action 'added' | 'edited' | 'deleted'
     */
    private function notifyProductChange(int $actorAdminId, string $action, int $productId, string $productName): void
    {
        $actorName = $_SESSION['admin_name'] ?? 'An admin';
        $actorRole = getAdminRole();

        // The title and the verb together, not the verb alone.
        //
        // The title used to be built as `'Product ' . ucfirst($verb)`, which only
        // works for added/edited/deleted. Hiding and showing break it: "Product Hid"
        // is not a phrase. So each case now carries its own title and verb, and the
        // default remains for cases still to come.
        [$title, $verb] = match ($action) {
            'added'   => ['Product Added',   'added'],
            'edited'  => ['Product Edited',  'edited'],
            'deleted' => ['Product Deleted', 'deleted'],
            'hidden'  => ['Product Hidden',  'hid'],
            'visible' => ['Product Visible', 'made visible'],
            default   => ['Product ' . ucfirst($action), $action],
        };

        // (1) A confirmation for the actor themselves
        AdminModel::sendNotification(
            $actorAdminId,
            $title,
            "You {$verb} the product \"{$productName}\" (#{$productId}).",
            'product_' . $action,
            'product',
            $productId,
            $actorAdminId
        );

        // (2) Higher-ranked admins (rank A excluded) who hold can_manage_products
        $targets = AdminModel::findHigherRankWithPermission('can_manage_products', $actorRole);
        foreach ($targets as $targetId) {
            $targetId = (int)$targetId;
            if ($targetId === $actorAdminId) {
                continue;
            }
            AdminModel::sendNotification(
                $targetId,
                $title,
                "{$actorName} {$verb} the product \"{$productName}\" (#{$productId}).",
                'product_' . $action,
                'product',
                $productId,
                $actorAdminId
            );
        }
    }
}
