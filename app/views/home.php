<?php
/**
 * app/views/home.php
 * صفحة العرض الرئيسية (Home View)
 * تستلم البيانات جاهزة من HomeController عبر مصفوفة $data
 */
?>

<main id="main-content" role="main">

<!-- Slider -->
<section>
    <div id="mainSlider" class="carousel slide" data-bs-ride="carousel">
        <div class="carousel-inner" id="slider-inner"></div>
        <button class="carousel-control-prev" type="button" data-bs-target="#mainSlider" data-bs-slide="prev">
            <span class="carousel-control-prev-icon"></span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#mainSlider" data-bs-slide="next">
            <span class="carousel-control-next-icon"></span>
        </button>
    </div>
</section>

<!-- Shop By Category -->
<section class="container py-5">
    <h2 class="section-title">Shop By Category</h2>
    <div class="d-flex justify-content-center flex-wrap gap-3">
        <?php
        // الأزرار ديناميكية من CategoryModel::getAllOrdered() — الأساسية أولاً
        foreach ($categories as $cat):
            $emoji = categoryEmoji($cat['name']);
        ?>
        <a href="<?= URLROOT ?>/products?cat=<?= urlencode($cat['name']) ?>"
           class="btn btn-outline-dark px-4 py-2">
            <?= $emoji ?> <?= htmlspecialchars(ucfirst($cat['name'])) ?>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- Best Sellers -->
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

<!-- New Arrivals -->
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

<!-- Explore More -->
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

<script>
// استقبال بيانات المنتجات التي تم تجهيزها داخل HomeController وتمريرها لـ JS
window.dbProducts = <?= json_encode($data['productsJS'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
window.dbHomeSliders = <?= json_encode($data['homeSliders'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

document.addEventListener('DOMContentLoaded', () => {
    renderSlider(window.dbHomeSliders); // بدل الاعتماد فقط على منتجات ثابتة

    if (window.dbProducts && window.dbProducts.length > 0) {
        const prods = window.dbProducts.map(p => ({
            ...p,
            image: p.image_path || p.image || '',
        }));
        renderHomeSections(prods); // renderHomeSections لم تعد تستدعي renderSlider داخلياً
    }
});
</script>