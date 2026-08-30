<?php
/**
 * app/views/home.php
 * صفحة العرض الرئيسية (Home View)
 * تستلم البيانات جاهزة من HomeController عبر مصفوفة $data
 */
?>

<main id="main-content" role="main">

<?php // Slider ?>
<?php
// ⚠️ السلايدر يُصيَّر **على الخادم**، لا في المتصفح.
//
// كان #slider-inner فارغاً تماماً في HTML، ويملؤه
// js/features/products-catalog.js من window.dbHomeSliders. والنتيجة
// مقيسة: الملف كان الرابع عشر في طابور ثمانية عشر ملفاً، فيبقى مكان
// السلايدر فارغاً **أكثر من ثانية** بعد ظهور بقية الصفحة.
//
// وهذا أسوأ ما يمكن أن يُؤجَّل: السلايدر أول ما تقع عليه العين، وهو
// أكبر عنصر مرئي في الصفحة (LCP).
//
// البنية أدناه تطابق ما كان ينتجه renderSlider حرفاً بحرف — نفس
// الأصناف ونفس التداخل — كي لا يتغيّر شيء في home-slider.css.
// و renderSlider تبقى للتحديث الحيّ من لوحة التحكّم، لكنها لم تعد
// المصدر الوحيد للعرض الأول.
$homeSliders = $data['homeSliders'] ?? [];
?>
<section<?= $homeSliders === [] ? ' class="d-none"' : '' ?>>
    <div id="mainSlider" class="carousel slide" data-bs-ride="carousel">
        <div class="carousel-inner" id="slider-inner">
            <?php foreach ($homeSliders as $index => $slide): ?>
                <?php
                $items = $slide['items'] ?? [];
                $count = count($items);
                // نفس قاعدة الصنف في renderSlider — راجع home-slider.css
                $countClass = $count >= 5 ? 'compact-count' : 'count-' . $count;
                ?>
                <div class="carousel-item<?= $index === 0 ? ' active' : '' ?>">
                    <div class="slide-items-row <?= $countClass ?>">
                        <?php foreach ($items as $i => $item): ?>
                            <?php
                            $title = (string) ($item['title'] ?? '');
                            $desc  = (string) ($item['description'] ?? '');
                            $img   = fixImagePath($item['image_path'] ?? '');
                            // ⚠️ الشريحة الأولى **ليست** lazy: هي أكبر
                            // عنصر مرئي في الصفحة، وتأجيلها يؤجّل ما
                            // يقيسه المتصفح كـLCP. الباقي lazy عن حقّ.
                            $eager = $index === 0 && $i === 0;
                            ?>
                            <?php if (!empty($item['link_url'])): ?>
                            <a href="<?= htmlspecialchars($item['link_url'], ENT_QUOTES) ?>" class="slide-item-link">
                            <?php endif; ?>
                                <div class="slide-item">
                                    <?php // alt يفضّل العنوان: هو ما يُعرّف الصورة، والوصف يشرحها. ?>
                                    <img src="<?= htmlspecialchars($img, ENT_QUOTES) ?>"
                                         alt="<?= htmlspecialchars($title !== '' ? $title : $desc, ENT_QUOTES) ?>"
                                         class="slide-item-img"
                                         <?= $eager ? 'fetchpriority="high" decoding="async"' : 'loading="lazy"' ?>>
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
        // الأزرار ديناميكية من CategoryModel::getAllOrdered() — الأساسية أولاً
        foreach ($categories as $cat):
            $emoji = categoryEmoji($cat['name']);
        ?>
        <a href="<?= URLROOT ?>/products?cat=<?= urlencode($cat['name']) ?>"
           class="btn btn-outline-dark px-4 py-2">
            <?php // @escaping-safe: categoryEmoji ترجع رمزاً من خريطة داخلية ?>
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
// بيانات المنتجات والسلايدر — جُهّزت في HomeController، والعرض يتولّاه
// js/features/products-catalog.js الذي يقرأ window.dbProducts و
// window.dbHomeSliders. الأسماء لم تتغيّر؛ تغيّر طريق وصولها فقط.
?>
<?= pageData([
    'dbProducts'    => $data['productsJS']  ?? [],
    'dbHomeSliders' => $data['homeSliders'] ?? [],
]) ?>