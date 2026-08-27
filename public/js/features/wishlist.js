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

// getStockBadgeJs حُذفت — القاعدة الآن في stockBadge() بـjs/core/utils.js،
// مرآةً لـgetStockBadge() في PHP. هذه الصفحة لا تعرض بادج «متوفّر»
// الأخضر (بادج على كل بطاقة ضجيج بصري)، فالوسيط الثاني يبقى false.

// [FIX] المسار مُحدَّث من /handlers/product_stock_handler.php إلى Route الجديد
async function fetchLiveStock(ids) {
    try {
        const params = new URLSearchParams();
        ids.forEach(id => params.append('ids[]', id));
        const res = await fetch(window.BASE_URL + '/handlers/product_stock_handler.php?' + params.toString());
        const data = await res.json();
        if (data.success) {
            liveStockData = { ...liveStockData, ...data.products };
        }
    } catch {
        // fail silently
    }
}

window.changeWishlistQty = (id, val, stock) => {
    const input = document.getElementById('qty-' + id);
    if (!input) return;
    const max = parseInt(stock, 10) || 0;
    let v = (parseInt(input.value, 10) || 1) + val;
    if (v < 1) v = 1;
    if (max > 0 && v > max) {
        v = max;
        if (typeof showToast === 'function') {
            showToast(`Only ${max} left in stock.`, 'warning');
        }
    }
    input.value = v;
};

async function renderWishlist() {
    const container = document.getElementById('wishlist-container');
    if (!container) return;

    wishlist = JSON.parse(localStorage.getItem('wishlist') || '[]');

    if (!wishlist.length) {
        container.innerHTML = `
        <div class="col-12 text-center py-5 fade-in-up">
            <div style="font-size:5rem;">💔</div>
            <h3 class="mt-3 fw-bold" style="color:var(--text-color);">Your Wishlist is Empty</h3>
            <p class="mt-2" style="color:var(--placeholder-color);">Save your favorite products and come back anytime.</p>
            <a href="${window.BASE_URL}/products" class="btn btn-success mt-3 px-4 py-2">🛍️ Browse Products</a>
        </div>`;
        return;
    }

    const validWishlistIds = wishlist
        .map(p => parseInt(p.id, 10))
        .filter(id => Number.isInteger(id) && id > 0);

    const missingIds = validWishlistIds
        .filter(id => !(String(id) in liveStockData));

    if (missingIds.length > 0) {
        await fetchLiveStock(missingIds);
    }

    // Auto-remove products that are no longer visible (hidden by an admin) from the wishlist
    let stillVisibleWishlist = wishlist.filter(p => {
        const live = liveStockData[String(parseInt(p.id, 10))];
        return live && live.is_visible !== 0; // keep only if we have live data AND it's visible
    });
    if (stillVisibleWishlist.length !== wishlist.length) {
        wishlist = stillVisibleWishlist;
        localStorage.setItem('wishlist', JSON.stringify(wishlist));
    }

    const isUser = REGULAR_USER_FLAG.value;
    const csrf = window.__csrfTokenForWishlist || '';

    container.innerHTML = wishlist.map(p => {
        const imgSrc = window.imagePathOrEmpty(p.image_path || p.image || '');
        const name   = escHtml(p.name);
        const normalizedId = parseInt(p.id, 10);
        const live   = liveStockData[String(normalizedId)];
        const stock  = live ? live.stock_quantity : null;
        const currentPrice = live ? (live.discount_percentage > 0 ? live.price_after_discount : live.price) : Number(p.price || 0);

        const badge = stockBadge(stock);
        const inStock = stock > 0;
        // حالة "نبّهني" تأتي من السيرفر (WishlistController::stock) — تُبقي هذه
        // الصفحة متطابقة مع product.php و product_dit.php لنفس المستخدم والمنتج.
        const alreadyNotified = !!(live && live.already_notified);

        return `
        <div class="col-lg-4 col-md-6 mb-4">
            <div class="card product-card h-100 shadow border-0 position-relative">
                <button class="favorite-btn remove-fav" data-id="${p.id}" aria-label="Remove from wishlist">❤️</button>
                <a href="${window.BASE_URL}/product?id=${p.id}" style="text-decoration:none;">
                    ${window.buildProductPicture(imgSrc, name, 'card-img-top product-image')}
                </a>
                <div class="card-body d-flex flex-column justify-content-between">
                    <div class="mb-3">
                        ${badge ? `<span class="badge ${badge.class}">${badge.label}</span>` : ''}
                        <h5 class="fw-bold mt-1">${name}</h5>
                        <div class="price-box">
                            <span class="new-price fs-5 fw-bold">$${currentPrice.toFixed(2)}</span>
                        </div>
                    </div>
                    <div>
                        ${inStock ? `
                        <div class="quantity-box mb-3 d-flex justify-content-center gap-2">
                            <button class="btn btn-outline-secondary btn-sm" onclick="changeWishlistQty('${p.id}',-1,${stock})">−</button>
                            <input type="number" value="1" id="qty-${p.id}"
                                   class="form-control quantity-input" min="1" max="${stock}" style="width:60px;"
                                   oninput="this.value = this.value.replace(/[^0-9]/g,'')"
                                   onchange="changeWishlistQty('${p.id}',0,${stock})">
                            <button class="btn btn-outline-secondary btn-sm" onclick="changeWishlistQty('${p.id}',1,${stock})">+</button>
                        </div>
                        ${isUser ? `
                        <button class="btn btn-success w-100 add-to-cart-wl" data-id="${p.id}">🛒 Add to Cart</button>
                        ` : `
                        <button class="btn btn-success w-100 btn-disabled-faded" disabled
                                data-bs-toggle="modal" data-bs-target="#loginModal"
                                onclick="this.removeAttribute('disabled')">🛒 Add To Cart</button>
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

    document.querySelectorAll('.remove-fav').forEach(btn => {
        btn.addEventListener('click', () => {
            wishlist = wishlist.filter(i => i.id != btn.dataset.id);
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
            const product = wishlist.find(i => i.id == id);
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

            // السعر الحيّ المجلوب للتو (بدل product.price القديم)
            const currentPriceForCart = live.discount_percentage > 0 ? live.price_after_discount : live.price;

            let cart = JSON.parse(localStorage.getItem('cart') || '[]');
            const variantId = product.variant_id ?? null;
            const ex = cart.find(i => i.id == id && i.variant_id == variantId);
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

            if (ex) {
                ex.quantity += qty;
                ex.stock = stock;
            } else {
                cart.push({
                    id,
                    variant_id: variantId,
                    color_name: product.color_name ?? null,
                    name: product.name,
                    price: currentPriceForCart,
                    image_path: product.image_path,
                    quantity: qty,
                    stock: stock,
                });
            }
            localStorage.setItem('cart', JSON.stringify(cart));

            if (typeof refreshCartUI === 'function') refreshCartUI();
            if (typeof showToast    === 'function') showToast('Added to cart!', 'success');
            if (input) input.value = 1;
            const cb = document.querySelector('[data-bs-target="#cartSidebar"]');
            if (cb) { cb.classList.add('cart-bounce'); setTimeout(() => cb.classList.remove('cart-bounce'), 500); }
        });
    });

    if (typeof window.initScrollReveal === 'function') requestAnimationFrame(window.initScrollReveal);
}
