// ══════════════════════════════════════════════════════════════
// js/features/cart.js — the shopping cart engine and stock synchronisation
// ══════════════════════════════════════════════════════════════

// ══════════════════════════════════════════════════════════════
// The storage layer — the server is the source of truth, and memory is its mirror
// ══════════════════════════════════════════════════════════════
//
// The cart used to live entirely in localStorage: it did not follow the user between
// devices, and it vanished when browser data was cleared or in a private window — and
// losing a full cart is a lost sale, not a UI annoyance. And what people put in and did
// not buy never reached the server at all.
//
// ── Why a mirror in memory rather than an async call at every site ──
//
// `getCartData()` is called from six files (checkout · products-catalog ·
// product-details · wishlist · ui · this one), and all of them assume it is
// **synchronous**. Making it async means an `await` at every site, and every site that
// forgets the await reads a Promise as though it were an array — a silent fault that
// surfaces late.
//
// So the mirror keeps the contract as it is: reads are synchronous from memory, and only
// writes go to the server and refresh the mirror from its response. Which means six files
// do not change, and the change is confined to this file and the three add sites.
//
// ── And the server responds with the whole cart after every change ──
//
// which is what resolves the two-tab conflict with no extra logic: whoever makes a
// change sees both their own result and the other tab's in the same response.

/** The cart's mirror. The real source is the cart_items table on the server. */
let cartCache = [];

/** Is the user signed in? A visitor has no cart at all — the buttons are hidden from them. */
function cartEnabled() {
    return Boolean(document.getElementById('cartSidebar'));
}

function cartUrl(path) {
    const base = window.BASE_URL || window.URLROOT || '';
    return base + path;
}

function getCartData() {
    return cartCache;
}
window.getCartData = getCartData;

/**
 * Reads the cart from the server and fills the mirror.
 *
 * Called on page load and after every change. Its failure does not empty the mirror: a
 * momentary network outage must not show an empty cart to somebody filling one.
 */
async function loadCart() {
    if (!cartEnabled()) return;

    try {
        const res  = await fetch(cartUrl('/cart'), { headers: { Accept: 'application/json' } });
        const data = await res.json();
        if (data.success && Array.isArray(data.items)) {
            cartCache = data.items;
            refreshCartUI();
        }
    } catch (e) {
        console.error('cart: could not fetch the cart from the server', e);
    }
}
window.loadCart = loadCart;

/**
 * The in-flight change per variant — it stops rapid clicks piling up.
 *
 * ⚠️ Without it, every click reads the mirror **before** the previous click's response
 * arrives, and so sees a stale quantity. The effect was measured: ten rapid clicks on a
 * product with five in stock all passed the browser's stock check, because each of them
 * saw "zero in the cart".
 *
 * The server now caps at the stock, so the database does not lie. But the chaining here
 * also prevents the flicker — a number that jumps and then springs back — and saves nine
 * network round trips out of ten.
 *
 * And the key is the variant rather than a global one: changing one line does not block
 * changing another.
 *
 * @type {Map<string|number, Promise<boolean>>}
 */
const inFlight = new Map();

/** Serialises changes to the same variant, and leaves different ones in parallel. */
function serialise(key, task) {
    const previous = inFlight.get(key) ?? Promise.resolve(true);
    // A catch, so one failure does not bring down the whole chain for this variant.
    const next = previous.catch(() => false).then(task);

    inFlight.set(key, next);
    next.finally(() => {
        if (inFlight.get(key) === next) inFlight.delete(key);
    });

    return next;
}

/**
 * Performs a change on the server and refreshes the mirror from its response.
 *
 * ⚠️ The mirror is refreshed from **the response** rather than from a local guess. An
 * optimistic update (change locally then send) shows the customer a state that may never
 * have happened — and a cart showing what is not in it is worse than a slow cart.
 */
async function cartMutate(path, payload) {
    if (!cartEnabled()) return false;

    try {
        const data = await fetchWithCsrfRetry(cartUrl(path), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ csrf_token: window._csrfToken || '', ...payload }),
        });

        if (Array.isArray(data.items)) {
            cartCache = data.items;
            refreshCartUI();
        }

        if (!data.success && data.message && typeof showToast === 'function') {
            showToast(data.message, 'error');
        }

        return Boolean(data.success);
    } catch (e) {
        console.error('cart: the cart change failed', e);
        if (typeof showToast === 'function') showToast('Network error. Please try again.', 'error');
        return false;
    }
}

/** Add a quantity — summed with what is already on the server, and serialised per variant. */
async function cartAdd(productId, variantId, qty = 1) {
    return serialise(variantId, () => cartMutate('/cart/add', {
        product_id: Number(productId),
        variant_id: Number(variantId),
        qty:        Number(qty) || 1,
    }));
}
window.cartAdd = cartAdd;

