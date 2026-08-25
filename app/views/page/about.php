<?php
/**
 * app/views/page/about.php
 * البيانات جاهزة من PageController::about()
 */
?>

<main id="main-content" role="main">
<section class="container py-5">

    <nav class="store-breadcrumb mb-4">
        <a href="<?= URLROOT ?>">🏠 Home</a>
        <span class="sep">/</span>
        <span class="current">About Us</span>
    </nav>

    <div class="text-center mb-5">
        <h1 class="fw-bold">About Cairo Store</h1>
        <p class="lead">Your Trusted Electronics Store</p>
    </div>

    <!-- Stats Bar -->
    <div class="row g-3 mb-5 text-center">
        <div class="col-6 col-md-3">
            <div class="card p-3">
                <div class="about-stat-value"><?= (int)$productsCount ?>+</div>
                <small class="about-stat-label">Products</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card p-3">
                <div class="about-stat-value"><?= (int)$employees ?>+</div>
                <small class="about-stat-label">Team Members</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card p-3">
                <div class="about-stat-value"><?= (int)$founded ?></div>
                <small class="about-stat-label">Founded</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card p-3">
                <div class="about-stat-value">100%</div>
                <small class="about-stat-label">Satisfaction</small>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card p-4 h-100">
                <h2 class="h3 mb-3">🏪 Who We Are</h2>
                <p>Cairo Store is a modern electronics store specialized in smartphones, laptops, gaming devices and smart accessories.</p>
                <p class="mb-0">We aim to provide high quality products with excellent customer service and affordable prices.</p>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card p-4 h-100">
                <h2 class="h3 mb-3">📋 Company Information</h2>
                <p>📅 <strong>Founded:</strong> <?= (int)$founded ?></p>
                <p>📍 <strong>Location:</strong> <?= htmlspecialchars($location) ?></p>
                <p>👥 <strong>Employees:</strong> <?= (int)$employees ?>+</p>
                <p>📞 <strong>Phone:</strong> <?= htmlspecialchars($phone) ?></p>
                <p class="mb-0">🕒 <strong>Hours:</strong> <?= htmlspecialchars($workingHours) ?></p>
            </div>
        </div>
    </div>

    <div class="card mt-4 p-4">
        <h2 class="h3 mb-3">🎯 Our Mission</h2>
        <p class="mb-0">To become one of the leading online electronics stores in the Middle East by delivering quality products and outstanding shopping experiences to every customer.</p>
    </div>

</section>
</main>