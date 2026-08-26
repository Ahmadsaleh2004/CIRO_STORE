<?php
/**
 * app/views/checkout/confirmation.php
 * صفحة تأكيد الطلب بعد النجاح
 */
?>

<main id="main-content" class="container py-5 text-center">
    <div class="card p-5 mx-auto" style="max-width:500px;">
        <div style="font-size:4rem;">✅</div>
        <h2 class="fw-bold mt-3">Order Confirmed!</h2>
        <p class="lead">Thank you for your order.</p>
        <p class="text-muted">Your Order ID: <strong>#<?= (int)($orderId ?? 0) ?></strong></p>
        <p class="small text-muted">We'll notify you once your order is taken for delivery.</p>
        <div class="d-flex gap-3 justify-content-center mt-4">
            <a href="<?= URLROOT ?>/user/info" class="btn btn-outline-dark">📋 My Orders</a>
            <a href="<?= URLROOT ?>/products"  class="btn btn-warning">🛒 Continue Shopping</a>
        </div>
    </div>
</main>
