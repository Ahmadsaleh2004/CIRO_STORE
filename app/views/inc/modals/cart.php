<?php
/**
 * app/views/inc/modals/cart.php
 * Cart Sidebar (Offcanvas) — Partial فقط، يُستدعى من footer.php
 * منقول من components/footer.php القديم (سطر 67–89)
 */
?>
<!-- ══ Cart Sidebar ════════════════════════════════════════ -->
<div class="offcanvas offcanvas-end" id="cartSidebar" tabindex="-1" aria-labelledby="cartSidebarLabel">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title fw-bold" id="cartSidebarLabel">🛒 Your Shopping Cart</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <ul id="cart-items-list" class="list-unstyled" aria-label="Cart items">
            <li class="text-center py-5" style="color:var(--muted-text);">Your cart is empty.</li>
        </ul>
        <div class="mt-4 pt-3 border-top">
            <div class="d-flex justify-content-between mb-3">
                <strong>Total:</strong>
                <span id="cart-total" class="fw-bold">$0.00</span>
            </div>
            <button class="btn btn-warning w-100 fw-bold"
                onclick="window.location.href='<?= URLROOT ?>/checkout'">
                Proceed To Checkout
            </button>
        </div>
    </div>
</div>
