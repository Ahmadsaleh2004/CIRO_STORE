<?php
/**
 * app/views/checkout/checkout.php
 * صفحة الدفع — 3 خطوات: العنوان → الدفع → المراجعة
 * البيانات تأتي جاهزة من CheckoutController::index()
 */
?>
<?php // كتلة <style> المضمّنة نُقلت إلى css/store/pages/checkout.css ?>

<main id="main-content" class="container py-5"
      data-checkout-urlroot="<?= URLROOT ?>"
      data-checkout-csrf="<?= htmlspecialchars($csrf) ?>"
      data-checkout-idempotency="<?= htmlspecialchars($idempotencyKey) ?>"
      data-checkout-addresses='<?= htmlspecialchars(json_encode(array_values($addresses ?? []), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>
    <h1 class="text-center fw-bold mb-4">🛒 Checkout</h1>

    <?php // Step Bar ?>
    <div class="step-bar">
        <div class="step-item active" id="si-1"><div class="step-circle">1</div><div class="step-label">Address</div></div>
        <div class="step-item"        id="si-2"><div class="step-circle">2</div><div class="step-label">Payment</div></div>
        <div class="step-item"        id="si-3"><div class="step-circle">3</div><div class="step-label">Review</div></div>
    </div>

    <div class="row justify-content-center">
    <div class="col-lg-8">

        <?php // ── Step 1: Address ──────────────────────── ?>
        <div class="checkout-step active" id="step-1">
        <div class="card p-4">
            <h4 class="mb-4">📍 Delivery Address</h4>

            <?php if (!empty($addresses)): ?>
            <div class="row g-2 mb-3">
                <?php foreach ($addresses as $i => $addr): ?>
                <div class="col-md-6">
                    <input type="radio" name="addr_choice" id="addr_<?= (int)$addr['id'] ?>"
                           class="addr-radio visually-hidden"
                           value="<?= (int)$addr['id'] ?>"
                           <?= ($addr['is_default'] || $i === 0) ? 'checked' : '' ?>>
                    <label class="addr-label w-100" for="addr_<?= (int)$addr['id'] ?>">
                        <strong><?= htmlspecialchars($addr['label'] ?? 'Home') ?></strong><br>
                        <small><?= htmlspecialchars($addr['city'] ?? '') ?><?= ($addr['city'] && $addr['country']) ? ', ' : '' ?><?= htmlspecialchars($addr['country'] ?? '') ?></small><br>
                        <small><?= htmlspecialchars($addr['full_address']) ?></small>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <hr>
            <p class="text-muted small mb-3">Or add a new address:</p>
            <?php endif; ?>

            <?php // فورم عنوان جديد ?>
            <div id="newAddrForm">
                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="float-group">
                            <input type="text" id="newAddrLabel" placeholder=" ">
                            <label>Label (Home / Work…)</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="float-group">
                            <input type="tel" id="newAddrPhone" placeholder=" ">
                            <label>Phone Number</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="float-group">
                            <input type="text" id="newAddrCountry" placeholder=" ">
                            <label>Country</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="float-group">
                            <input type="text" id="newAddrCity" placeholder=" ">
                            <label>City</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="float-group">
                            <textarea id="newAddrFull" rows="2" placeholder=" "></textarea>
                            <label>Full Address</label>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <input type="checkbox" id="newAddrDefault" class="u-w-16">
                    <label for="newAddrDefault" class="small">Set as default</label>
                </div>
                <button type="button" class="btn btn-outline-success btn-sm" id="saveNewAddrBtn">
                    ➕ Save Address
                </button>
            </div>

            <div class="mt-4 text-end">
                <button class="btn btn-dark px-5" id="toStep2Btn">Next: Payment →</button>
            </div>
        </div>
        </div>

        <?php // ── Step 2: Payment ──────────────────────── ?>
        <div class="checkout-step" id="step-2">
        <div class="card p-4">
            <h4 class="mb-4">💳 Payment Method</h4>
            <div class="d-flex flex-column gap-3">
                <label class="addr-label d-flex align-items-center gap-3 cursor-pointer">
                    <input type="radio" name="payment_method" value="cash_on_delivery" checked class="addr-radio">
                    <span>💵 Cash on Delivery</span>
                </label>
                <label class="addr-label d-flex align-items-center gap-3 cursor-pointer u-inert">
                    <input type="radio" name="payment_method" value="credit_card" disabled class="addr-radio">
                    <span>💳 Credit/Debit Card <small class="text-muted">(Coming Soon)</small></span>
                </label>
            </div>
            <div class="d-flex justify-content-between mt-4">
                <button class="btn btn-outline-secondary" id="backToStep1Btn">← Back</button>
                <button class="btn btn-dark px-5" id="toStep3Btn">Review Order →</button>
            </div>
        </div>
        </div>

        <?php // ── Step 3: Review ───────────────────────── ?>
        <div class="checkout-step" id="step-3">
        <div class="card p-4">
            <h4 class="mb-3">📋 Order Summary</h4>
            <ul id="reviewCartList" class="list-unstyled mb-3"></ul>
            <div class="d-flex justify-content-between fw-bold border-top pt-3">
                <span>Total:</span>
                <span id="reviewTotal">$0.00</span>
            </div>
            <div class="mt-3 p-3 rounded u-surface-card">
                <strong>📍 Delivery Address</strong>
                <p id="reviewAddress" class="mb-0 small mt-1">—</p>
            </div>
            <div class="mt-2 p-3 rounded u-surface-card">
                <strong>💵 Payment:</strong> <span id="reviewPayment">Cash on Delivery</span>
            </div>
            <p class="small text-muted mt-2">📜 <?= htmlspecialchars($returnPolicy ?? '') ?></p>
            <div class="trust-badges">
                <span class="trust-badge">🔒 Secure Checkout</span>
                <span class="trust-badge">🚚 Fast Delivery</span>
                <span class="trust-badge">↩️ Easy Returns</span>
            </div>
            <div class="d-flex justify-content-between mt-4">
                <button class="btn btn-outline-secondary" id="backToStep2Btn">← Back</button>
                <button class="btn btn-warning fw-bold px-5" id="placeOrderBtn">
                    ✅ Place Order
                </button>
            </div>
        </div>
        </div>

    </div><?php // /col ?>
    </div><?php // /row ?>
</main>

<?php // منطق الصفحة في js/features/checkout.js — البيانات تصله عبر data-* على <main> ?>

