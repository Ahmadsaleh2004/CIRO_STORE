/**
 * js/features/wishlist.js — Wishlist with live stock/price data
 */

let wishlist = JSON.parse(localStorage.getItem('wishlist') || '[]');
let liveStockData = {};

const REGULAR_USER_FLAG = Object.freeze({ value: window.__isRegularUser === true });

function canAddToCart() {
    return REGULAR_USER_FLAG.value === true
        && typeof window.__csrfTokenForWishlist === 'string'
        && window.__csrfTokenForWishlist.length > 0;
}

document.addEventListener('DOMContentLoaded', async () => {
    const container = document.getElementById('wishlist-container');
    if (container && wishlist.length > 0) {
        container.innerHTML = Array(Math.min(wishlist.length, 3)).fill(`
        <div class="col-lg-4 col-md-6 mb-4">
            <div class="skeleton-card">
                <div class="skeleton skeleton-img"></div>
                <div class="skeleton skeleton-line"></div>
                <div class="skeleton skeleton-line short"></div>
            </div>
        </div>`).join('');
    }
    await renderWishlist();
});

// getStockBadgeJs was removed — the rule now lives in stockBadge() in js/core/utils.js,
// mirroring getStockBadge() in PHP. This page does not show the green "in stock" badge (a
// badge on every card is visual noise), so the second argument stays false.

// [FIX] The path was updated from /handlers/product_stock_handler.php to the new route
/**
 * Fetches live stock and visibility for the given ids into liveStockData.
 *
 * Returns whether the server actually answered. That boolean is what renderWishlist relies on
 * before it deletes anything — see the comment there.
 *
 * `res.ok` is checked before parsing: during an outage, or while Render is waking the
 * container, this endpoint answers a 503 HTML page, and parsing it as JSON throws.
 */
async function fetchLiveStock(ids) {
    try {
        const params = new URLSearchParams();
        ids.forEach(id => params.append('ids[]', id));
        const res = await fetch(window.BASE_URL + '/handlers/product_stock_handler.php?' + params.toString());
        if (!res.ok) return false;

        const data = await res.json();
        if (!data.success) return false;

        liveStockData = { ...liveStockData, ...data.products };
        return true;
    } catch {
        return false;
    }
}

// ══════════════════════════════════════════════════════════════
// A wishlist card's counter ceiling = the stock **minus what is in the cart**
// ══════════════════════════════════════════════════════════════
//
// The same principle as the details page and the list cards: stop them before the choice
// rather than after it. The counter here used to cap at the absolute stock, and the user
// then discovered on pressing "Add to Cart" that what is in their cart counts against
// them — through a toast
// «You already have the maximum available quantity».
//
// ── A case belonging to the wishlist alone ──────────────────
//
// Wishlist items are stored in localStorage, and some are old ones with no `variant_id`
// — the field was added later. So matching a cart line by variant_id alone would have
// failed on those items and returned "zero in the cart", that is, a ceiling wider than the
// truth.
//
// So: when the variant is known it is matched, and otherwise every cart line for this
// product is summed. And the stock displayed here is the product's, not the variant's (it
// comes from WishlistController::stock), so the sum is its correct counterpart.
function cartQtyForWishlist(productId, variantId) {
    const cart = window.getCartData ? window.getCartData() : [];
    const pid  = Number(productId);

    if (variantId === '' || variantId === null || variantId === undefined) {
        return cart
            .filter(i => Number(i.id) === pid)
            .reduce((sum, i) => sum + (Number(i.quantity) || 0), 0);
    }

    const vId  = Number(variantId);
    const line = cart.find(i => Number(i.id) === pid && Number(i.variant_id) === vId);

    return line ? Number(line.quantity) || 0 : 0;
}

/** What is genuinely available for a card, from its field's attributes. */
function wishlistRemaining(input) {
    const stock = Number(input.getAttribute('data-wl-stock')) || 0;
    const inCart = cartQtyForWishlist(
        input.getAttribute('data-wl-qty-input'),
        input.getAttribute('data-wl-variant')
    );

    return Math.max(0, stock - inCart);
}

