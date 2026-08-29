// ══════════════════════════════════════════════════════════════
// js/features/cart.js — محرك سلة التسوق وتزامن المخزون
// ══════════════════════════════════════════════════════════════

// ══════════════════════════════════════════════════════════════
// طبقة التخزين — الخادم هو المرجع، والذاكرة مرآةٌ له
// ══════════════════════════════════════════════════════════════
//
// كانت السلّة في localStorage بالكامل: لا تتبع المستخدم بين أجهزته،
// وتضيع بمسح بيانات المتصفّح أو بنافذة خاصة — وضياع سلّة مليئة خسارة
// بيع لا إزعاج واجهة. وما يضعه الناس ولا يشترونه لم يكن يصل الخادم قط.
//
// ── لماذا مرآة في الذاكرة لا استدعاء غير متزامن في كل موضع ──
//
// `getCartData()` تُستدعى من ستّة ملفات (checkout · products-catalog ·
// product-details · wishlist · ui · هذا الملف)، وكلّها تفترضها
// **متزامنة**. تحويلها إلى async يعني `await` في كل موضع، وكل موضع
// نسيَ الـawait يقرأ Promise كأنه مصفوفة — عطلٌ صامت يظهر متأخّراً.
//
// فالمرآة تُبقي العقد كما هو: القراءة متزامنة من الذاكرة، والكتابة
// وحدها تذهب إلى الخادم وتُحدّث المرآة من ردّه. أي أن ستّة ملفات لا
// تتغيّر، والتغيير محصور في هذا الملف وفي مواضع الإضافة الثلاثة.
//
// ── والخادم يردّ بالسلّة كاملةً بعد كل تعديل ────────────────
//
// وهذا ما يحلّ تعارض التبويبين بلا منطق إضافي: من يعدّل يرى نتيجة
// تعديله ونتيجة تعديل غيره في الاستجابة نفسها.

/** مرآة السلّة. المصدر الحقيقي جدول cart_items على الخادم. */
let cartCache = [];

/** هل المستخدم مسجَّل؟ الزائر لا سلّة له أصلاً — الأزرار مخفيّة عنه. */
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
 * يقرأ السلّة من الخادم ويملأ المرآة.
 *
 * تُستدعى عند تحميل الصفحة وبعد كل تعديل. وفشلها لا يُفرّغ المرآة:
 * انقطاع شبكة لحظي يجب ألّا يُظهر السلّة فارغة لمن يملأها.
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
        console.error('cart: تعذّر جلب السلّة من الخادم', e);
    }
}
window.loadCart = loadCart;

/**
 * ينفّذ تعديلاً على الخادم ويُحدّث المرآة من ردّه.
 *
 * ⚠️ المرآة تُحدَّث من **الاستجابة** لا من تخمين محلي. التحديث
 * المتفائل (عدّل محلياً ثم أرسل) يعرض للزبون حالةً قد لا تكون قد
 * وقعت — وسلّة تعرض ما ليس فيها أسوأ من سلّة بطيئة.
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
        console.error('cart: فشل تعديل السلّة', e);
        if (typeof showToast === 'function') showToast('Network error. Please try again.', 'error');
        return false;
    }
}

/** إضافة كمية — تُجمَع مع الموجود على الخادم. */
async function cartAdd(productId, variantId, qty = 1) {
    return cartMutate('/cart/add', {
        product_id: Number(productId),
        variant_id: Number(variantId),
        qty:        Number(qty) || 1,
    });
}
window.cartAdd = cartAdd;

/** ضبط كمية سطر ضبطاً مطلقاً — الصفر يحذفه. */
async function cartSetQuantity(variantId, qty) {
    return cartMutate('/cart/update', { variant_id: Number(variantId), qty: Number(qty) });
}
window.cartSetQuantity = cartSetQuantity;

/** حذف سطر. */
async function cartRemove(variantId) {
    return cartMutate('/cart/remove', { variant_id: Number(variantId) });
}
window.cartRemove = cartRemove;

/**
 * أُبقيت للتوافق: بعض الملفات تنادي saveCart بمصفوفة معدَّلة.
 *
 * لم تعد تكتب شيئاً — الكتابة صارت عبر النقاط أعلاه. تُحدّث المرآة
 * وتعيد الرسم فقط، كي لا ينكسر مستدعٍ لم يُحدَّث بعد.
 */
function saveCart(updatedCart) {
    if (Array.isArray(updatedCart)) {
        cartCache = updatedCart;
    }
    refreshCartUI();
}
window.saveCart = saveCart;

