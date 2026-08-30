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
 * تعديلات جارية لكل variant — تمنع تراكم النقرات السريعة.
 *
 * ⚠️ بلا هذا، كل نقرة تقرأ المرآة **قبل** أن يصل ردّ سابقتها، فترى
 * كمية قديمة. وأثره مقيس: عشر نقرات سريعة على منتج مخزونه خمسة كانت
 * تمرّ كلّها من فحص المخزون في المتصفّح لأن كلّاً منها ترى «صفر في
 * السلّة».
 *
 * الخادم يسقّف الآن بالمخزون فلا تكذب القاعدة. لكن السلسلة هنا تمنع
 * الوميض أيضاً — رقمٌ يقفز ثم يرتدّ — وتوفّر تسع رحلات شبكة من عشر.
 *
 * والمفتاح هو الـvariant لا عامّ: تعديل سطرٍ لا يمنع تعديل غيره.
 *
 * @type {Map<string|number, Promise<boolean>>}
 */
const inFlight = new Map();

/** يسلسل التعديلات على نفس الـvariant، ويترك المختلفة متوازية. */
function serialise(key, task) {
    const previous = inFlight.get(key) ?? Promise.resolve(true);
    // catch كي لا يُسقط فشلٌ واحد السلسلة كلّها لهذا الـvariant.
    const next = previous.catch(() => false).then(task);

    inFlight.set(key, next);
    next.finally(() => {
        if (inFlight.get(key) === next) inFlight.delete(key);
    });

    return next;
}

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

/** إضافة كمية — تُجمَع مع الموجود على الخادم، ومسلسَلة لكل variant. */
async function cartAdd(productId, variantId, qty = 1) {
    return serialise(variantId, () => cartMutate('/cart/add', {
        product_id: Number(productId),
        variant_id: Number(variantId),
        qty:        Number(qty) || 1,
    }));
}
window.cartAdd = cartAdd;

/** ضبط كمية سطر ضبطاً مطلقاً — الصفر يحذفه. */
async function cartSetQuantity(variantId, qty) {
    return serialise(variantId, () => cartMutate('/cart/update', { variant_id: Number(variantId), qty: Number(qty) }));
}
window.cartSetQuantity = cartSetQuantity;

/** حذف سطر. */
async function cartRemove(variantId) {
    return serialise(variantId, () => cartMutate('/cart/remove', { variant_id: Number(variantId) }));
}
window.cartRemove = cartRemove;

