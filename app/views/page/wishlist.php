<?php
/**
 * app/views/page/wishlist.php
 * المحتوى بيتبني بالكامل جافاسكريبت (js/wishlist.js) من localStorage
 */
?>

<main id="main-content" role="main">
<section class="container py-5">

    <nav class="store-breadcrumb mb-4">
        <a href="<?= URLROOT ?>">🏠 Home</a>
        <span class="sep">/</span>
        <span class="current">My Wishlist</span>
    </nav>

    <h1 class="section-title">My Wishlist</h1>

    <div id="wishlist-container" class="row"></div>

</section>
</main>

<script>
    window.__isRegularUser        = <?= $isRegularUser ? 'true' : 'false' ?>;
    window.__csrfTokenForWishlist = "<?= htmlspecialchars($csrf, ENT_QUOTES) ?>";
</script>