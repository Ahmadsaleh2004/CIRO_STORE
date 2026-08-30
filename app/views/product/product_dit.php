<?php
/**
 * app/views/product/show.php
 * Markup matching the original Task(1) design exactly (the same classes, the same
 * English, the same JavaScript interactions).
 * The variables coming from ProductController::show():
 * $p, $variants, $selectedVariant, $reviews, $avgRating, $myReview, $related,
 * $price, $discount, $finalPrice, $stock, $imgSrc, $csrf, $notified, $userLoggedIn
 */

// Colours sorted ascending by stock (closest to running out first) — for the "Show all colors" accordion
$sortedByStock = $variants;
usort($sortedByStock, fn($a, $b) => (int)$a['stock_quantity'] <=> (int)$b['stock_quantity']);
// Note: head.php, navbar.php and footer.php are not included here —
// App\Core\Controller::view() does that automatically (head + navbar + this file + footer).
?>

<main id="main-content" class="container py-5">

    <?php // ── Breadcrumb ── ?>
    <nav class="store-breadcrumb mb-4">
        <a href="<?= URLROOT ?>">🏠 Home</a>
        <span class="sep">/</span>
        <a href="<?= URLROOT ?>/products">Products</a>
        <span class="sep">/</span>
        <span class="current"><?= htmlspecialchars($p['name']) ?></span>
    </nav>

    <?php // ── Product Detail ─────────────────────────────── ?>
    <div class="row g-5 align-items-center mb-5">

        <?php // Gallery ?>
        <div class="col-lg-6">
            <div class="zoom-wrapper position-relative">
                <?php if ($discount > 0): ?>
                <span class="discount-badge badge-zindex" id="discountBadge">-<?= (float)$discount ?>%</span>
                <?php endif; ?>
                <?php $mainWebp = getWebpPath($imgSrc); ?>
                <picture>
                    <?php if ($mainWebp): ?>
                    <source srcset="<?= htmlspecialchars($mainWebp) ?>" type="image/webp">
                    <?php endif; ?>
                    <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($p['name']) ?>" id="productMainImg" class="product-detail-main-img">
                </picture>
            </div>
        </div>

        <?php // Info ?>
        <div class="col-lg-6">
            <h1 class="fw-bold mb-2"><?= htmlspecialchars($p['name']) ?></h1>

            <?php // Rating ?>
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="rating-stars-yellow">
                    <?php for ($i = 1; $i <= 5; $i++) echo $i <= $avgRating ? '★' : '☆'; ?>
                </span>
                <small class="reviews-count-text">
                    <?= number_format($avgRating, 1) ?> (<?= count($reviews) ?> reviews)
                </small>
            </div>

            <?php // Price ?>
            <div class="price-box mb-3">
                <span class="new-price">$<?= number_format($finalPrice, 2) ?></span>
                <?php if ($discount > 0): ?>
                <span class="old-price ms-2">$<?= number_format($price, 2) ?></span>
                <?php endif; ?>
            </div>

            <?php if (count($variants) > 1): ?>
            <?php // ── Color Swatches ─────────────────────────── ?>
            <div class="mb-3" id="colorSwatches">
                <label class="fw-bold d-block mb-2">Color: <span id="selectedColorName">
                    <?= htmlspecialchars($selectedVariant['color_name']) ?></span></label>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($variants as $v): ?>
                    <button type="button"
                        class="btn btn-outline-secondary btn-sm color-swatch-btn <?= $v['id'] == $selectedVariant['id'] ? 'active' : '' ?>"
                        data-variant-id="<?= (int)$v['id'] ?>"
                        <?php /* The colour comes from the database, so it cannot become a class,
                                 and it cannot stay a style= attribute once the CSP is
                                 tightened. It leaves here as data in data-swatch, and
                                 js/store/variant-swatches.js writes it into a custom CSS
                                 property through the CSSOM — which the CSP does not block,
                                 because the value never appears in the markup. */ ?>
                        <?= $v['color_hex'] ? 'data-swatch="' . htmlspecialchars($v['color_hex']) . '"' : '' ?>
                        <?= (int)$v['stock_quantity'] <= 0 ? 'title="Out of stock"' : '' ?>>
                        <?= htmlspecialchars($v['color_name']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php // ── "Show all colors" Accordion ── ?>
            <div class="mb-4">
                <button type="button" class="btn btn-link p-0 text-decoration-none fw-bold" id="toggleAllColorsBtn"
                        aria-expanded="false" aria-controls="allColorsPanel">
                    <span id="toggleArrowIcon">▾</span> Show all colors &amp; stock details
                </button>
                <div id="allColorsPanel" class="d-none mt-2 p-3 rounded border">
                    <?php foreach ($sortedByStock as $i => $v):
                        $vPrice = (float)($v['discount_percentage'] > 0 ? $v['price_after_discount'] : $v['price']);
                        $isLowStockFlag = (int)$v['stock_quantity'] > 0 && $i === 0;
                    ?>
                    <div class="d-flex justify-content-between align-items-center py-2 <?= $i > 0 ? 'border-top' : '' ?>">
                        <div>
                            <strong><?= htmlspecialchars($v['color_name']) ?></strong>
                            <?php if ($isLowStockFlag): ?>
                            <span class="badge bg-danger ms-2">⚡ Almost sold out</span>
                            <?php endif; ?>
                            <?php if ((int)$v['stock_quantity'] <= 0): ?>
                            <span class="badge bg-secondary ms-2">Out of stock</span>
                            <?php endif; ?>
                            <div class="small u-muted">
                                Gender: <?= htmlspecialchars(ucfirst($v['gender_category'] ?? '')) ?> · Stock: <?= (int)$v['stock_quantity'] ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold">$<?= number_format($vPrice, 2) ?></div>
                            <?php if ((float)$v['discount_percentage'] > 0): ?>
                            <div class="small text-decoration-line-through old-price-sub">$<?= number_format($v['price'], 2) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <p class="product-description mb-4"><?= htmlspecialchars($p['description'] ?? '') ?></p>

            <?php // Specs ?>
            <div class="product-specs mb-4 p-3 rounded">
                <div class="row g-2">
                    <?php if (!empty($p['manufacturer'])): ?>
                    <div class="col-sm-6">
                        <span class="spec-label">🏷️ Brand:</span>
                        <span class="spec-value"> <?= htmlspecialchars($p['manufacturer']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($p['country_of_origin'])): ?>
                    <div class="col-sm-6">
                        <span class="spec-label">🌍 Origin:</span>
                        <span class="spec-value"> <?= htmlspecialchars($p['country_of_origin']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($p['date_added'])): ?>
                    <div class="col-sm-12 mt-1">
                        <span class="spec-label">📅 Date Added:</span>
                        <span class="spec-value"> <?= date('d M Y', strtotime($p['date_added'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php /*
Stock badge — the same getStockBadge() the product list uses, plus the
                 green branch that belongs to the details page alone
*/ ?>
            <?php $sb = getStockBadge($stock, true); ?>
            <div class="mb-3"><span class="badge <?= $sb['class'] ?> fs-6" id="stockBadge"><?= htmlspecialchars($sb['label']) ?></span></div>

            <?php // ── Qty + Cart block ── ?>
            <div id="qtyCartBlock" class="<?= $stock > 0 ? '' : 'd-none' ?>">
                <?php /*
`max` here is only an initial value — the absolute stock, before the
                 browser knows what is in the cart. js/features/product-details.js resets
                 it immediately to (stock − this variant's quantity in the cart), and
                 again after every cart change, through the `cart:updated` event.

                 It cannot be computed here once and for all: the cart changes after
                 the page renders — a line is removed in the sidebar and the availability
                 comes back — so a value PHP computes once goes stale immediately.
*/ ?>
                <div class="quantity-box mb-4">
                    <button class="btn btn-outline-secondary" id="minusBtn" aria-label="Decrease quantity">−</button>
                    <input type="number" value="1" min="1" max="<?= $stock ?>"
                           id="productQty" class="form-control quantity-input qty-input-md">
                    <button class="btn btn-outline-secondary" id="plusBtn" aria-label="Increase quantity">+</button>
                </div>

                <?php // Filled in by product-details.js: "You have 2 in your cart — 3 left". ?>
                <p id="qtyRemainingHint" class="small u-muted mb-3 d-none" aria-live="polite"></p>
                <div class="d-flex gap-2">
                    <?php if ($userLoggedIn && empty($_SESSION['admin_in_store_mode'] ?? false)): ?>
                    <button id="addCartBtn" class="btn btn-success btn-lg px-5" <?= $stock <= 0 ? 'disabled' : '' ?>>🛒 Add To Cart</button>
                    <?php else: ?>
                    <button id="addCartBtn"
                            class="btn btn-success btn-lg px-5 btn-disabled-faded"
                            disabled
                            data-bs-toggle="modal" data-bs-target="#loginModal"
                            data-action="self-enable">
                        🛒 Add To Cart
                    </button>
                    <?php endif; ?>
                    <button id="wishBtn" class="btn btn-outline-danger btn-lg">🤍</button>
                </div>
            </div>

            <?php // ── Notify Me block ── ?>
            <div id="notifyBlock" class="<?= $stock > 0 ? 'd-none' : '' ?>">
                <?php if ($alreadyRequested): ?>
                <div class="alert alert-success py-2">✅ We'll notify you when this product is back in stock!</div>
                <?php elseif ($userLoggedIn && empty($_SESSION['admin_in_store_mode'] ?? false)): ?>
                <form class="js-notify-form" data-product-id="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <button type="submit"
                            class="btn btn-lg w-100 js-notify-btn <?= $alreadyRequested ? 'btn-success' : 'btn-outline-warning' ?>"
                            <?= $alreadyRequested ? 'disabled' : '' ?>>
                        <?= $alreadyRequested ? "✅ We'll notify you!" : '🔔 Notify Me When Available' ?>
                    </button>
                </form>
                <?php else: ?>
                <button class="btn btn-outline-warning btn-lg"
                        data-bs-toggle="modal" data-bs-target="#loginModal">
                    🔔 Notify Me (Login Required)
                </button>
                <?php endif; ?>
                <div id="wishBtnStandalone" class="mt-2 <?= $stock <= 0 ? '' : 'd-none' ?>">
                    <button id="wishBtn2" class="btn btn-outline-danger">🤍 Add to Wishlist</button>
                </div>
            </div>

        </div>
    </div>

    <hr class="my-5">

    <?php // ── Reviews ────────────────────────────────────── ?>
    <h2 class="section-title">⭐ Reviews & Ratings</h2>

    <?php if ($userLoggedIn && empty($_SESSION['admin_in_store_mode'] ?? false)): ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-3"><?= $myReview ? '✏️ Edit Your Review' : '+ Add Your Review' ?></h5>
        <?php
        $toastMessage = $reviewMsg ?? '';
        $toastType    = 'success';
        require APPROOT . '/views/shared/flash-toast.php';
        $toastMessage = $reviewErr ?? '';
        $toastType    = 'error';
        require APPROOT . '/views/shared/flash-toast.php';
        ?>
        <form method="POST" action="<?= URLROOT ?>/product?id=<?= (int)$p['id'] ?>">
            <input type="hidden" name="submit_review" value="1">
            <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrf) ?>">
            <div class="mb-3">
                <label class="fw-bold mb-2">Rating <span class="text-danger">*</span></label>
                <div id="starWidget">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="star-span <?= ($myReview && $myReview['rating'] >= $i) ? 'active' : '' ?>"
                          data-val="<?= $i ?>">
                        <?= ($myReview && $myReview['rating'] >= $i) ? '★' : '☆' ?>
                    </span>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="rating" id="ratingInput" value="<?= (int)($myReview['rating'] ?? 0) ?>">
            </div>
            <div class="float-group">
                <textarea name="comment" rows="3" placeholder=" "><?= htmlspecialchars($myReview['comment'] ?? '') ?></textarea>
                <label>Comment (optional)</label>
            </div>
            <button id="reviewSubmitBtn" type="submit" class="btn btn-success btn-disabled-faded"
                    disabled aria-disabled="true">Submit Review</button>
        </form>
    </div>
    <?php else: ?>
    <div class="alert alert-info py-2 mb-4">
        <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal">Login</a> to leave a review.
    </div>
    <?php endif; ?>

    <?php if (empty($reviews)): ?>
    <p class="no-reviews-text">No reviews yet. Be the first!</p>
    <?php else: ?>
    <?php foreach ($reviews as $rv): ?>
    <div class="review-card">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <strong><?= htmlspecialchars($rv['full_name']) ?></strong>
            <small class="review-date-text"><?= date('d M Y', strtotime($rv['created_at'])) ?></small>
        </div>
        <div class="review-stars-yellow">
            <?php for ($i = 1; $i <= 5; $i++) echo $i <= $rv['rating'] ? '★' : '☆'; ?>
        </div>
        <?php if (!empty($rv['comment'])): ?>
        <p class="small mb-0 mt-1"><?= nl2br(htmlspecialchars($rv['comment'])) ?></p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <hr class="my-5">

    <?php // ── Related ────────────────────────────────────── ?>
    <?php if (!empty($related)): ?>
    <h2 class="section-title">You May Also Like</h2>
    <div class="row">
        <?php foreach ($related as $r):
            $rPrice = (float)($r['discount_percentage'] > 0 ? $r['price_after_discount'] : $r['price']);
        ?>
        <div class="col-lg-3 col-md-6 mb-4">
            <a href="<?= URLROOT ?>/product?id=<?= (int)$r['id'] ?>"
               class="image-only-product reveal">
                <?php $relWebp = getWebpPath($r['image_path'] ?? ''); ?>
                <picture>
                    <?php if ($relWebp): ?>
                    <source srcset="<?= htmlspecialchars($relWebp) ?>" type="image/webp">
                    <?php endif; ?>
                    <img src="<?= htmlspecialchars(fixImagePath($r['image_path'] ?? '')) ?>"
                         class="img-fill" alt="<?= htmlspecialchars($r['name']) ?>" loading="lazy">
                </picture>
                <div class="price-overlay">$<?= number_format($rPrice, 2) ?></div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<?php
// The product's data and its colour variants — js/features/product-details.js reads them
// from window.PRODUCT_ID, PRODUCT_NAME, PRODUCT_VARIANTS and SELECTED_VARIANT_ID. The
// names have not changed.
?>
<?= pageData([
    'PRODUCT_ID'          => (int) $p['id'],
    'PRODUCT_NAME'        => $p['name'],
    'PRODUCT_VARIANTS'    => array_map(function ($v) {
        return [
            'id'          => (int) $v['id'],
            'color_name'  => $v['color_name'],
            'price'       => (float) $v['price'],
            'discount'    => (float) $v['discount_percentage'],
            'final_price' => (float) ($v['discount_percentage'] > 0
                ? $v['price_after_discount']
                : $v['price']),
            'stock'       => (int) $v['stock_quantity'],
            'image'       => fixImagePath($v['image_path'] ?? ''),
        ];
    }, $variants),
    'SELECTED_VARIANT_ID' => (int) $selectedVariant['id'],
]) ?>