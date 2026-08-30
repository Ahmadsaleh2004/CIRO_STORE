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

// ══════════════════════════════════════════════════════════════
// سقف عدّاد بطاقة المفضّلة = المخزون **ناقص ما في السلّة**
// ══════════════════════════════════════════════════════════════
//
// نفس مبدأ صفحة التفاصيل وبطاقات القائمة: المنع قبل الاختيار لا
// بعده. كان العدّاد هنا يسقّف بالمخزون المطلق، ثم يكتشف المستخدم عند
// الضغط على «Add to Cart» أن ما في سلّته محسوبٌ عليه — بتوست
// «You already have the maximum available quantity».
//
// ── حالة تخصّ المفضّلة وحدها ───────────────────────────────
//
// عناصر المفضّلة تُخزَّن في localStorage، وبعضها قديم بلا
// `variant_id` — أُضيف الحقل لاحقاً. فمطابقة سطر السلّة بـvariant_id
// وحده كانت ستفشل على تلك العناصر وتُرجع «صفر في السلّة»، أي سقفاً
// أوسع من الحقيقة.
//
// ولذلك: إن عُرفت النسخة طابقناها، وإلّا جمعنا كل سطور السلّة لهذا
// المنتج. والمخزون المعروض هنا مخزون المنتج لا النسخة (يأتي من
// WishlistController::stock)، فالجمع هو المقابل الصحيح له.
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

/** المتاح فعلاً لبطاقة، من سمات حقلها. */
function wishlistRemaining(input) {
    const stock = Number(input.getAttribute('data-wl-stock')) || 0;
    const inCart = cartQtyForWishlist(
        input.getAttribute('data-wl-qty-input'),
        input.getAttribute('data-wl-variant')
    );

    return Math.max(0, stock - inCart);
}

/** يضبط سقف كل بطاقة وحالة أزرارها على المتاح. */
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

        // كزرّ صفحة التفاصيل: لا نلمس زرّاً معطّلاً لسبب آخر — الزائر
        // غير المسجَّل يراه معطّلاً بـdata-action="self-enable" ليفتح
        // مودال الدخول.
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

// السلّة تتغيّر بعد رسم البطاقات — حذف سطر من السلّة الجانبية يجب أن
// يُعيد المتاح هنا فوراً. الحدث يبثّه refreshCartUI في cart.js.
document.addEventListener('cart:updated', applyWishlistQtyLimits);

window.changeWishlistQty = (id, val) => {
    const input = document.getElementById('qty-' + id);
    if (!input) return;

    // ⚠️ السقف يُقرأ من المتاح لا من المخزون.
    //
    // كانت الدالة تستقبل `stock` وسيطاً ثالثاً من سمة على الزرّ،
    // فتسقّف بالمخزون المطلق وتقول «Only N left in stock» — وهي رسالة
    // صحيحة عن المخزون وخاطئة عمّا يستطيع المستخدم إضافته.
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
                <a href="${window.BASE_URL}/product?id=${p.id}" class="text-decoration-none">
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

    // ── أزرار الكمية وحقلها ────────────────────────────────────
    //
    // ⚠️ كانت هذه ثلاثة معالجات مضمّنة داخل innerHTML:
    // onclick على زرَّي (−) و(+)، وoninput وonchange على الحقل.
    // وسياسة CSP في public/.htaccess بلا script-src 'unsafe-inline'
    // تحجب المعالج المضمّن مهما كان مصدره — فكانت أزرار الكمية في
    // صفحة المفضّلة **ميتة تماماً**: تُنقر ولا يتغيّر شيء.
    //
    // وكان `max=""` فارغاً في الحقل نفسه، فلم يكن للمتصفّح سقف يعرفه
    // أصلاً؛ صار `max="${stock}"` كي يحدّه قبل أن يحدّه الكود.
    //
    // الربط هنا يقع بعد كل إعادة رسم لأن renderWishlist تُستدعى مجدداً
    // بعد كل تغيير — وهو نفس ما تفعله الكتلتان أدناه.
    // ولم يعد المخزون يُمرَّر وسيطاً: السقف صار المتاح (المخزون ناقص
    // ما في السلّة)، وتقرؤه changeWishlistQty من سمات الحقل نفسه —
    // فلا تحمل الأزرار نسخةً ثانية منه قد تشيخ عن حقلها.
    document.querySelectorAll('[data-wl-qty]').forEach(btn => {
        btn.addEventListener('click', () => {
            window.changeWishlistQty(
                btn.getAttribute('data-wl-qty'),
                parseInt(btn.getAttribute('data-wl-delta'), 10) || 0
            );
        });
    });

    document.querySelectorAll('[data-wl-qty-input]').forEach(input => {
        // تنقية الأرقام كانت في oninput؛ نُبقيها على input كي يبقى
        // المنع فورياً أثناء الكتابة لا بعد مغادرة الحقل.
        input.addEventListener('input', () => {
            input.value = input.value.replace(/[^0-9]/g, '');
        });

        input.addEventListener('change', () => {
            window.changeWishlistQty(input.getAttribute('data-wl-qty-input'), 0);
        });
    });

    // البطاقات تُرسم من جديد عند كل تغيير في المفضّلة، فالسقف يُحسب
    // بعد الرسم مباشرةً لا عند التحميل وحده.
    applyWishlistQtyLimits();

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

            const variantId = product.variant_id ?? null;
            const ex = (window.getCartData ? window.getCartData() : [])
                .find(i => i.id == id && i.variant_id == variantId);
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

            // ⚠️ لا سطر منتج يُبنى هنا: الخادم يخزّن «ماذا وكم»، والسعر
            // والاسم والصورة تُقرأ من القاعدة عند العرض. ولذلك سقط حساب
            // السعر الحيّ الذي كان هنا — لم يبقَ له مستعمل.
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
