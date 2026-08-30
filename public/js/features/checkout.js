// ══════════════════════════════════════════════════════════════
// js/features/checkout.js — the checkout page (three steps)
// ══════════════════════════════════════════════════════════════
//
// This file used to be a 157-line inline <script> block inside
// app/views/checkout/checkout.php. The block injected four PHP values (URLROOT, the CSRF
// token, the idempotency key, and the saved addresses) and then wrote the page's entire
// logic beneath them.
//
// The four values now arrive through data-* attributes on <main id="main-content">, and
// everything below this line is pure logic that knows nothing about PHP.
//
// Everything sits inside an IIFE deliberately: the inline version declared URLROOT,
// CSRF_TOKEN, postJson, goTo and buildReview **in the global scope**. Acceptable in a
// block belonging to one page, but in an external file loaded alongside the rest of the
// store's files it means a possible name collision — and `const URLROOT` in the global
// scope throws a SyntaxError if another file declares it.

(function () {
    'use strict';

    // Selecting by the attribute rather than the id is deliberate: the attribute describes
    // exactly what is being looked for, so it is unaffected by any duplicated id on the page
    // and assumes no particular tag.
    const root = document.querySelector('[data-checkout-urlroot]');
    if (!root) return; // We are not on the checkout page

    // ── The page's data (from data-*, not from inline PHP) ──────
    const URLROOT         = root.dataset.checkoutUrlroot;
    const CSRF_TOKEN      = root.dataset.checkoutCsrf;
    const IDEMPOTENCY_KEY = root.dataset.checkoutIdempotency;

    let SAVED_ADDRESSES = [];
    try {
        SAVED_ADDRESSES = JSON.parse(root.dataset.checkoutAddresses || '[]');
    } catch (e) {
        console.error('checkout: could not parse the saved addresses', e);
    }

    // ── The DOM elements ────────────────────────────────────────
    const steps     = [1, 2, 3].map(n => document.getElementById(`step-${n}`));
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

    // ── Sending JSON through the central CSRF safety net ────────
    // There used to be a local wrapper here called fetchWithCsrf that sent the request with
    // no retry, justifying itself on the grounds that fetchWithCsrfRetry "supports FormData
    // and urlencoded only, and this page sends JSON". **That was true when it was written,
    // and stopped being true in phase 6b-1**, which taught that function to rebuild JSON
    // bodies precisely.
    //
    // And why it matters here in particular: the token in this project is one per session,
    // so a form failing in another tab is enough to rotate it and leave this page's token
    // invalid — and the request is lost. Losing a request here means **losing a completed
    // purchase** after the user has filled in three steps.
    //
    // And retrying is safe on /checkout: the controller verifies the token **before** the
    // idempotency check and before creating any order, so a refusal means nothing has been
    // created yet. And the idempotency key survives in the rebuilt body, guarding against a
    // duplicated order regardless.
    //
    // csrf.js is loaded in the store footer before this file (both are deferred, and
    // execution follows document order), so the function is certainly defined by the time
    // what follows runs.
    function postJson(url, payload) {
        return fetchWithCsrfRetry(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
    }

    // ── Getting the selected address ────────────────────────────
    function getSelectedAddressId() {
        const checked = document.querySelector('input[name="addr_choice"]:checked');
        if (checked) return parseInt(checked.value);
        // With no saved address, a new one is built
        const full = document.getElementById('newAddrFull')?.value.trim();
        return full ? 'new' : null;
    }

    // ── Building the order summary ──────────────────────────────
    function buildReview() {
        const cart    = window.getCartData ? window.getCartData() : [];
        const list    = document.getElementById('reviewCartList');
        const totalEl = document.getElementById('reviewTotal');
        const addrEl  = document.getElementById('reviewAddress');
        const payEl   = document.getElementById('reviewPayment');

        // ⚠️ `quantity`, not `qty`. The cart's key is called quantity in all three places
        // that write it (products-catalog · product-details · wishlist), and this line read
        // `item.qty` — that is, undefined. So `price * undefined` was NaN, and the final
        // review screen before payment showed "$NaN" on every line and in the total.
        let total = 0;
        list.innerHTML = cart.map(item => {
            const qty  = Number(item.quantity) || 0;
            const line = Number(item.price) * qty;
            total += line;
            // ⚠️ `escHtml` on the name and the colour.
            //
            // They used to be injected raw into innerHTML. And both values come from the
            // database — an admin types them in Manage Products — so a product name containing
            // <img src=x onerror=…> executed on **the final review screen before payment**,
            // the worst page for that to happen on.
            //
            // And the CSP blocking inline handlers is not enough: the blocking is a second
            // layer, and escaping is the first. The other files escape already (cart.js,
            // wishlist.js and notifications.js), so this site was the exception rather than
            // the rule.
            const label = escHtml(item.name)
                + (item.color_name ? ' — ' + escHtml(item.color_name) : '');

            return `<li class="d-flex justify-content-between mb-2 small">
            <span>${label} × ${qty}</span>
            <span>$${line.toFixed(2)}</span>
        </li>`;
        }).join('') || '<li class="text-muted">Cart is empty.</li>';

        totalEl.textContent = '$' + total.toFixed(2);

        // Displaying the selected address
        const addrId = getSelectedAddressId();

        // A strict comparison, after the types are unified. The ids arrive from two
        // different sources — `parseInt` from the radio's value, and JSON from data-* — and
        // `==` was what bridged them. And `getSelectedAddressId` may return the string 'new'
        // or null, both of which become NaN and match no id — which is the wanted behaviour:
        // no saved address is selected.
        const addr = SAVED_ADDRESSES.find(a => Number(a.id) === Number(addrId));
        addrEl.textContent = addr
            ? [addr.label, addr.full_address, addr.city, addr.country].filter(Boolean).join(', ')
            : (document.getElementById('newAddrFull')?.value.trim() || '—');

        // The payment method
        const pay = document.querySelector('input[name="payment_method"]:checked');
        payEl.textContent = pay?.value === 'cash_on_delivery' ? 'Cash on Delivery' : pay?.value || '—';
    }

    // ── The step transitions ────────────────────────────────────
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

    // ── Adding a new address ────────────────────────────────────
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

        const res = await postJson(URLROOT + '/user/addresses', {
            csrf_token: CSRF_TOKEN,
            label, phone_number: phone, country, city, full_address: full, is_default: isDefault,
        });

        if (res.success) {
            Swal.fire({ icon: 'success', text: 'Address saved!' }).then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', text: res.message || 'Could not save address.' });
        }
    });

    // ── Translating the cart into the API's shape ───────────────
    //
    // The cart is stored in localStorage in its own shape, {id, quantity, …}, and the API
    // is documented in another, {product_id, qty, …}. And the two sides spoke without a
    // translation: the server read product_id, found it missing, dropped every item, and the
    // customer received "Invalid items in cart" after three steps.
    // **Nobody could complete an order.**
    //
    // The translation lives here rather than on the server deliberately: the local storage
    // shape is the client's business alone and may change, while the API's shape is a
    // contract published in OpenAPI. Teaching the server to accept both names would have
    // cemented both spellings forever.
    //
    // ⚠️ `shown_price` is the price displayed, not the price relied upon. The server
    // compares it against the database's price and refuses the order if they differ — and
    // never stores it.
    function toOrderItems(cart) {
        return cart.map(item => ({
            product_id:  Number(item.id),
            variant_id:  item.variant_id ?? null,
            qty:         Number(item.quantity) || 0,
            shown_price: Number(item.price),
        }));
    }

    // Note: variantKey and sameLine used to be here — matching a local cart line against a
    // line from the server to correct its price in place. They fell away when the cart moved
    // to the database: there is nothing left to match, since the server returns the whole
    // cart with live prices and the mirror is replaced by it.

    /**
     * Refreshes the cart after an order is refused for a price change.
     *
     * ⚠️ It now refetches from the server rather than writing locally. It used to write the
     * price into the browser's copy — correct back when the cart lived in localStorage, and
     * wrong once it moved to the database: a write into the mirror never reaches the
     * original, so it showed the customer a price the server does not know.
     *
     * And refetching is more honest than patching: `/cart` returns each line's live price
     * anyway, so what comes back is what they will actually be charged.
     */
    async function applyServerPrices(serverItems) {
        if (!serverItems.length || !window.loadCart) return;

        await window.loadCart();
    }

    /** The "before → after" table inside the dialog. The names come from the database, so they are escaped. */
    function buildPriceChangeHtml(serverItems) {
        const esc = window.escHtml || (s => String(s));
        const rows = serverItems.map(i => `
            <li class="d-flex justify-content-between gap-3 small">
                <span>${esc(i.name)}</span>
                <span><s class="text-muted">$${Number(i.shown_price).toFixed(2)}</s>
                      &nbsp;<strong>$${Number(i.price).toFixed(2)}</strong></span>
            </li>`).join('');

        return `<p class="mb-2">These prices changed while your cart was open:</p>
                <ul class="list-unstyled text-start mb-0">${rows}</ul>`;
    }

    /** Restores the order button to its normal state — this used to be repeated verbatim in three places. */
    function resetPlaceButton() {
        const btn = document.getElementById('placeOrderBtn');
        if (!btn) return;
        btn.disabled    = false;
        btn.textContent = '✅ Place Order';
    }

    // ── Placing the order ───────────────────────────────────────
    document.getElementById('placeOrderBtn')?.addEventListener('click', async () => {
        // The cart arrives from the server when the page loads. And if it has not arrived
        // yet — a slow connection, or a fast click — it is fetched now, rather than telling
        // the customer "your cart is empty" when it is not.
        if (window.getCartData && !window.getCartData().length && window.loadCart) {
            await window.loadCart();
        }

        const cart = window.getCartData ? window.getCartData() : [];
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
            const res = await postJson(URLROOT + '/checkout', {
                csrf_token:       CSRF_TOKEN,
                address_id:       addrId,
                payment_method:   paymentMethod,
                idempotency_key:  IDEMPOTENCY_KEY,
                items:            toOrderItems(cart),
            });

            // ── A price change: refresh the cart and let the customer decide ──
            //
            // The server refused the whole order and returned the correct prices. Detection
            // by error_code rather than by the message's text — the same contract as
            // csrf_invalid.
            if (!res.success && res.error_code === 'price_changed') {
                await applyServerPrices(res.items || []);
                resetPlaceButton();
                goTo(3);
                buildReview();
                Swal.fire({
                    icon:  'warning',
                    title: 'Prices Updated',
                    html:  buildPriceChangeHtml(res.items || []),
                    confirmButtonText: 'Review Cart',
                });
                return;
            }

            if (res.success) {
                // ⚠️ window.clearCart is not defined in any file — the guard here makes the
                // call inert. Existing behaviour, moved across unchanged.
                if (window.clearCart) window.clearCart();
                Swal.fire({
                    icon: 'success', title: '✅ Order Placed!',
                    text: res.message, timer: 2000, showConfirmButton: false
                }).then(() => {
                    window.location.href = res.redirect || URLROOT;
                });
            } else {
                resetPlaceButton();
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        } catch {
            resetPlaceButton();
            Swal.fire({ icon: 'error', text: 'Network error. Please try again.' });
        }
    });
})();
