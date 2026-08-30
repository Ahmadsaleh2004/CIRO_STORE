<?php
/**
 * app/views/admin/product/index.php — fragment فقط (بدون DOCTYPE/html/head/body)
 * المتغيرات من AdminProductsController::index():
 *   $products, $categories, $sortOptions, $search, $catId, $sortKey,
 *   $page, $totalPages, $total, $flashMsg, $flashErr,
 *   $adminName, $adminRole, $adminId, $csrf (من adminView تلقائياً)
 */
?>

<?php
// ── Page Header ───────────────────────────────────────────
//
// الشارة تعرض عدد **النتائج** حين يكون هناك بحث أو فلترة، والعدد
// الكلّي فيما عدا ذلك.
//
// وسبب هذا التفريع أن سطر «X products found» حُذف من فوق الجدول،
// وكانت الشارة تعرض countAll() دائماً — أي أن العدد المُرشَّح اختفى
// من الصفحة كلّها. فأصبحت الشارة تحمل الرقمين بحسب السياق بدل سطر
// ثالث مستقلّ.
//
// و`X of Y` لا الرقم وحده عند الفلترة: «12» بلا مرجع لا تقول شيئاً،
// و«12 of 340» تقول كم رشّح البحث من كم.
//
// ⚠️ البحث والكاتوجريز وحدهما — لا الفرز.
//
// سببان: `AdminProductModel::countFiltered($search, $categoryIds)`
// لا تأخذ الفرز أصلاً، فالفرز لا يغيّر `$total` بشيء — وعرض
// «340 of 340» لمجرّد أن الأدمن رتّب بالسعر ضجيجٌ لا خبر.
//
// والثاني أن `$priceSort` وأختيها تكون **null** لا `''` حين لا
// تُطلَب (راجع AdminProductsController::index)، فمقارنتها بـ`''`
// كانت ستصدق دائماً وتُبقي الشارة في وضع الفلترة أبداً.
$isFiltered = $search !== '' || $categoryIds !== [];

$countLabel = $isFiltered
    ? (int) ($total ?? 0) . ' of ' . (int) $totalProducts
    : (string) (int) $totalProducts;
?>
<div class="admin-page-header">
    <h1>📦 Manage Products <span class="badge bg-secondary fw-normal u-fs-90 align-middle"><?= htmlspecialchars($countLabel) ?></span></h1>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <?php
            $exportParams = http_build_query(array_filter([
                'q'          => $search,
                'price_sort' => $priceSort,
                'stock_sort' => $stockSort,
                'date_sort'  => $dateSort,
            ]));
            foreach ($categoryIds as $cid) { $exportParams .= '&cat[]=' . (int)$cid; }
            $exportCsvUrl       = URLROOT . '/admin/products/export-csv?' . $exportParams;
            $exportCsvOnlyRoleA = false;
            include __DIR__ . '/../inc/export-csv-button.php';
        ?>
        <a href="<?= URLROOT ?>/admin/products/add" class="btn btn-success btn-sm">+ Add Product</a>
    </div>
</div>

<?php // ── Flash Messages ─────────────────────────────────────── ?>
<?php require APPROOT . '/views/shared/flash-messages.php'; ?>

<?php
$activeCount = (int)(bool)$priceSort + (int)(bool)$stockSort + (int)(bool)$dateSort + count($categoryIds);
?>

<?php /*
── صفّ الأدوات: Sort & Filter + البحث ──────────────────

     كانت هاتان كتلتين فوق بعضهما: `div.dropdown mb-3` مستقلّة، ثم صفّ
     البحث تحتها. فيقع زرّ الفرز في سطر وحده فوق حقل البحث، وهما أداتا
     تصفية واحدة تُستعملان معاً.

     وحُذف معهما سطر «X products found» — بطلبٍ صريح.

     ⚠️ وله ثمن يستحقّ التسجيل: الشارة بجانب العنوان تعرض
     `$totalProducts` وهو `countAll()` — العدد الكلّي لا عدد نتائج
     البحث. فالعدد المُرشَّح (`$total`) لم يعد ظاهراً في أي مكان حين
     يكون هناك بحث أو فلترة نشطة. المتغيّر ما زال يصل الـview
     ويستعمله ترقيم الصفحات، فإعادة عرضه سطرٌ واحد متى طُلب.
*/ ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">

