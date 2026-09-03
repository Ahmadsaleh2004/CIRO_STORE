<?php
/**
 * app/views/home.php
 * The home page view.
 * It receives its data ready from HomeController through the $data array.
 */
?>

<main id="main-content" role="main">

<?php // Slider ?>
<?php
// ⚠️ The slider is rendered **on the server**, not in the browser.
//
// #slider-inner used to be entirely empty in the HTML, filled by
// js/features/products-catalog.js from window.dbHomeSliders. The result was measured:
// that file was fourteenth in a queue of eighteen, so the slider's space stayed empty
// for **more than a second** after the rest of the page appeared.
//
// And that is the worst thing to defer: the slider is the first thing the eye lands on,
// and it is the page's largest contentful paint.
//
// The structure below matches what renderSlider produced character for character — the
// same classes and the same nesting — so nothing in home-slider.css has to change.
// renderSlider remains, for live updates from the admin panel, but it is no longer the
// only source of the first render.
$homeSliders = $data['homeSliders'] ?? [];
?>
<section<?= $homeSliders === [] ? ' class="d-none"' : '' ?>>
    <div id="mainSlider" class="carousel slide" data-bs-ride="carousel">
        <div class="carousel-inner" id="slider-inner">
            <?php foreach ($homeSliders as $index => $slide): ?>
                <?php
                $items = $slide['items'] ?? [];
                $count = count($items);
                // The same class rule as in renderSlider — see home-slider.css
                $countClass = $count >= 5 ? 'compact-count' : 'count-' . $count;
                ?>
                <div class="carousel-item<?= $index === 0 ? ' active' : '' ?>">
                    <div class="slide-items-row <?= $countClass ?>">
                        <?php foreach ($items as $i => $item): ?>
                            <?php
                            $title = (string) ($item['title'] ?? '');
                            $desc  = (string) ($item['description'] ?? '');
                            $img   = fixImagePath($item['image_path'] ?? '');
                            // ⚠️ The first slide is **not** lazy: it is the page's largest
                            // contentful paint, and deferring it defers what the browser
                            // measures as LCP. The rest are lazy, rightly.
                            $eager = $index === 0 && $i === 0;
                            ?>
                            <?php if (!empty($item['link_url'])): ?>
                            <a href="<?= htmlspecialchars($item['link_url'], ENT_QUOTES) ?>" class="slide-item-link">
                            <?php endif; ?>
                                <div class="slide-item">
                                    <?php
                                    // ⚠️ WebP, through <picture> — and the difference is not marginal.
                                    //
                                    // The product cards have used <picture> for a long time; the slider
                                    // never did, so the largest image on the site was the one served
                                    // raw. Measured on this install: ipad.jpg is 3.3 MB and ipad.webp,
                                    // sitting beside it on disk, is 63 KB. airpods pro.jpg is 1.8 MB
                                    // against 24 KB. Seven slides came to roughly 7 MB where 200 KB
                                    // would do — on the page whose largest contentful paint this is,
                                    // and on the phone that has to wait for it.
                                    //
                                    // getWebpPath returns a path only when the .webp actually exists on
                                    // disk, so a slide whose image has no twin falls through to the
                                    // <img> below untouched.
                                    $itemWebp = getWebpPath($item['image_path'] ?? '');
                                    ?>
                                    <picture>
                                        <?php if ($itemWebp): ?>
                                        <source srcset="<?= htmlspecialchars($itemWebp, ENT_QUOTES) ?>" type="image/webp">
                                        <?php endif; ?>
                                    <?php // alt prefers the title: the title identifies the image, the description explains it ?>
                                    <img src="<?= htmlspecialchars($img, ENT_QUOTES) ?>"
                                         alt="<?= htmlspecialchars($title !== '' ? $title : $desc, ENT_QUOTES) ?>"
                                         class="slide-item-img"
                                         <?= $eager ? 'fetchpriority="high" decoding="async"' : 'loading="lazy"' ?>>
                                    </picture>
                                    <?php if ($title !== '' || $desc !== ''): ?>
                                    <div class="slide-item-caption">
                                        <?php if ($title !== ''): ?>
                                        <div class="slide-item-title"><?= htmlspecialchars($title) ?></div>
                                        <?php endif; ?>
                                        <?php if ($desc !== ''): ?>
                                        <div class="slide-item-desc"><?= htmlspecialchars($desc) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php if (!empty($item['link_url'])): ?>
                            </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="carousel-control-prev" type="button" data-bs-target="#mainSlider" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Previous slide</span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#mainSlider" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Next slide</span>
        </button>
    </div>