/** Set a line's quantity absolutely — zero removes it. */
async function cartSetQuantity(variantId, qty) {
    return serialise(variantId, () => cartMutate('/cart/update', { variant_id: Number(variantId), qty: Number(qty) }));
}
window.cartSetQuantity = cartSetQuantity;

/** Remove a line. */
async function cartRemove(variantId) {
    return serialise(variantId, () => cartMutate('/cart/remove', { variant_id: Number(variantId) }));
}
window.cartRemove = cartRemove;

/**
 * Sets the mirror directly and re-renders — **with no write to the server**.
 *
 * ⚠️ It used to be called `saveCart` and wrote to localStorage. After the cart moved to
 * the server it kept the same name "for compatibility" while saving nothing — a name
 * promising what it does not do, which is worse than the function's absence: whoever reads
 * `saveCart(cart)` believes their cart was saved.
 *
 * And a sweep of its callers proved it has not one caller in production: every write goes
 * through cartAdd, cartSetQuantity and cartRemove. So it has one legitimate use left —
 * seeding the mirror in tests without a server — and the new name says so.
 */
function setCartMirror(items) {
    if (Array.isArray(items)) {
        cartCache = items;
    }
    refreshCartUI();
}
window.setCartMirror = setCartMirror;

function refreshCartUI() {
    if (typeof updateCounters === 'function') updateCounters();
    renderCart();

    // ── Telling the rest of the page that the mirror changed ─────
    //
    // This function is the single passage for every cart change: loadCart goes through it
    // after the initial fetch, and setCartMirror after every add, change or delete the
    // server answers. So emitting the event here means one place that cannot be forgotten,
    // rather than a line added at every operation.
    //
    // And why an event rather than a direct call: the product details page needs to
    // recompute the quantity counter's ceiling after every change (available = stock minus
    // what is in the cart), and cart.js does not know it exists and should not. The event
    // inverts the direction: whoever cares listens, and the source carries no list of who
    // cares.
    document.dispatchEvent(new CustomEvent('cart:updated', {
        detail: { items: cartCache },
    }));
}
window.refreshCartUI = refreshCartUI;

function renderCart() {
    const cartContainer = document.getElementById("cart-items-list");
    const cartTotal     = document.getElementById("cart-total");
    if (!cartContainer) return;

    let cart = getCartData();
    if (cart.length === 0) {
        cartContainer.innerHTML = `<li class="text-center py-5 u-placeholder">Your cart is empty.</li>`;
        if (cartTotal) cartTotal.innerText = "$0.00";
    } else {
        let total = 0;
        cartContainer.innerHTML = cart.map(item => {
            total += item.price * item.quantity;
            const vId = item.variant_id ?? '';
            const colorLabel = item.color_name ? ` <span class="small u-o-75">(${escHtml(item.color_name)})</span>` : '';
            return `
                <li class="mb-3 p-2 border-bottom border-secondary">
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="${window.BASE_URL}/product?id=${item.id}" class="text-white text-decoration-none fw-bold">${escHtml(item.name)}${colorLabel}</a>
                        <button class="btn btn-sm btn-outline-danger remove-item" data-id="${item.id}" data-variant-id="${vId}" aria-label="Remove ${escHtml(item.name)} from cart">✖</button>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small>$${item.price} each</small>
                        <div class="d-flex align-items-center gap-2">
                            <button class="btn btn-sm btn-outline-light minus" data-id="${item.id}" data-variant-id="${vId}" aria-label="Decrease quantity">-</button>
                            <span class="px-2 fw-bold" aria-label="Quantity: ${item.quantity}">${item.quantity}</span>
                            <button class="btn btn-sm btn-outline-light plus" data-id="${item.id}" data-variant-id="${vId}" aria-label="Increase quantity">+</button>
                        </div>
                    </div>
                </li>`;
        }).join('');
        if (cartTotal) cartTotal.innerText = `$${total.toFixed(2)}`;
    }
}
window.renderCart = renderCart;