<div class="dropdown">
    <button class="btn btn-outline-primary btn-sm dropdown-toggle"
            type="button"
            id="sortFilterBtn"
            data-bs-toggle="dropdown"
            data-bs-auto-close="outside"
            aria-expanded="false">
        🔀 Sort &amp; Filter
        <?php if ($activeCount): ?>
        <span class="badge bg-primary ms-1"><?= $activeCount ?></span>
        <?php endif; ?>
    </button>

    <form method="GET"
          class="dropdown-menu p-3 u-category-panel"
          id="sortFilterForm">

        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">

        <?php // القسم 1: السعر (radio) ?>
        <p class="fw-bold small mb-1">💰 Price</p>
        <div class="mb-3">
            <div class="form-check">
                <input class="form-check-input" type="radio"
                       name="price_sort" value="" id="priceNone"
                       <?= !$priceSort ? 'checked' : '' ?>>
                <label class="form-check-label small" for="priceNone">None</label>
            </div>
            <?php foreach ($priceOptions as $key => $label): ?>
            <div class="form-check">
                <input class="form-check-input" type="radio"
                       name="price_sort" value="<?= $key ?>" id="p_<?= $key ?>"
                       <?= $priceSort === $key ? 'checked' : '' ?>>
                <label class="form-check-label small" for="p_<?= $key ?>"><?= htmlspecialchars($label) ?></label>
            </div>
            <?php endforeach; ?>
        </div>

        <?php // القسم 2: الكمية (radio) ?>
        <p class="fw-bold small mb-1">📦 Stock</p>
        <div class="mb-3">
            <div class="form-check">
                <input class="form-check-input" type="radio"
                       name="stock_sort" value="" id="stockNone"
                       <?= !$stockSort ? 'checked' : '' ?>>
                <label class="form-check-label small" for="stockNone">None</label>
            </div>
            <?php foreach ($stockOptions as $key => $label): ?>
            <div class="form-check">
                <input class="form-check-input" type="radio"
                       name="stock_sort" value="<?= $key ?>" id="s_<?= $key ?>"
                       <?= $stockSort === $key ? 'checked' : '' ?>>
                <label class="form-check-label small" for="s_<?= $key ?>"><?= htmlspecialchars($label) ?></label>
            </div>
            <?php endforeach; ?>
        </div>

        <?php // القسم 3: التاريخ (radio) ?>
        <p class="fw-bold small mb-1">🕒 Date</p>
        <div class="mb-3">
            <div class="form-check">
                <input class="form-check-input" type="radio"
                       name="date_sort" value="" id="dateNone"
                       <?= !$dateSort ? 'checked' : '' ?>>
                <label class="form-check-label small" for="dateNone">None (default)</label>
            </div>
            <?php foreach ($dateOptions as $key => $label): ?>
            <div class="form-check">
                <input class="form-check-input" type="radio"
                       name="date_sort" value="<?= $key ?>" id="d_<?= $key ?>"
                       <?= $dateSort === $key ? 'checked' : '' ?>>
                <label class="form-check-label small" for="d_<?= $key ?>"><?= htmlspecialchars($label) ?></label>
            </div>
            <?php endforeach; ?>
        </div>

        <hr class="filter-divider">

        <?php // القسم 4: الكاتوجريز (checkbox — OR) ?>
        <p class="fw-bold small mb-1">🏷️ Categories <span class="text-muted fw-normal">(any match)</span></p>
        <div class="mb-3">
            <?php foreach ($categories as $c): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox"
                       name="cat[]" value="<?= (int)$c['id'] ?>" id="c_<?= (int)$c['id'] ?>"
                       <?= in_array((int)$c['id'], $categoryIds, true) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="c_<?= (int)$c['id'] ?>">
                    <?= htmlspecialchars($c['name']) ?>
                    <?php if ($c['is_core']): ?>
                    <span class="badge bg-secondary u-fs-50">core</span>
                    <?php endif; ?>
                    <span class="badge bg-light text-dark u-fs-50"><?= (int)$c['product_count'] ?></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary flex-fill">✅ Apply</button>
            <a href="?q=<?= urlencode($search) ?>"
               class="btn btn-sm btn-outline-secondary">✕ Clear</a>
        </div>

    </form>