/** Sets every card's ceiling and its buttons' state from what is available. */
function applyWishlistQtyLimits() {
    document.querySelectorAll('[data-wl-qty-input]').forEach(input => {
        const id        = input.getAttribute('data-wl-qty-input');
        const remaining = wishlistRemaining(input);
        const stock     = Number(input.getAttribute('data-wl-stock')) || 0;
        const inCart    = stock - remaining;

        input.max = String(remaining);
        const value = Math.min(Math.max(1, Number(input.value) || 1), Math.max(remaining, 1));
        input.value = remaining === 0 ? 0 : value;

        const plusBtn = document.querySelector(`[data-wl-qty="${id}"][data-wl-delta="1"]`);
        if (plusBtn) plusBtn.disabled = remaining === 0 || Number(input.value) >= remaining;

        // As on the details page: a button disabled for another reason is not touched — a
        // signed-out visitor sees it disabled with data-action="self-enable" so it opens the
        // login modal.
        const addBtn = document.querySelector(`.add-to-cart-wl[data-id="${id}"]`);
        if (addBtn && !addBtn.hasAttribute('data-action')) {
            addBtn.disabled = remaining === 0;
        }

        const hint = document.querySelector(`[data-wl-hint="${id}"]`);
        if (hint) {
            if (remaining === 0 && inCart > 0) {
                hint.textContent = `All ${inCart} available in your cart.`;
                hint.classList.remove('d-none');
            } else if (inCart > 0) {
                hint.textContent = `${inCart} in your cart — ${remaining} more available.`;
                hint.classList.remove('d-none');
            } else {
                hint.classList.add('d-none');
            }
        }
    });
}
window.applyWishlistQtyLimits = applyWishlistQtyLimits;

// The cart changes after the cards render — removing a line in the sidebar must restore
// the availability here immediately. The event is emitted by refreshCartUI in cart.js.
document.addEventListener('cart:updated', applyWishlistQtyLimits);

window.changeWishlistQty = (id, val) => {
    const input = document.getElementById('qty-' + id);
    if (!input) return;

    // ⚠️ The ceiling is read from what is available, not from the stock.
    //
    // The function used to take `stock` as a third argument from an attribute on the
    // button, capping at the absolute stock and saying "Only N left in stock" — a message
    // that is true about the stock and false about what the user can actually add.
    const remaining = wishlistRemaining(input);

    let v = (parseInt(input.value, 10) || 1) + val;
    if (v < 1) v = 1;

    if (remaining > 0 && v > remaining) {
        v = remaining;
        if (typeof showToast === 'function') {
            showToast(`Only ${remaining} more available.`, 'warning');
        }
    }

    input.value = remaining === 0 ? 0 : v;
    applyWishlistQtyLimits();
};

async function renderWishlist() {
    const container = document.getElementById('wishlist-container');
    if (!container) return;

    wishlist = JSON.parse(localStorage.getItem('wishlist') || '[]');

    if (!wishlist.length) {
        container.innerHTML = `
        <div class="col-12 text-center py-5 fade-in-up">
            <div class="u-fs-5r">💔</div>
            <h3 class="mt-3 fw-bold u-text">Your Wishlist is Empty</h3>
            <p class="mt-2 u-placeholder">Save your favorite products and come back anytime.</p>
            <a href="${window.BASE_URL}/products" class="btn btn-success mt-3 px-4 py-2">🛍️ Browse Products</a>
        </div>`;
        return;
    }

    const validWishlistIds = wishlist
        .map(p => parseInt(p.id, 10))
        .filter(id => Number.isInteger(id) && id > 0);

    const missingIds = validWishlistIds
        .filter(id => !(String(id) in liveStockData));

    // Draw what localStorage already holds before asking the server anything. The page used
    // to show skeletons until the stock request returned — a database round trip from
    // Render, and the whole wait for a cold container — while everything a card shows
    // except its stock was already in the browser. The cards come back below with their
    // stock once the server answers.
    if (missingIds.length > 0) drawWishlist(true);

    const serverAnswered = missingIds.length === 0 || await fetchLiveStock(missingIds);

    // Auto-remove products the server says are gone: hidden by an admin, or deleted.
    //
    // ⚠️ "The server says" is the whole rule, and it used to be missing. The filter kept an
    // item only when live data existed for it — so when the stock request FAILED, no item had
    // live data, every item was dropped, and the empty list was written back to localStorage.
    // A database outage, or a single 503 while Render woke the container, permanently erased
    // the wishlist of every visitor who opened this page. The wishlist lives only in the
    // browser, so there was nothing to restore it from.
    //
    // Now an item is removed only on evidence: the server answered and marked it hidden, or
    // the server answered and did not return it at all. With no answer, every item stays and
    // is drawn from what localStorage already holds.
    const stillVisibleWishlist = wishlist.filter(p => {
        const live = liveStockData[String(parseInt(p.id, 10))];
        if (live) return live.is_visible !== 0;
        return !serverAnswered;
    });
    if (stillVisibleWishlist.length !== wishlist.length) {
        wishlist = stillVisibleWishlist;
        localStorage.setItem('wishlist', JSON.stringify(wishlist));
    }

    drawWishlist(false);
}

/**
 * Renders the cards from `wishlist` and `liveStockData`, and wires their controls.
 *
 * With `pending`, a card whose stock has not arrived yet shows the in-stock layout with every
 * control disabled: it holds the space the quantity box takes, so the card does not jump when
 * the stock lands, and it offers nothing that would have to be refused a moment later.
 */
