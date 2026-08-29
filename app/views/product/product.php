<?php
// app/views/product/index.php

$pageTitle = 'Products';
$pageDescription = 'Browse all products at Cairo Store.';

// productTag() انتقلت إلى app/helpers/product_tag_helper.php
?>

<main id="main-content" role="main">
<section class="container py-5">

    <nav class="store-breadcrumb mb-3">
        <a href="<?= URLROOT ?>">🏠 Home</a>
        <span class="sep">/</span>
        <span class="current">Products</span>
    </nav>

    <h1 class="section-title">Our Products</h1>

    <?php if (!empty($msg)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php // ── Filters ───────────────────────────────────────── ?>
    <div class="row mb-4 g-3">
        <div class="col-md-6 col-lg-4">
            <div id="search-wrapper">
                <input type="text" id="search" class="form-control" placeholder="Search products...">
                <ul id="autocomplete-list"></ul>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <select id="sort" class="form-select">
                <option value="">Sort Products</option>
                <optgroup label="By Name">
                    <option value="az">Name A-Z</option>
                    <option value="za">Name Z-A</option>
                </optgroup>
                <optgroup label="By Price">
                    <option value="low">Price Low → High</option>
                    <option value="high">Price High → Low</option>
                </optgroup>
                <optgroup label="By Category">
                    <?php
                    foreach ($categories as $cat):
                        $emoji = categoryEmoji($cat['name']);
                    ?>
                    <option value="cat-<?= htmlspecialchars($cat['name']) ?>">
                        <?php // @escaping-safe: categoryEmoji ترجع رمزاً من خريطة داخلية ?>
                        <?= $emoji ?> <?= htmlspecialchars(ucfirst($cat['name'])) ?>
                    </option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="By Price Range">
                    <option value="price-u100">Under $100</option>
                    <option value="price-u300">Under $300</option>
                    <option value="price-u500">Under $500</option>
                    <option value="price-o500">$500 &amp; Above</option>
                </optgroup>
            </select>
        </div>
        <div class="col-md-6 col-lg-3 d-flex align-items-center gap-2">
            <input type="range" id="priceRange" min="0" max="2000" value="2000" class="form-range">
            <span id="priceRangeVal" class="small fw-bold price-range-val">≤$2000</span>
        </div>
        <div class="col-md-6 col-lg-2">
            <button id="reset" class="btn btn-secondary w-100">Reset</button>
        </div>
    </div>

    <div id="results-count" class="mb-3 results-count-text"></div>

    <?php // ── Products Grid ──────────────────────────────── ?>
    <div class="row" id="products-container">

        <?php foreach ($products as $p):
            $display   = $p['_display'];
            $price     = (float)$display['price'];
            $discount  = (float)($display['discount_percentage'] ?? 0);
            $afterDisc = (float)($display['price_after_discount'] ?? $price);
            $finalPrice = $discount > 0 ? $afterDisc : $price;
            $stock     = (int)$display['stock_quantity'];
            $imgSrc    = htmlspecialchars(fixImagePath($display['image_path'] ?? $p['image_path'] ?? ''));
            $variantId = $display['id'] ?? null;
            $colorName = $display['color_name'] ?? null;
            $tag       = productTag($p);
            $cats      = strtolower($p['categories'] ?? '');
        ?>
        <div class="col-lg-4 col-md-6 mb-4 product-item reveal"
             data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>"
             data-price="<?= $finalPrice ?>"
             data-cats="<?= htmlspecialchars($cats) ?>"
             data-color="<?= htmlspecialchars($colorName ?? '') ?>">
            <div class="card product-card h-100 shadow border-0 position-relative" role="article">

                <?php if ($discount > 0): ?>
                <span class="discount-badge">-<?= (float)$discount ?>%</span>
                <?php endif; ?>

                <?php if (!empty($isAdminProd)): ?>
                <form method="POST" class="admin-delete-form">
                    <input type="hidden" name="delete_product" value="1">
                    <input type="hidden" name="product_id"    value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrf) ?>">
                    <button type="submit" class="delete-product-btn"
                        data-confirm="Delete «<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>»?"
                        title="Delete">✕</button>
                </form>
                <?php else: ?>
                <button class="favorite-btn" aria-label="Add to wishlist"
                    data-pid="<?= (int)$p['id'] ?>"
                    data-product='<?= htmlspecialchars(json_encode([
                        'id'         => (int)$p['id'],
                        'variant_id' => $variantId,
                        'color_name' => $colorName,
                        'name'       => $p['name'],
                        'price'      => $finalPrice,
                        'image_path' => $imgSrc ? fixImagePath($display['image_path'] ?? $p['image_path']) : fixImagePath($p['image_path']),
                        'image'      => fixImagePath($display['image_path'] ?? $p['image_path']),
                    ])) ?>'>🤍</button>
                <?php endif; ?>

                <a href="<?= URLROOT ?>/product?id=<?= (int)$p['id'] ?>" class="product-link">
                    <?php $webpSrc = getWebpPath($display['image_path'] ?? $p['image_path'] ?? ''); ?>
                    <picture>
                        <?php if ($webpSrc): ?>
                        <source srcset="<?= htmlspecialchars($webpSrc) ?>" type="image/webp">
                        <?php endif; ?>
                        <img src="<?= htmlspecialchars($imgSrc) ?>"
                             class="card-img-top product-image"
                             alt="<?= htmlspecialchars($p['name']) ?>"
                             loading="lazy">
                    </picture>
                </a>

                <div class="card-body d-flex flex-column justify-content-between">
                    <div class="mb-2">
                        <h5 class="fw-bold"><?= htmlspecialchars($p['name']) ?></h5>

                        <?php $stockBadge = getStockBadge($stock); ?>
                        <?php if ($stockBadge): ?>
                        <span class="badge <?= $stockBadge['class'] ?> mb-1"><?= htmlspecialchars($stockBadge['label']) ?></span>
                        <?php endif; ?>

                        <div class="price-box mt-1">
                            <span class="new-price fs-5 fw-bold">$<?= number_format($finalPrice,2) ?></span>
                            <?php if ($discount > 0): ?>
                            <span class="old-price ms-1">$<?= number_format($price,2) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (empty($isAdminProd)): ?>
                    <div>
                        <div class="quantity-box mb-2 d-flex justify-content-center gap-2">
                            <button class="btn btn-outline-secondary btn-sm"
                                    data-action="change-qty" data-product-id="<?= (int)$p['id'] ?>" data-delta="-1" aria-label="Decrease quantity">−</button>
                            <input type="number" value="1" id="qty-<?= (int)$p['id'] ?>"
                                   class="form-control quantity-input qty-input-sm"
                                   min="1" max="<?= $stock ?>">
                            <button class="btn btn-outline-secondary btn-sm"
                                    data-action="change-qty" data-product-id="<?= (int)$p['id'] ?>" data-delta="1" aria-label="Increase quantity">+</button>
                        </div>
                        <?php if ($stock > 0): ?>
                        <?php if (isUser() && empty($_SESSION['admin_in_store_mode'])): ?>
                        <button class="btn btn-success w-100"
                                data-action="add-to-cart"
                                    data-product-id="<?= (int)$p['id'] ?>"
                                    data-variant-id="<?= (int)$variantId ?>"
                                    data-price="<?= $finalPrice ?>"
                                    data-stock="<?= $stock ?>">
                            🛒 Add to Cart
                        </button>
                        <?php else: ?>
                        <button class="btn btn-success w-100 btn-disabled-faded"
                                disabled
                                data-bs-toggle="modal" data-bs-target="#loginModal"
                                data-action="self-enable">
                            🛒 Add to Cart
                        </button>
                        <?php endif; ?>
                        <?php else: ?>
                        <?php if (isUser() && empty($_SESSION['admin_in_store_mode'])): ?>
                        <?php $alreadyNotified = in_array((int)$p['id'], $notifiedProductIds, true); ?>
                        <form class="js-notify-form" data-product-id="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <button type="submit"
                                    class="btn w-100 js-notify-btn <?= $alreadyNotified ? 'btn-success' : 'btn-outline-warning' ?>"
                                    <?= $alreadyNotified ? 'disabled' : '' ?>>
                                <?= $alreadyNotified ? "✅ We'll notify you!" : '🔔 Notify Me' ?>
                            </button>
                        </form>
                        <?php else: ?>
                        <button class="btn btn-outline-warning w-100"
                            data-bs-toggle="modal" data-bs-target="#loginModal">
                            🔔 Notify Me (Login)
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

    </div>

    <?php if ($totalPages > 1): ?>
    <?php // ── Pagination ──────────────────────────────────── ?>
    <nav aria-label="Products pagination" class="mt-4 d-flex justify-content-center">
        <ul class="pagination">
            <?php
            $baseQuery = array_diff_key($_GET, ['page' => '']);
            $buildUrl  = fn(int $p) => '?' . http_build_query(array_merge($baseQuery, ['page' => $p]));
            ?>
            <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= htmlspecialchars($buildUrl($currentPage - 1)) ?>">‹ Prev</a>
            </li>
            <?php for ($p = max(1, $currentPage - 2); $p <= min($totalPages, $currentPage + 2); $p++): ?>
            <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="<?= htmlspecialchars($buildUrl($p)) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= htmlspecialchars($buildUrl($currentPage + 1)) ?>">Next ›</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>

</section>
</main>

<?php
// بيانات المنتجات لبطاقات المفضّلة — يقرأها js/features/products-catalog.js
// من window.dbProducts. الاسم والشكل لم يتغيّرا.
?>
<?= pageData([
    'dbProducts' => array_values(array_map(function ($p) {
        $d = $p['_display'];
        return [
            'id'         => (int) $p['id'],
            'variant_id' => $d['id'] ?? null,
            'color_name' => $d['color_name'] ?? null,
            'name'       => $p['name'],
            'price'      => (float) (($d['discount_percentage'] ?? 0) > 0
                ? $d['price_after_discount']
                : $d['price']),
            'image'      => fixImagePath($d['image_path'] ?? $p['image_path']),
            'image_path' => fixImagePath($d['image_path'] ?? $p['image_path']),
            'tag'        => productTag($p),
            'categories' => $p['categories'] ?? '',
        ];
    }, $products)),
]) ?>