<?php
/**
 * app/views/checkout/checkout.php
 * صفحة الدفع — 3 خطوات: العنوان → الدفع → المراجعة
 * البيانات تأتي جاهزة من CheckoutController::index()
 */
?>
<style>
    .step-bar { display:flex; justify-content:center; gap:0; margin-bottom:2rem; }
    .step-item { display:flex; flex-direction:column; align-items:center; gap:5px; flex:1; max-width:150px; position:relative; }
    .step-circle { width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center;
        font-weight:700; border:2px solid var(--section-border); background:var(--card-bg); color:var(--text-color); transition:.3s; }
    .step-item.active .step-circle  { background:var(--accent); border-color:var(--accent); color:#fff; }
    .step-item.done   .step-circle  { background:#16a34a; border-color:#16a34a; color:#fff; }
    .step-label { font-size:.72rem; color:var(--muted-text); }
    .step-item.active .step-label { color:var(--accent); font-weight:600; }
    .step-item:not(:last-child)::after { content:''; position:absolute; top:19px; left:calc(50% + 19px);
        width:calc(100% - 38px); height:2px; background:var(--section-border); }
    .step-item.done:not(:last-child)::after { background:#16a34a; }
    .checkout-step  { display:none; }
    .checkout-step.active { display:block; }
    .trust-badges { display:flex; flex-wrap:wrap; gap:12px; justify-content:center; margin-top:14px; }
    .trust-badge  { display:flex; align-items:center; gap:5px; font-size:.78rem; color:var(--muted-text); }
    .addr-radio:checked + .addr-label { border-color:var(--accent)!important; }
    .addr-label { border:2px solid var(--section-border)!important; border-radius:10px; padding:12px 14px;
        cursor:pointer; display:block; transition:.2s; }
    .addr-label:hover { border-color:var(--accent)!important; }
</style>

<main id="main-content" class="container py-5">
    <h1 class="text-center fw-bold mb-4">🛒 Checkout</h1>

    <!-- Step Bar -->
    <div class="step-bar">
        <div class="step-item active" id="si-1"><div class="step-circle">1</div><div class="step-label">Address</div></div>
        <div class="step-item"        id="si-2"><div class="step-circle">2</div><div class="step-label">Payment</div></div>
        <div class="step-item"        id="si-3"><div class="step-circle">3</div><div class="step-label">Review</div></div>
    </div>

    <div class="row justify-content-center">
    <div class="col-lg-8">

        <!-- ── Step 1: Address ──────────────────────── -->
        <div class="checkout-step active" id="step-1">
        <div class="card p-4">
            <h4 class="mb-4">📍 Delivery Address</h4>

            <?php if (!empty($addresses)): ?>
            <div class="row g-2 mb-3">
                <?php foreach ($addresses as $i => $addr): ?>
                <div class="col-md-6">
                    <input type="radio" name="addr_choice" id="addr_<?= $addr['id'] ?>"
                           class="addr-radio visually-hidden"
                           value="<?= $addr['id'] ?>"
                           <?= ($addr['is_default'] || $i === 0) ? 'checked' : '' ?>>
                    <label class="addr-label w-100" for="addr_<?= $addr['id'] ?>">
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

            <!-- فورم عنوان جديد -->
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
                    <input type="checkbox" id="newAddrDefault" style="width:16px;height:16px;">
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

        <!-- ── Step 2: Payment ──────────────────────── -->
        <div class="checkout-step" id="step-2">
        <div class="card p-4">
            <h4 class="mb-4">💳 Payment Method</h4>
            <div class="d-flex flex-column gap-3">
                <label class="addr-label d-flex align-items-center gap-3 cursor-pointer">
                    <input type="radio" name="payment_method" value="cash_on_delivery" checked class="addr-radio">
                    <span>💵 Cash on Delivery</span>
                </label>
                <label class="addr-label d-flex align-items-center gap-3 cursor-pointer" style="opacity:.5;pointer-events:none;">
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

        <!-- ── Step 3: Review ───────────────────────── -->
        <div class="checkout-step" id="step-3">
        <div class="card p-4">
            <h4 class="mb-3">📋 Order Summary</h4>
            <ul id="reviewCartList" class="list-unstyled mb-3"></ul>
            <div class="d-flex justify-content-between fw-bold border-top pt-3">
                <span>Total:</span>
                <span id="reviewTotal">$0.00</span>
            </div>
            <div class="mt-3 p-3 rounded" style="background:var(--card-bg);border:1px solid var(--section-border);">
                <strong>📍 Delivery Address</strong>
                <p id="reviewAddress" class="mb-0 small mt-1">—</p>
            </div>
            <div class="mt-2 p-3 rounded" style="background:var(--card-bg);border:1px solid var(--section-border);">
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

    </div><!-- /col -->
    </div><!-- /row -->
</main>

<script>
// ── بيانات الصفحة ────────────────────────────────────────────
const URLROOT           = "<?= URLROOT ?>";
const CSRF_TOKEN        = "<?= htmlspecialchars($csrf) ?>";
const IDEMPOTENCY_KEY   = window.CHECKOUT_IDEMPOTENCY_KEY || "<?= bin2hex(random_bytes(8)) ?>";
const SAVED_ADDRESSES   = <?= json_encode(array_values($addresses ?? []), JSON_UNESCAPED_UNICODE) ?>;

// ── عناصر DOM ───────────────────────────────────────────────
const steps   = [1, 2, 3].map(n => document.getElementById(`step-${n}`));
const stepItems = [1, 2, 3].map(n => document.getElementById(`si-${n}`));

function goTo(n) {
    steps.forEach((s, i) => {
        s.classList.toggle('active', i + 1 === n);
        stepItems[i].classList.remove('active', 'done');
        if (i + 1 < n)  stepItems[i].classList.add('done');
        if (i + 1 === n) stepItems[i].classList.add('active');
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── ناقلات الخطوات ──────────────────────────────────────────
document.getElementById('toStep2Btn')?.addEventListener('click', () => {
    if (!getSelectedAddressId()) {
        Swal.fire({ icon: 'warning', title: 'Address Required', text: 'Please select or add a delivery address.' });
        return;
    }
    goTo(2);
});
document.getElementById('backToStep1Btn')?.addEventListener('click', () => goTo(1));
document.getElementById('toStep3Btn')?.addEventListener('click', () => { buildReview(); goTo(3); });
document.getElementById('backToStep2Btn')?.addEventListener('click', () => goTo(2));

// ── إضافة عنوان جديد ────────────────────────────────────────
document.getElementById('saveNewAddrBtn')?.addEventListener('click', async () => {
    const label   = document.getElementById('newAddrLabel').value.trim()   || 'Home';
    const phone   = document.getElementById('newAddrPhone').value.trim()   || '';
    const country = document.getElementById('newAddrCountry').value.trim() || '';
    const city    = document.getElementById('newAddrCity').value.trim()    || '';
    const full    = document.getElementById('newAddrFull').value.trim()    || '';
    const isDefault = document.getElementById('newAddrDefault')?.checked ? 1 : 0;

    if (!full) {
        Swal.fire({ icon: 'warning', text: 'Please enter the full address.' });
        return;
    }

    const res = await fetchWithCsrf(URLROOT + '/user/addresses', 'POST', {
        csrf_token: CSRF_TOKEN,
        label, phone_number: phone, country, city, full_address: full, is_default: isDefault,
    });

    if (res.success) {
        Swal.fire({ icon: 'success', text: 'Address saved!' }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', text: res.message || 'Could not save address.' });
    }
});

// ── الحصول على العنوان المختار ───────────────────────────────
function getSelectedAddressId() {
    const checked = document.querySelector('input[name="addr_choice"]:checked');
    if (checked) return parseInt(checked.value);
    // إذا لم يكن هناك عنوان محفوظ فنبني عنواناً جديداً
    const full = document.getElementById('newAddrFull')?.value.trim();
    return full ? 'new' : null;
}

// ── بناء ملخص الطلب ─────────────────────────────────────────
function buildReview() {
    const cart    = window.getCartData ? window.getCartData() : [];
    const list    = document.getElementById('reviewCartList');
    const totalEl = document.getElementById('reviewTotal');
    const addrEl  = document.getElementById('reviewAddress');
    const payEl   = document.getElementById('reviewPayment');

    let total = 0;
    list.innerHTML = cart.map(item => {
        total += item.price * item.qty;
        return `<li class="d-flex justify-content-between mb-2 small">
            <span>${item.name}${item.color_name ? ' — ' + item.color_name : ''} × ${item.qty}</span>
            <span>$${(item.price * item.qty).toFixed(2)}</span>
        </li>`;
    }).join('') || '<li class="text-muted">Cart is empty.</li>';

    totalEl.textContent = '$' + total.toFixed(2);

    // عرض العنوان المختار
    const addrId = getSelectedAddressId();
    const addr   = SAVED_ADDRESSES.find(a => a.id == addrId);
    addrEl.textContent = addr
        ? [addr.label, addr.full_address, addr.city, addr.country].filter(Boolean).join(', ')
        : (document.getElementById('newAddrFull')?.value.trim() || '—');

    // طريقة الدفع
    const pay = document.querySelector('input[name="payment_method"]:checked');
    payEl.textContent = pay?.value === 'cash_on_delivery' ? 'Cash on Delivery' : pay?.value || '—';
}

// ── تنفيذ الطلب ──────────────────────────────────────────────
document.getElementById('placeOrderBtn')?.addEventListener('click', async () => {
    const cart     = window.getCartData ? window.getCartData() : [];
    if (!cart.length) {
        Swal.fire({ icon: 'warning', text: 'Your cart is empty.' });
        return;
    }

    const addrId = getSelectedAddressId();
    if (!addrId || addrId === 'new') {
        Swal.fire({ icon: 'warning', text: 'Please select a saved delivery address.' });
        return;
    }

    const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value || 'cash_on_delivery';

    document.getElementById('placeOrderBtn').disabled = true;
    document.getElementById('placeOrderBtn').textContent = '⏳ Placing Order…';

    try {
        const res = await fetchWithCsrf(URLROOT + '/checkout', 'POST', {
            csrf_token:       CSRF_TOKEN,
            address_id:       addrId,
            payment_method:   paymentMethod,
            idempotency_key:  IDEMPOTENCY_KEY,
            items:            cart,
        });

        if (res.success) {
            if (window.clearCart) window.clearCart();
            Swal.fire({
                icon: 'success', title: '✅ Order Placed!',
                text: res.message, timer: 2000, showConfirmButton: false
            }).then(() => {
                window.location.href = res.redirect || URLROOT;
            });
        } else {
            document.getElementById('placeOrderBtn').disabled = false;
            document.getElementById('placeOrderBtn').textContent = '✅ Place Order';
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }
    } catch (e) {
        document.getElementById('placeOrderBtn').disabled = false;
        document.getElementById('placeOrderBtn').textContent = '✅ Place Order';
        Swal.fire({ icon: 'error', text: 'Network error. Please try again.' });
    }
});

// ── دالة fetch مع CSRF ───────────────────────────────────────
async function fetchWithCsrf(url, method, data) {
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(data),
    });
    return res.json();
}
</script>