/**
 * يضبط المرآة مباشرةً ويعيد الرسم — **بلا كتابة على الخادم**.
 *
 * ⚠️ كان اسمها `saveCart` وكانت تكتب في localStorage. وبعد انتقال
 * السلّة إلى الخادم بقيت بالاسم نفسه «للتوافق» وهي لا تحفظ شيئاً —
 * اسمٌ يَعِد بما لا يفعله، وهو أسوأ من غياب الدالة: من يقرأ
 * `saveCart(cart)` يظنّ سلّته حُفظت.
 *
 * ومسحٌ للمستدعين أثبت أنها بلا مستدعٍ واحد في الإنتاج: كل الكتابة
 * تمرّ بـcartAdd و cartSetQuantity و cartRemove. فما بقي لها إلا
 * استعمال واحد مشروع — تهيئة المرآة في الاختبارات بلا خادم — والاسم
 * الجديد يقول ذلك.
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

    // ── إشعار بقيّة الصفحة أن المرآة تغيّرت ───────────────────────
    //
    // هذه الدالة هي المعبر الوحيد لكل تغيّر في السلّة: يمرّ بها
    // loadCart بعد الجلب الأوّلي، وsetCartMirror بعد كل إضافة أو
    // تعديل أو حذف يردّ به الخادم. فبثّ الحدث هنا يعني موضعاً واحداً
    // يستحيل أن يُنسى، لا سطراً يُضاف عند كل عملية.
    //
    // ولماذا حدث لا استدعاء مباشر: صفحة تفاصيل المنتج تحتاج أن تعيد
    // حساب سقف عدّاد الكمية بعد كل تغيّر (المتاح = المخزون ناقص ما في
    // السلّة)، وcart.js لا يعرف بوجودها ولا يجب أن يعرف. الحدث يقلب
    // الاتجاه: المهتمّ يستمع، والمصدر لا يحمل قائمة بمن يهمّه الأمر.
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
 * يُصحّح السلّة بعد أن يتغيّر المخزون تحتها.
 *
 * ══════════════════════════════════════════════════════════════
 * ⚠️ عطلٌ قِيس وأُصلح: التصحيح كان لا يغادر المتصفّح
 * ══════════════════════════════════════════════════════════════
 *
 * النسخة السابقة كانت تبني `updatedCart` بـreduce وتُعدّل الكمية
 * **داخل الحلقة**. و`getCartData()` تُرجع المرآة بالمرجع، فكان
 * `item.quantity = info.stock` يكتب في المرآة نفسها. ثم تأتي المصفاة
 * التي تقرّر ما يُرسَل إلى الخادم فتقارن:
 *
 *     info.stock < cart.find(...).quantity
 *
 * وquantity كانت قد صارت info.stock — فالشرط `x < x` كاذب دائماً،
 * ولم يُستدعَ cartSetQuantity قطّ.
 *
 * الأثر: الشاشة تعرض الكمية المخفَّضة والخادم يحتفظ بالقديمة. فيصل
 * الزبون إلى الدفع فيُرفض بـout_of_stock عن سلّة تبدو سليمة أمامه.
 *
 * ── ولماذا اختفى استدعاء /cart/check-stock ──────────────────
 *
 * لأنه صار زائداً. `/cart` تضمّ product_variants في كل قراءة فتُرجع
 * `stock` و`price` الحيَّين مع كل سطر، وتُسقط المخفيّ والمحذوف من
 * تلقائها. فالمرآة **هي** بيانات المخزون الطازجة — واستدعاء نقطة
 * ثانية لجلبها كان رحلة شبكة تسأل عمّا تملكه الإجابة أصلاً.
 *
 * وما بقي عملٌ حقيقي: نافدٌ يُحذف، وكميةٌ تجاوزت المتاح تُخفَّض —
 * وكلاهما يُكتب على الخادم، ثم تُعاد القراءة منه.
 */
async function syncCartWithStock() {
    const cart = getCartData();
    if (cart.length === 0) return;

    // القراءة قبل أي تعديل: نلتقط النيّة الأصلية للزبون كي نقارنها
    // بالمتاح. القراءة بعد التعديل هي بالضبط ما أسقط النسخة السابقة.
    const soldOut = cart.filter(i => i.variant_id && Number(i.stock) <= 0);
    const excess  = cart.filter(i =>
        i.variant_id && Number(i.stock) > 0 && Number(i.quantity) > Number(i.stock)
    );

    if (soldOut.length === 0 && excess.length === 0) return;

    // تُلتقط الأسماء الآن: بعد loadCart تختفي السطور المحذوفة من المرآة.
    const soldOutNames = soldOut.map(i => i.name + (i.color_name ? ` (${i.color_name})` : ''));
    const excessNames  = excess.map(i => `${i.name} (${i.stock})`);

    try {
        await Promise.all([
            ...soldOut.map(i => cartRemove(i.variant_id)),
            ...excess.map(i => cartSetQuantity(i.variant_id, Number(i.stock))),
        ]);
    } catch (e) {
        console.error('syncCartWithStock: تعذّر تصحيح السلّة', e);
        return;
    }

    // القراءة من الخادم بعد التصحيح — لا ثقة بما في الذاكرة بعد كتابة.
    await loadCart();

    if (soldOutNames.length > 0 && typeof showToast === 'function') {
        showToast(`Removed (out of stock): ${soldOutNames.join(', ')}`, 'error');
    }

    if (excessNames.length > 0 && typeof showToast === 'function') {
        showToast(`Quantity adjusted to available stock: ${excessNames.join(', ')}`, 'info');
    }
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