function refreshCartUI() {
    if (typeof updateCounters === 'function') updateCounters();
    renderCart();
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

function syncCartWithStock() {
    const cart = getCartData();
    if (cart.length === 0) return;

    const variantIds = cart.map(item => item.variant_id).filter(v => v !== undefined && v !== null);
    if (variantIds.length === 0) return;

    // fetch عارٍ عن قصد — لا fetchWithCsrfRetry: CartController::checkStock
    // **لا تتحقق من توكن CSRF** (استعلام مخزون للقراءة)، والطلب لا يرسل
    // توكناً أصلاً. فلا يمكن أن تُرجع «Invalid CSRF token».
    // nosemgrep: cairo-bare-fetch-post
    fetch(window.BASE_URL + '/cart/check-stock', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: variantIds.map(id => 'variant_ids[]=' + encodeURIComponent(id)).join('&')
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) return;

        // تحويل data.items (array) إلى object مفهرس بـ variant_id
        // بنفس البنية التي يتوقعها باقي الكود: variants[variantId] = {stock, visible, ...}
        const variants = {};
        (data.items || []).forEach(item => {
            variants[String(item.variant_id)] = {
                stock:   item.stock_quantity,
                visible: true,                       // is_visible=1 مشروط بالـ SQL
                price:   item.price_after_discount || item.price,
            };
        });

        let removedNames = [];
        let adjustedNames = [];
        let stockRefreshed = false;

        const updatedCart = cart.reduce((acc, item) => {
            if (item.variant_id === undefined || item.variant_id === null) {
                acc.push(item);
                return acc;
            }

            const info = variants[String(item.variant_id)];

            if (!info || !info.visible || info.stock <= 0) {
                removedNames.push(item.name + (item.color_name ? ` (${item.color_name})` : ''));
                return acc;
            }

            if (info.stock < item.quantity) {
                item.quantity = info.stock;
                adjustedNames.push(`${item.name} (${info.stock})`);
            }

            if (item.stock !== info.stock) {
                item.stock = info.stock;
                stockRefreshed = true;
            }

            acc.push(item);
            return acc;
        }, []);

        // ── التصحيح يُكتب على الخادم لا محلياً ────────────────────
        //
        // `stockRefreshed` وحده لم يعد يستدعي كتابة: `/cart` تُرجع
        // المخزون والسعر الحيَّين في كل قراءة، فالمرآة محدَّثة أصلاً.
        // يبقى ما يغيّر السلّة فعلاً — حذفُ نافد، وخفضُ كمية تجاوزت
        // المتاح — وكلاهما يمرّ بالخادم كي يبقى مصدر الحقيقة واحداً.
        //
        // والاستدعاءات قليلة بطبعها: لا تقع إلا حين ينفد شيء بين
        // فتح الصفحة وفحصها.
        const corrections = updatedCart.filter(i => i.variant_id);
        const removedIds  = cart
            .filter(i => i.variant_id && !updatedCart.some(u => u.variant_id === i.variant_id))
            .map(i => i.variant_id);

        Promise.all([
            ...removedIds.map(id => cartRemove(id)),
            ...corrections
                .filter(i => {
                    const info = variants[String(i.variant_id)];
                    return info && info.stock < (cart.find(c => c.variant_id === i.variant_id)?.quantity ?? 0);
                })
                .map(i => cartSetQuantity(i.variant_id, i.quantity)),
        ]).then(() => {
            if (removedNames.length > 0 || adjustedNames.length > 0) {
                loadCart();
            } else if (stockRefreshed) {
                refreshCartUI();
            }
        });

        if (removedNames.length > 0 || adjustedNames.length > 0 || stockRefreshed) {
            if (removedNames.length > 0) {
                if (typeof showToast === 'function') showToast(`Removed (out of stock): ${removedNames.join(', ')}`, 'error');
            }
            if (adjustedNames.length > 0) {
                if (typeof showToast === 'function') showToast(`Quantity adjusted to available stock: ${adjustedNames.join(', ')}`, 'info');
            }
        }
    })
    .catch(err => console.error('syncCartWithStock error:', err));
}
window.syncCartWithStock = syncCartWithStock;

// تهيئة تفاعلات السلة بالـ DOM
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

            // ⚠️ الكمية تُرسَل **مطلقةً** لا كفارق (+1/−1).
            //
            // الفارق يفترض أن ما في الشاشة هو ما في القاعدة، وهو افتراض
            // يسقط بنقرتين سريعتين أو بتبويب ثانٍ: فارقان يصلان فيصير
            // المجموع ثلاثة بدل اثنين. والقيمة المطلقة تجعل آخر نقرة
            // تفوز — وهو السلوك الذي يتوقّعه من يضغط الزرّ.
            if (btn.classList.contains("plus")) {
                const maxStock = typeof item.stock === 'number' ? item.stock : Infinity;
                if (item.quantity >= maxStock) {
                    if (typeof showToast === 'function') showToast('No more stock available for this item.', 'error');
                    return;
                }
                cartSetQuantity(variantId, item.quantity + 1);
            } else if (btn.classList.contains("minus")) {
                // الصفر حذفٌ على الخادم، فالنقصان من واحد يزيل السطر.
                cartSetQuantity(variantId, item.quantity - 1);
            } else if (btn.classList.contains("remove-item")) {
                cartRemove(variantId);
                if (typeof showToast === 'function') showToast('Product removed from cart', 'info');
            }
        }
    });

    // السلّة تُجلب من الخادم أوّلاً، ثم يُفحص مخزونها.
    //
    // الترتيب لازم: syncCartWithStock تعمل على ما في المرآة، والمرآة
    // فارغة قبل أن يردّ /cart. تشغيلها قبله كان سيفحص لا شيء ثم يصمت.
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