</div>

    <form method="GET" class="d-flex gap-2">
        <?php if ($priceSort):  ?><input type="hidden" name="price_sort" value="<?= htmlspecialchars($priceSort) ?>"><?php endif; ?>
        <?php if ($stockSort):  ?><input type="hidden" name="stock_sort" value="<?= htmlspecialchars($stockSort) ?>"><?php endif; ?>
        <?php if ($dateSort):   ?><input type="hidden" name="date_sort"  value="<?= htmlspecialchars($dateSort) ?>"><?php endif; ?>
        <?php foreach ($categoryIds as $cid): ?>
        <input type="hidden" name="cat[]" value="<?= (int)$cid ?>">
        <?php endforeach; ?>
        <input type="text"
               name="q"
               class="form-control form-control-sm u-category-trigger"
               placeholder="Search by name..."
               value="<?= htmlspecialchars($search) ?>">
        <button class="btn btn-sm btn-success">🔍 Search</button>
        <?php if ($search !== ''): ?>
        <?php
        $clearParams = array_filter(['price_sort' => $priceSort, 'stock_sort' => $stockSort, 'date_sort' => $dateSort]);
        $clearQuery  = http_build_query($clearParams);
        foreach ($categoryIds as $cid) { $clearQuery .= '&cat[]=' . (int)$cid; }
        ?>
        <a href="?<?= $clearQuery ?>" class="btn btn-sm btn-outline-secondary">✕ Clear Search</a>
        <?php endif; ?>
    </form>
</div>

<?php // ── Products Table ─────────────────────────────────────── ?>
<div class="card p-0 mb-4">
    <div class="table-responsive">
        <table class="table admin-table mb-0" id="productsTable">
            <thead>
                <tr>
                    <?php /* لم يكن للمنتجات عمود معرّف إطلاقاً — الهوية
                             موجودة في id="product-row-N" وحدها، أي يراها
                             الـJS ولا يراها الأدمن. وهو يحتاجها: روابط
                             التعديل والدعم والسجلّات كلها بالمعرّف. */ ?>
                    <th class="u-w-64">#</th>
                    <th class="u-w-60">Image</th>
                    <th>Name</th>
                    <th>Last Modified</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Visible</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($products)): ?>
                <?php
                $emptyColspan = 8;   // ثمانية منذ إضافة عمود المعرّف
                $emptyPadding = 'py-5';   // هذا الجدول وحده يستعمل التباعد الأكبر
                // مصطلح البحث مُهرَّب هنا لأن الـpartial يطبع النص كما هو
                $emptyMessage = 'No products found'
                    . ($search !== '' ? ' for "' . htmlspecialchars($search) . '"' : '') . '.';
                require APPROOT . '/views/shared/table-empty-row.php';
                ?>
            <?php else: ?>
                <?php foreach ($products as $p): ?>
                <tr id="product-row-<?= (int)$p['id'] ?>"
                    class="<?= ($p['is_visible'] ?? 1) ? '' : 'product-hidden-row' ?>">

                    <?php // المعرّف — ثابت مدى حياة الصفّ، لا ترتيب عرض ?>
                    <td class="text-muted"><?= (int)$p['id'] ?></td>

                    <?php // صورة المنتج ?>
                    <td>
                        <img src="<?= htmlspecialchars(fixImagePath($p['image_path'] ?? '')) ?>"
                             alt="<?= htmlspecialchars($p['name']) ?>"
                             class="u-thumb-48"
                             loading="lazy">
                    </td>

                    <?php // اسم المنتج ?>
                    <td>
                        <a href="<?= URLROOT ?>/admin/products/edit?id=<?= (int)$p['id'] ?>"
                           class="fw-semibold text-decoration-none u-text">
                            <?= htmlspecialchars($p['name']) ?>
                        </a>
                        <span class="badge bg-secondary ms-1 hidden-badge u-fs-60 <?= ($p['is_visible'] ?? 1) ? 'd-none' : '' ?>">
                            Hidden
                        </span>
                        <?php if (!empty($p['manufacturer'])): ?>
                        <br><small class="u-muted u-fs-75">
                            <?= htmlspecialchars($p['manufacturer']) ?>
                        </small>
                        <?php endif; ?>
                    </td>

                    <?php // آخر تعديل ?>
                    <td class="u-meta-80">
                        <?php if (!empty($p['last_modified_by_name'])): ?>
                            <span class="u-text fw-medium">
                                <?= htmlspecialchars($p['last_modified_by_name']) ?>
                            </span>
                            <?php if (!empty($p['updated_at'])): ?>
                                <br><small><?= htmlspecialchars(date('M j, Y H:i', strtotime($p['updated_at']))) ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>

                    <?php // السعر ?>
                    <td class="text-nowrap">
                        <span class="fw-semibold u-accent">
                            $<?= number_format((float)($p['price'] ?? 0), 2) ?>
                        </span>
                        <?php if (($p['discount_percentage'] ?? 0) > 0): ?>
                        <br><small class="text-muted u-fs-75">
                            -<?= (int)$p['discount_percentage'] ?>%
                        </small>
                        <?php endif; ?>
                    </td>

                    <?php // المخزون ?>
                    <td>
                        <?php
                        $stock       = (int)($p['total_stock'] ?? $p['stock_quantity'] ?? 0);
                        $minVariant  = $p['min_variant_stock'];
                        // إن لم يوجد أي variant (حالة نادرة)، اعتمد على المجموع نفسه كبديل آمن
                        $colorSource = ($minVariant !== null) ? (int)$minVariant : $stock;
                        $stockClass = match(true) {
                            $colorSource === 0         => 'bg-danger',
                            $colorSource > 0 && $colorSource < 50 => 'bg-warning text-dark',
                            default                    => 'bg-success',
                        };
                        ?>
                        <span class="badge <?= $stockClass ?>" title="Lowest color in stock: <?= $minVariant !== null ? (int)$minVariant : 'n/a' ?>">
                            <?= $stock ?>
                        </span>
                    </td>

                    <?php // الرؤية + toggle ?>
                    <td>
                        <button class="btn btn-sm toggle-vis-btn
                                       <?= ($p['is_visible'] ?? 1) ? 'btn-outline-secondary' : 'btn-outline-warning' ?>"
                                data-id="<?= (int)$p['id'] ?>"
                                title="<?= ($p['is_visible'] ?? 1) ? 'Hide from store' : 'Show in store' ?>">
                            <?= ($p['is_visible'] ?? 1) ? '👁️' : '🚫' ?>
                        </button>
                    </td>

                    <?php // Actions ?>
                    <td>
                        <a href="<?= URLROOT ?>/admin/products/edit?id=<?= (int)$p['id'] ?>"
                           class="btn btn-sm btn-outline-primary me-1"
                           title="Edit product">✏️</a>
                        <button class="btn btn-sm btn-outline-danger del-btn"
                                data-id="<?= (int)$p['id'] ?>"
                                data-name="<?= htmlspecialchars($p['name']) ?>"
                                title="Delete product">🗑️ Delete</button>
                    </td>

                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php // ── Pagination ─────────────────────────────────────────── ?>