/**
 * Corrects the cart after the stock changes beneath it.
 *
 * ══════════════════════════════════════════════════════════════
 * ⚠️ A measured and fixed fault: the correction never left the browser
 * ══════════════════════════════════════════════════════════════
 *
 * The previous version built `updatedCart` with a reduce and changed the quantity
 * **inside the loop**. And `getCartData()` returns the mirror by reference, so
 * `item.quantity = info.stock` wrote into the mirror itself. Then came the filter deciding
 * what to send to the server, and it compared:
 *
 *     info.stock < cart.find(...).quantity
 *
 * and quantity had already become info.stock — so the condition `x < x` is always false,
 * and cartSetQuantity was never called.
 *
 * The effect: the screen shows the reduced quantity while the server keeps the old one.
 * So the customer reaches checkout and is refused with out_of_stock, over a cart that
 * looks perfectly fine in front of them.
 *
 * ── And why the /cart/check-stock call disappeared ──────────
 *
 * Because it became redundant. `/cart` joins product_variants on every read, so it
 * returns live `stock` and `price` with each line and drops the hidden and deleted ones on
 * its own. So the mirror **is** the fresh stock data — and calling a second endpoint to
 * fetch it was a network round trip asking for what the answer already held.
 *
 * What remains is real work: an out-of-stock line is removed, and a quantity beyond what
 * is available is reduced — both written to the server, and then read back from it.
 */
async function syncCartWithStock() {
    const cart = getCartData();
    if (cart.length === 0) return;

    // Read before any change: the customer's original intent is captured so it can be
    // compared against what is available. Reading after the change is exactly what brought
    // the previous version down.
    const soldOut = cart.filter(i => i.variant_id && Number(i.stock) <= 0);
    const excess  = cart.filter(i =>
        i.variant_id && Number(i.stock) > 0 && Number(i.quantity) > Number(i.stock)
    );

    if (soldOut.length === 0 && excess.length === 0) return;

    // The names are captured now: after loadCart, the removed lines are gone from the mirror.
    const soldOutNames = soldOut.map(i => i.name + (i.color_name ? ` (${i.color_name})` : ''));
    const excessNames  = excess.map(i => `${i.name} (${i.stock})`);

    try {
        await Promise.all([
            ...soldOut.map(i => cartRemove(i.variant_id)),
            ...excess.map(i => cartSetQuantity(i.variant_id, Number(i.stock))),
        ]);
    } catch (e) {
        console.error('syncCartWithStock: could not correct the cart', e);
        return;
    }

    // Read from the server after correcting — memory is not trusted after a write.
    await loadCart();

    if (soldOutNames.length > 0 && typeof showToast === 'function') {
        showToast(`Removed (out of stock): ${soldOutNames.join(', ')}`, 'error');
    }

    if (excessNames.length > 0 && typeof showToast === 'function') {
        showToast(`Quantity adjusted to available stock: ${excessNames.join(', ')}`, 'info');
    }
}

window.syncCartWithStock = syncCartWithStock;

// Wiring the cart's interactions to the DOM
document.addEventListener("DOMContentLoaded", () => {
    document.addEventListener("click", (e) => {
        if (e.target.closest("#cart-items-list")) {
            const btn = e.target;
            const id  = parseInt(btn.dataset.id);
            const variantIdRaw = btn.dataset.variantId;
            const variantId    = variantIdRaw ? parseInt(variantIdRaw) : null;
            if (!id) return;

            const item = getCartData().find(
                p => p.id === id && (p.variant_id ?? null) == (variantId ?? null)
            );
            if (!item || !variantId) return;

            // ⚠️ The quantity is sent **absolutely**, not as a delta (+1/−1).
            //
            // A delta assumes that what is on the screen is what is in the database, an
            // assumption that falls over on two rapid clicks or a second tab: two deltas
            // arrive and the total becomes three instead of two. An absolute value makes the
            // last click win — which is the behaviour whoever presses the button expects.
            if (btn.classList.contains("plus")) {
                const maxStock = typeof item.stock === 'number' ? item.stock : Infinity;
                if (item.quantity >= maxStock) {
                    if (typeof showToast === 'function') showToast('No more stock available for this item.', 'error');
                    return;
                }
                cartSetQuantity(variantId, item.quantity + 1);
            } else if (btn.classList.contains("minus")) {
                // Zero is a deletion on the server, so decrementing from one removes the line.
                cartSetQuantity(variantId, item.quantity - 1);
            } else if (btn.classList.contains("remove-item")) {
                cartRemove(variantId);
                if (typeof showToast === 'function') showToast('Product removed from cart', 'info');
            }
        }
    });

    // The cart is fetched from the server first, and its stock is checked afterwards.
    //
    // The order is required: syncCartWithStock works on what is in the mirror, and the
    // mirror is empty before /cart answers. Running it first would have checked nothing and
    // then said nothing.
    loadCart().then(() => {
        if (getCartData().length > 0) {
            syncCartWithStock();
        }
    });

    const offcanvasEl = document.getElementById('cartSidebar');
    if (offcanvasEl) {
        offcanvasEl.addEventListener('show.bs.offcanvas', () => {
            renderCart();
            syncCartWithStock();
        });
    }

});
