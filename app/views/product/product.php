<?php
// app/views/product/index.php

$pageTitle = 'Products';
$pageDescription = 'Browse all products at Cairo Store.';

// productTag() moved to app/helpers/product_tag_helper.php
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

    <?php /*
── Filters ─────────────────────────────────────────────

     This was one <select id="sort"> holding four optgroups — name, price,
     category and price band — and a select carries exactly one value. So the
     four groups were mutually exclusive by construction: choosing a category
     cancelled the sort, choosing a sort cancelled the category. You could
     never see "the cheapest headphones, A to Z", because the control had no
     way to hold two answers at once.

     Four independent controls now, in a panel of their own. Name and Price
     are single-choice, since a list has one order; Categories are checkboxes
     because a shop that adds a category — headphones beside accessories and
     phones — should let you tick both. The categories are read from the
     database, so a new one appears here without an edit.

     The panel also takes the four rows the toolbar used to spend: the search
     field, the select, the price slider and a full-width Reset each had a
     line of their own on a phone, about 400px of chrome before the first
     product. The toolbar is one row now and the rest is behind the button.
*/ ?>
    <div class="catalog-toolbar d-flex align-items-center gap-2 mb-4">
        <div id="search-wrapper">
            <input type="text" id="search" class="form-control form-control-sm" placeholder="Search products...">
            <ul id="autocomplete-list"></ul>
        </div>

        <div class="dropdown">
            <button class="btn btn-outline-primary btn-sm dropdown-toggle"
                    type="button"
                    id="catalogFilterBtn"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    <?php /* Popper anchors the panel to the button, and on a 375px screen a
                            320px panel hung 113px off the right edge — measured. Static
                            display hands the positioning to CSS, which pins it to the
                            toolbar instead. See .catalog-filter-panel in products.css. */ ?>
                    data-bs-display="static"
                    aria-expanded="false">
                🔀 Sort &amp; Filter
                <span class="badge bg-primary ms-1 d-none" id="catalogFilterCount">0</span>
            </button>

            <?php /* dropdown-menu-end: with Popper switched off the panel is left-aligned to
                     the button by default, and the button sits near the right edge — measured
                     at 768px, where it hung 44px past it. Aligning its right edge to the
                     button's fixes every width above 575px; below that the panel is pinned to
                     the toolbar instead and products.css overrides both offsets. */ ?>
            <div class="dropdown-menu dropdown-menu-end p-3 catalog-filter-panel" id="catalogFilterPanel">

                <?php // Name — one order at a time, so radios wearing button clothes ?>
                <p class="fw-bold small mb-1">🔤 By Name</p>
                <div class="catalog-btn-group mb-3">
                    <input type="radio" class="btn-check" name="nameSort" id="nameSortNone" value="" checked>
                    <label class="btn btn-sm btn-outline-secondary" for="nameSortNone">None</label>
                    <input type="radio" class="btn-check" name="nameSort" id="nameSortAz" value="az">
                    <label class="btn btn-sm btn-outline-secondary" for="nameSortAz">A → Z</label>
                    <input type="radio" class="btn-check" name="nameSort" id="nameSortZa" value="za">
                    <label class="btn btn-sm btn-outline-secondary" for="nameSortZa">Z → A</label>
                </div>

                <?php // Price — same shape. When both are set, price leads and name breaks ties. ?>
                <p class="fw-bold small mb-1">💰 By Price</p>
                <div class="catalog-btn-group mb-3">
                    <input type="radio" class="btn-check" name="priceSort" id="priceSortNone" value="" checked>
                    <label class="btn btn-sm btn-outline-secondary" for="priceSortNone">None</label>
                    <input type="radio" class="btn-check" name="priceSort" id="priceSortLow" value="low">
                    <label class="btn btn-sm btn-outline-secondary" for="priceSortLow">Low → High</label>
                    <input type="radio" class="btn-check" name="priceSort" id="priceSortHigh" value="high">
                    <label class="btn btn-sm btn-outline-secondary" for="priceSortHigh">High → Low</label>
                </div>

                <hr class="filter-divider">

                <?php // Categories — checkboxes, any match, straight from the database ?>
                <p class="fw-bold small mb-1">🏷️ Categories <span class="text-muted fw-normal">(any match)</span></p>
                <div class="mb-3">
                    <?php
                    foreach ($categories as $cat):
                        $catValue = strtolower($cat['name']);
                        $emoji    = categoryEmoji($cat['name']);
                    ?>
                    <div class="form-check">
                        <input class="form-check-input catalog-cat" type="checkbox"
                               value="<?= htmlspecialchars($catValue) ?>"
                               id="catalogCat_<?= htmlspecialchars($catValue) ?>">
                        <label class="form-check-label small" for="catalogCat_<?= htmlspecialchars($catValue) ?>">
                            <?php // @escaping-safe: categoryEmoji returns a symbol from an internal map ?>
                            <?= $emoji ?> <?= htmlspecialchars(ucfirst($cat['name'])) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>

                <hr class="filter-divider">

                <?php // Price band and the slider — both narrow the range, and they compose ?>
                <p class="fw-bold small mb-1">💵 Price Range</p>
                <div class="catalog-btn-group mb-2">
                    <input type="radio" class="btn-check" name="priceBand" id="priceBandNone" value="" checked>
                    <label class="btn btn-sm btn-outline-secondary" for="priceBandNone">Any</label>
                    <input type="radio" class="btn-check" name="priceBand" id="priceBandU100" value="u100">
                    <label class="btn btn-sm btn-outline-secondary" for="priceBandU100">Under $100</label>
                    <input type="radio" class="btn-check" name="priceBand" id="priceBandU300" value="u300">
                    <label class="btn btn-sm btn-outline-secondary" for="priceBandU300">Under $300</label>
                    <input type="radio" class="btn-check" name="priceBand" id="priceBandU500" value="u500">
                    <label class="btn btn-sm btn-outline-secondary" for="priceBandU500">Under $500</label>
                    <input type="radio" class="btn-check" name="priceBand" id="priceBandO500" value="o500">
                    <label class="btn btn-sm btn-outline-secondary" for="priceBandO500">$500 &amp; Above</label>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <input type="range" id="priceRange" min="0" max="2000" value="2000" class="form-range">
                    <span id="priceRangeVal" class="small fw-bold price-range-val">≤$2000</span>
                </div>
            </div>
        </div>

        <button id="reset" class="btn btn-secondary btn-sm">Reset</button>
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
// The product data for the wishlist cards — js/features/products-catalog.js reads it
// from window.dbProducts. The name and the shape have not changed.
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