<?php if ($totalPages > 1):
    // بناء query string الفلاتر للـ pagination — يحافظ على كل الفلاتر عند التنقل
    $paginationBase = http_build_query(array_filter([
        'q'          => $search,
        'price_sort' => $priceSort,
        'stock_sort' => $stockSort,
        'date_sort'  => $dateSort,
    ]));
    foreach ($categoryIds as $cid) { $paginationBase .= '&cat[]=' . (int)$cid; }
    $pageUrl = fn(int $p) => '?' . $paginationBase . ($paginationBase ? '&' : '') . 'page=' . $p;
?>
<nav aria-label="Products pagination" class="mb-4">
    <ul class="pagination justify-content-center flex-wrap">

        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $pageUrl($page - 1) ?>">&laquo; Prev</a>
        </li>

        <?php
        $window = 2;
        $start  = max(1, $page - $window);
        $end    = min($totalPages, $page + $window);
        ?>

        <?php if ($start > 1): ?>
        <li class="page-item">
            <a class="page-link" href="<?= $pageUrl(1) ?>">1</a>
        </li>
        <?php if ($start > 2): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
        <?php endif; ?>

        <?php for ($p2 = $start; $p2 <= $end; $p2++): ?>
        <li class="page-item <?= $p2 === $page ? 'active' : '' ?>">
            <a class="page-link" href="<?= $pageUrl($p2) ?>"><?= $p2 ?></a>
        </li>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
        <li class="page-item">
            <a class="page-link" href="<?= $pageUrl($totalPages) ?>"><?= $totalPages ?></a>
        </li>
        <?php endif; ?>

        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $pageUrl($page + 1) ?>">Next &raquo;</a>
        </li>

    </ul>
</nav>
<?php endif; ?>

<?php // ── CSRF hidden (يُستخدم بـ AJAX من products.js) ─────── ?>
<input type="hidden" id="productsCsrf" value="<?= htmlspecialchars($csrf) ?>">

<?php // ── Category Picker Modal ─────────────────────────────── ?>
<?php include __DIR__ . '/_category-picker-modal.php'; ?>