</section>

<?php // Shop By Category ?>
<section class="container py-5">
    <h2 class="section-title">Shop By Category</h2>
    <div class="d-flex justify-content-center flex-wrap gap-3">
        <?php
        // The buttons come dynamically from CategoryModel::getAllOrdered() — the core ones first
        foreach ($categories as $cat):
            $emoji = categoryEmoji($cat['name']);
        ?>
        <a href="<?= URLROOT ?>/products?cat=<?= urlencode($cat['name']) ?>"
           class="btn btn-outline-dark px-4 py-2">
            <?php // @escaping-safe: categoryEmoji returns a symbol from an internal map ?>
            <?= $emoji ?> <?= htmlspecialchars(ucfirst($cat['name'])) ?>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<?php // Best Sellers ?>
<section class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="section-title mb-0">Best Sellers</h2>
        <a href="<?= URLROOT ?>/products" class="section-view-all">View All →</a>
    </div>
    <div class="section-carousel-wrapper">
        <button class="section-carousel-btn prev-btn" data-target="best-sellers-track">&#8249;</button>
        <div class="section-carousel-track" id="best-sellers-track"></div>
        <button class="section-carousel-btn next-btn" data-target="best-sellers-track">&#8250;</button>
    </div>
</section>

<?php // New Arrivals ?>
<section class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="section-title mb-0">New Arrivals</h2>
        <a href="<?= URLROOT ?>/products" class="section-view-all">View All →</a>
    </div>
    <div class="section-carousel-wrapper">
        <button class="section-carousel-btn prev-btn" data-target="new-arrivals-track">&#8249;</button>
        <div class="section-carousel-track" id="new-arrivals-track"></div>
        <button class="section-carousel-btn next-btn" data-target="new-arrivals-track">&#8250;</button>
    </div>
</section>

<?php // Explore More ?>
<section class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="section-title mb-0">Explore More</h2>
        <a href="<?= URLROOT ?>/products" class="section-view-all">View All →</a>
    </div>
    <div class="section-carousel-wrapper">
        <button class="section-carousel-btn prev-btn" data-target="other-products-track">&#8249;</button>
        <div class="section-carousel-track" id="other-products-track"></div>
        <button class="section-carousel-btn next-btn" data-target="other-products-track">&#8250;</button>
    </div>
</section>

</main>

<?php
// The product and slider data — prepared in HomeController, with the rendering handled
// by js/features/products-catalog.js, which reads window.dbProducts and
// window.dbHomeSliders. The names have not changed; only how they arrive has.
?>
<?= pageData([
    'dbProducts'    => $data['productsJS']  ?? [],
    // Each item gains `webp`: the path of its WebP twin, or null when there is none.
    // renderSlider cannot work this out for itself — deriving ".webp" in the browser and
    // hoping produces a <source> pointing at a 404, and a chosen source that fails does
    // not fall back to the <img>; it shows nothing at all.
    'dbHomeSliders' => array_map(static function (array $slide): array {
        $slide['items'] = array_map(static function (array $item): array {
            $item['webp'] = getWebpPath($item['image_path'] ?? '');
            return $item;
        }, $slide['items'] ?? []);
        return $slide;
    }, $data['homeSliders'] ?? []),
]) ?>