function drawWishlist(pending) {
    const container = document.getElementById('wishlist-container');
    if (!container) return;

    const isUser = REGULAR_USER_FLAG.value;
    const csrf = window.__csrfTokenForWishlist || '';

    container.innerHTML = wishlist.map(p => {
        const imgSrc = window.imagePathOrEmpty(p.image_path || p.image || '');
        const name   = escHtml(p.name);
        const normalizedId = parseInt(p.id, 10);
        const live   = liveStockData[String(normalizedId)];
        const stock  = live ? live.stock_quantity : null;
        const currentPrice = live ? (live.discount_percentage > 0 ? live.price_after_discount : live.price) : Number(p.price || 0);

        // While the stock is on its way, a card shows no badge and no price: stockBadge reads
        // a missing stock as 0 ("Out of Stock"), and the price saved with the item can be
        // stale — a figure that changes in front of the visitor a second later is worse than
        // an empty line for that second. The line keeps its height so nothing moves.
        const awaiting = pending && !live;
        const badge = awaiting ? null : stockBadge(stock);
        const inStock = stock > 0;
        // The "notify me" state comes from the server (WishlistController::stock) — it keeps
        // this page consistent with product.php and product_dit.php for the same user and
        // product.
        const alreadyNotified = !!(live && live.already_notified);

        const actions = awaiting ? `
                        <div class="quantity-box mb-3 d-flex justify-content-center gap-2" aria-hidden="true">
                            <button type="button" class="btn btn-outline-secondary btn-sm" disabled>−</button>
                            <input type="number" value="1" class="form-control quantity-input u-w-60" disabled tabindex="-1">
                            <button type="button" class="btn btn-outline-secondary btn-sm" disabled>+</button>
                        </div>
                        <button class="btn btn-success w-100 btn-disabled-faded" disabled>🛒 ${isUser ? 'Add to Cart' : 'Add To Cart'}</button>
                        ` : null;

        return `
        <div class="col-lg-4 col-md-6 mb-4">
            <div class="card product-card h-100 shadow border-0 position-relative">
                <button class="favorite-btn remove-fav" data-id="${p.id}" aria-label="Remove from wishlist">❤️</button>
                <a href="${window.BASE_URL}/product?id=${p.id}" class="text-decoration-none">
                    ${window.buildProductPicture(imgSrc, name, 'card-img-top product-image')}
                </a>
                <div class="card-body d-flex flex-column justify-content-between">
                    <div class="mb-3">
                        ${badge ? `<span class="badge ${badge.class}">${badge.label}</span>` : awaiting ? '<span class="badge invisible">&nbsp;</span>' : ''}
                        <h5 class="fw-bold mt-1">${name}</h5>
                        <div class="price-box">
                            <span class="new-price fs-5 fw-bold">${awaiting ? '&nbsp;' : `$${currentPrice.toFixed(2)}`}</span>
                        </div>
                    </div>
                    <div>
                        ${actions !== null ? actions : inStock ? `
                        <div class="quantity-box mb-3 d-flex justify-content-center gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    data-wl-qty="${p.id}" data-wl-delta="-1">−</button>
                            <input type="number" value="1" id="qty-${p.id}"
                                   class="form-control quantity-input u-w-60" min="1" max="${stock}"
                                   data-wl-qty-input="${p.id}"
                                   data-wl-stock="${stock}"
                                   data-wl-variant="${p.variant_id ?? ''}">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    data-wl-qty="${p.id}" data-wl-delta="1">+</button>
                        </div>
                        <p class="small u-muted text-center mb-2 d-none"
                           data-wl-hint="${p.id}" aria-live="polite"></p>
                        ${isUser ? `
                        <button class="btn btn-success w-100 add-to-cart-wl" data-id="${p.id}">🛒 Add to Cart</button>
                        ` : `
                        <button class="btn btn-success w-100 btn-disabled-faded" disabled
                                data-bs-toggle="modal" data-bs-target="#loginModal"
                                data-action="self-enable">🛒 Add To Cart</button>
                        `}
                        ` : `
                        ${isUser ? `
                        <form class="js-notify-form" data-product-id="${p.id}">
                            <input type="hidden" name="csrf_token" value="${csrf}">
                            <button type="submit"
                                    class="btn w-100 js-notify-btn ${alreadyNotified ? 'btn-success' : 'btn-outline-warning'}"
                                    ${alreadyNotified ? 'disabled' : ''}>${alreadyNotified ? "✅ We'll notify you!" : '🔔 Notify Me When Available'}</button>
                        </form>
                        ` : `
                        <button class="btn btn-outline-warning w-100"
                                data-bs-toggle="modal" data-bs-target="#loginModal">🔔 Notify Me (Login Required)</button>
                        `}
                        `}
                    </div>
                </div>
            </div>
        </div>`;
    }).join('');

    // ── The quantity buttons and their field ────────────────────
    //
    // ⚠️ These used to be three inline handlers inside innerHTML: an onclick on the (−)
    // and (+) buttons, and oninput and onchange on the field. And the CSP in
    // public/.htaccess, without script-src 'unsafe-inline', blocks an inline handler
    // whatever its source — so the quantity buttons on the wishlist page were **completely
    // dead**: they were clicked and nothing changed.
    //
    // And `max=""` was empty on the field itself, so the browser had no ceiling to know at
    // all; it became `max="${stock}"` so the browser constrains it before the code does.
    //
    // The binding here happens after every re-render, because renderWishlist is called
    // again after every change — the same thing the two blocks below do.
    // And the stock is no longer passed as an argument: the ceiling is now what is
    // available (the stock minus what is in the cart), and changeWishlistQty reads it from
    // the field's own attributes — so the buttons carry no second copy of it that could go
    // stale against their field.
    document.querySelectorAll('[data-wl-qty]').forEach(btn => {
        btn.addEventListener('click', () => {
            window.changeWishlistQty(
                btn.getAttribute('data-wl-qty'),
                parseInt(btn.getAttribute('data-wl-delta'), 10) || 0
            );
        });
    });

    document.querySelectorAll('[data-wl-qty-input]').forEach(input => {
        // The numeric sanitising was in oninput; it stays on input so the constraint remains
        // immediate while typing rather than after leaving the field.
        input.addEventListener('input', () => {
            input.value = input.value.replace(/[^0-9]/g, '');
        });

        input.addEventListener('change', () => {
            window.changeWishlistQty(input.getAttribute('data-wl-qty-input'), 0);
        });
    });

    // The cards are re-rendered on every wishlist change, so the ceiling is computed
    // straight after the render rather than at load alone.
    applyWishlistQtyLimits();

    document.querySelectorAll('.remove-fav').forEach(btn => {
        btn.addEventListener('click', () => {
            wishlist = wishlist.filter(i => !sameId(i.id, btn.dataset.id));
            localStorage.setItem('wishlist', JSON.stringify(wishlist));
            if (typeof updateCounters === 'function') updateCounters();
            renderWishlist();
        });
    });

    document.querySelectorAll('.add-to-cart-wl').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!canAddToCart()) {
                if (typeof showToast === 'function') {
                    showToast('Please log in with a customer account to add items to your cart.', 'danger');
                }
                return;
            }

            const id = parseInt(btn.dataset.id, 10);
            const product = wishlist.find(i => sameId(i.id, id));
            if (!product) return;

            await fetchLiveStock([id]);
            const live = liveStockData[String(id)];
            const stock = live ? live.stock_quantity : 0;
            const isVisible = live ? live.is_visible : 0;

            if (!live || isVisible === 0 || stock <= 0) {
                if (typeof showToast === 'function') showToast('This product is no longer available.', 'danger');
                renderWishlist();
                return;
            }

            const input = document.getElementById('qty-' + id);
            let qty = parseInt(input?.value, 10) || 1;
            if (qty < 1) qty = 1;
            if (qty > stock) {
                qty = stock;
                if (typeof showToast === 'function') {
                    showToast(`Only ${stock} in stock — quantity adjusted.`, 'warning');
                }
                if (input) input.value = qty;
            }

            const variantId = product.variant_id ?? null;
            const ex = (window.getCartData ? window.getCartData() : [])
                .find(i => sameId(i.id, id) && sameVariant(i.variant_id, variantId));
            const existingQty = ex ? ex.quantity : 0;

            if (existingQty + qty > stock) {
                const allowed = stock - existingQty;
                if (allowed <= 0) {
                    if (typeof showToast === 'function') {
                        showToast(`You already have the maximum available quantity (${stock}) in your cart.`, 'warning');
                    }
                    return;
                }
                qty = allowed;
                if (typeof showToast === 'function') {
                    showToast(`Only ${allowed} more can be added (stock limit).`, 'warning');
                }
            }

            // ⚠️ No product line is built here: the server stores "what and how many", and
            // the price, name and image are read from the database at display time. Which is
            // why the live price calculation that used to be here was dropped — nothing uses
            // it any more.
            if (!variantId) {
                if (typeof showToast === 'function') showToast('Please choose a colour first.', 'warning');
                return;
            }

            if (!(await window.cartAdd(id, variantId, qty))) return;

            if (typeof showToast    === 'function') showToast('Added to cart!', 'success');
            if (input) input.value = 1;
            const cb = document.querySelector('[data-bs-target="#cartSidebar"]');
            if (cb) { cb.classList.add('cart-bounce'); setTimeout(() => cb.classList.remove('cart-bounce'), 500); }
        });
    });

    if (typeof window.initScrollReveal === 'function') requestAnimationFrame(window.initScrollReveal);
}
