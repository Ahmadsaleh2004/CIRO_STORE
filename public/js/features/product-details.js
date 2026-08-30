// ══════════════════════════════════════════════════════════════
// js/features/product-details.js — تفاعلات صفحة تفاصيل المنتج
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
    // ── Star Widget ─────────────────────────────────────────────
    const stars      = document.querySelectorAll('.star-span');
    const ratingInpt = document.getElementById('ratingInput');
    const reviewBtn  = document.getElementById('reviewSubmitBtn');
    const commentTxt = document.querySelector('textarea[name="comment"]');

    function checkReviewValidity() {
        const hasRating  = ratingInpt && parseInt(ratingInpt.value) >= 1;
        const hasComment = commentTxt && commentTxt.value.trim().length > 0;
        if (reviewBtn && typeof updateButtonState === 'function') {
            updateButtonState(reviewBtn, hasRating || hasComment);
        }
    }

    if (stars.length) {
        stars.forEach(s => {
            s.addEventListener('mouseover', () => {
                const v = parseInt(s.dataset.val);
                stars.forEach((st,i) => { st.textContent=i<v?'★':'☆'; st.style.color=i<v?'#f59e0b':'#d1d5db'; });
            });
            s.addEventListener('click', () => {
                const v = parseInt(s.dataset.val);
                if (ratingInpt) ratingInpt.value = v;
                stars.forEach((st,i) => { st.classList.toggle('active',i<v); st.textContent=i<v?'★':'☆'; });
                checkReviewValidity();
            });
        });
        document.getElementById('starWidget')?.addEventListener('mouseleave', () => {
            const cur = parseInt(ratingInpt?.value||0);
            stars.forEach((st,i) => { st.textContent=i<cur?'★':'☆'; st.style.color=i<cur?'#f59e0b':'#d1d5db'; });
        });
    }
    if (commentTxt) commentTxt.addEventListener('input', checkReviewValidity);
    checkReviewValidity();

    // ── Variant Switching ─────────────────────────────────────────
    const productVariants = window.PRODUCT_VARIANTS || [];
    let currentVariantId = window.SELECTED_VARIANT_ID || 0;

    function applyVariantToUI(variant) {
        const imgEl = document.getElementById('productMainImg');
        if (imgEl) imgEl.src = imagePathOrEmpty(variant.image);

        const newPriceEl = document.querySelector('.new-price');
        if (newPriceEl) newPriceEl.textContent = '$' + variant.final_price.toFixed(2);

        const oldPriceEl = document.querySelector('.old-price');
        const discountBadge = document.getElementById('discountBadge');
        if (variant.discount > 0) {
            if (!oldPriceEl) {
                const span = document.createElement('span');
                span.className = 'old-price ms-2';
                span.textContent = '$' + variant.price.toFixed(2);
                newPriceEl?.after(span);
            } else {
                oldPriceEl.textContent = '$' + variant.price.toFixed(2);
                oldPriceEl.style.display = '';
            }
            if (discountBadge) { discountBadge.textContent = '-' + variant.discount + '%'; discountBadge.style.display = ''; }
        } else {
            if (oldPriceEl) oldPriceEl.style.display = 'none';
            if (discountBadge) discountBadge.style.display = 'none';
        }

        const nameEl = document.getElementById('selectedColorName');
        if (nameEl) nameEl.textContent = variant.color_name;

        document.querySelectorAll('.color-swatch-btn').forEach(btn => {
            btn.classList.toggle('active', parseInt(btn.dataset.variantId) === variant.id);
        });

        // ⚠️ لا `qtyInput.max = variant.stock` هنا.
        //
        // المخزون المطلق ليس السقف الصحيح: ما في سلّة المستخدم من هذه
        // النسخة محجوزٌ منه. الحساب كلّه في applyQtyLimits أدناه، ويُستدعى
        // بعد ضبط currentVariantId في آخر هذه الدالة.
        const qtyInput = document.getElementById('productQty');
        if (qtyInput) qtyInput.value = variant.stock > 0 ? 1 : 0;

        const qtyCartBlock = document.getElementById('qtyCartBlock');
        const notifyBlock  = document.getElementById('notifyBlock');
        if (qtyCartBlock && notifyBlock) {
            if (variant.stock > 0) {
                qtyCartBlock.style.display = '';
                notifyBlock.style.display  = 'none';
            } else {
                qtyCartBlock.style.display = 'none';
                notifyBlock.style.display  = '';
            }
        }

        const notifyVariantInput = document.getElementById('notifyVariantIdInput');
        if (notifyVariantInput) notifyVariantInput.value = variant.id;

        const addBtn = document.getElementById('addCartBtn');
        if (addBtn) addBtn.disabled = variant.stock <= 0;

        // القاعدة في stockBadge() بـjs/core/utils.js، مرآةً لـgetStockBadge()
        // في PHP. كانت مكتوبة هنا بـif/else — وهي النسخة الثالثة من نفس
        // القاعدة في المشروع. صفحة التفاصيل وحدها تعرض البادج الأخضر،
        // فالوسيط الثاني true (نفس ما يفعله الـview عند العرض من الخادم).
        const stockBadgeEl = document.getElementById('stockBadge');
        if (stockBadgeEl) {
            const badge = stockBadge(variant.stock, true);
            stockBadgeEl.classList.remove('bg-danger', 'bg-warning', 'text-dark', 'bg-success');
            // badge.class قد يحمل صنفين ('bg-warning text-dark') فيُقسَّم
            badge.class.split(' ').forEach(c => stockBadgeEl.classList.add(c));
            stockBadgeEl.textContent = badge.label;
        }

        currentVariantId = variant.id;

        // بعد تثبيت النسخة المختارة: يُعاد حساب المتاح لها هي، لا لسابقتها.
        applyQtyLimits();
    }

    document.querySelectorAll('.color-swatch-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const v = productVariants.find(x => x.id === parseInt(btn.dataset.variantId));
            if (v) applyVariantToUI(v);
        });
    });

    document.getElementById('toggleAllColorsBtn')?.addEventListener('click', function () {
        const panel = document.getElementById('allColorsPanel');
        const icon  = document.getElementById('toggleArrowIcon');
        if (!panel || !icon) return;
        // ⚠️ الحالة تُقرأ من `d-none` لا من style.display.
        //
        // اللوحة تبدأ بـ`d-none` في الترميز، وstyle.display يبدأ سلسلة
        // فارغة — فكان `expanded` يساوي true عند أول نقرة، فتُخفي
        // مخفياً ولا يظهر شيء. ولو ظهر لما نفع: `d-none` تحمل
        // !important فتغلب أي نمط مضمّن.
        const expanded = !panel.classList.contains('d-none');
        panel.classList.toggle('d-none', expanded);
        icon.textContent = expanded ? '▾' : '▴';
        this.setAttribute('aria-expanded', String(!expanded));
    });

    // ── Qty & Cart ───────────────────────────────────────────────
    const qty   = document.getElementById('productQty');
    const plus  = document.getElementById('plusBtn');
    const minus = document.getElementById('minusBtn');

    // ══════════════════════════════════════════════════════════
    // سقف العدّاد = المخزون **ناقص ما في السلّة**
    // ══════════════════════════════════════════════════════════
    //
    // كان السقف هو المخزون المطلق: `max="<?= $stock ?>"` في الـview،
    // و`qtyInput.max = variant.stock` عند تبديل اللون. فمن عنده خمس
    // قطع في المخزون واثنتان في سلّته كان العدّاد يسمح له بخمس، ثم
    // يرفض عند الضغط على «Add To Cart» بتوست «Only 3 more available».
    //
    // أي أن المستخدم يُمنَع **بعد** أن يختار لا **قبله** — وهو أسوأ
    // ترتيب: يبدو أن الرقم مقبول ثم يُرفض بلا سبب ظاهر على الشاشة.
    //
    // ── ولماذا الحساب هنا لا في الخادم ────────────────────────
    //
    // لأن السلّة تتغيّر بعد رسم الصفحة: يفتح المستخدم السلّة الجانبية
    // ويحذف سطراً، أو يضيف من تبويب آخر. قيمة يحسبها PHP مرّة واحدة
    // عند التحميل تشيخ فوراً وتعيد العطل من الجهة الأخرى — تسمح بأقلّ
    // مما يجوز.
    //
    // والبيانات كلّها موجودة في المتصفّح أصلاً: مخزون كل نسخة في
    // PRODUCT_VARIANTS، والسلّة في مرآة cart.js. فيبقى السقف صحيحاً
    // بعد كل تغيّر بلا إعادة تحميل، ما دمنا نستمع لـ`cart:updated`.
    function cartQtyFor(variantId) {
        const cart = window.getCartData ? window.getCartData() : [];
        // `Number()` على الطرفين ثم `===`: كلا الجانبين رقمٌ فعلاً —
        // CartModel::getForUser تُحوّل id وvariant_id بـ(int)، وpageData
        // تكتبهما أرقاماً — لكن التحويل الصريح يجعل ذلك عقداً مقروءاً
        // لا افتراضاً عن شكل JSON قادم من الخادم.
        const productId = Number(window.PRODUCT_ID);
        const vId       = Number(variantId);
        const line = cart.find(
            i => Number(i.id) === productId && Number(i.variant_id) === vId
        );

        return line ? Number(line.quantity) || 0 : 0;
    }

    function remainingFor(variant) {
        if (!variant) return 0;

        return Math.max(0, (Number(variant.stock) || 0) - cartQtyFor(variant.id));
    }

    const addCartBtn = document.getElementById('addCartBtn');

    /** يضبط سقف العدّاد وحالة زرَّي (+) و«Add To Cart» على المتاح. */
    function applyQtyLimits() {
        if (!qty) return;

        const variant   = productVariants.find(v => v.id === currentVariantId);
        const remaining = remainingFor(variant);

        qty.max = String(remaining);
        qty.dataset.remaining = String(remaining);

        // صفر متاح: العدّاد يعرض صفراً ولا يقبل رفعاً، والزرّان معطّلان.
        // (المخزون قد يكون موجوداً كلّه في السلّة — وهي حالة مختلفة عن
        // نفاد المخزون، فلا نُخفي الكتلة ولا نُظهر «أبلغني عند التوفّر».)
        const value = Math.min(Math.max(1, Number(qty.value) || 1), Math.max(remaining, 1));
        qty.value = remaining === 0 ? 0 : value;

        if (plus) plus.disabled = remaining === 0 || Number(qty.value) >= remaining;

        // ⚠️ لا نلمس زرّ الإضافة إن كان معطّلاً لسبب آخر: الزائر غير
        // المسجَّل يراه معطّلاً بـdata-action="self-enable" ليفتح مودال
        // الدخول. تفعيله هنا يكسر ذلك المسار.
        if (addCartBtn && !addCartBtn.hasAttribute('data-action')) {
            addCartBtn.disabled = remaining === 0;
        }

        const hint = document.getElementById('qtyRemainingHint');
        if (hint) {
            const inCart = cartQtyFor(currentVariantId);
            if (remaining === 0 && inCart > 0) {
                hint.textContent = `All ${inCart} available in your cart.`;
                hint.classList.remove('d-none');
            } else if (inCart > 0) {
                hint.textContent = `${inCart} already in your cart — ${remaining} more available.`;
                hint.classList.remove('d-none');
            } else {
                hint.classList.add('d-none');
            }
        }
    }

    if (plus && qty) {
        plus.onclick = () => {
            const v   = parseInt(qty.value, 10) || 0;
            const max = parseInt(qty.max, 10);
            if (v < (Number.isNaN(max) ? Infinity : max)) qty.value = v + 1;
            applyQtyLimits();
        };
    }
    if (minus && qty) {
        minus.onclick = () => {
            const v = parseInt(qty.value, 10) || 0;
            if (v > 1) qty.value = v - 1;
            applyQtyLimits();
        };
    }

    // الكتابة اليدوية تُقيَّد كذلك — وإلا التفّ المستخدم على الزرّين
    // بكتابة الرقم مباشرةً في الحقل.
    if (qty) qty.addEventListener('input', applyQtyLimits);

    // كل تغيّر في السلّة يعيد الحساب: حذف سطر من السلّة الجانبية يجب
    // أن يُعيد المتاح فوراً، لا بعد إعادة تحميل الصفحة.
    document.addEventListener('cart:updated', applyQtyLimits);

    applyQtyLimits();

    addCartBtn?.addEventListener('click', async () => {
        if (!qty) return;
        const q = parseInt(qty.value);
        const variant = productVariants.find(v => v.id === currentVariantId);
        if (!variant || variant.stock <= 0) return;

        // ⚠️ لم يعد يُبنى كائن منتج كامل: الخادم يخزّن «ماذا وكم» فقط،
        // والاسم واللون والسعر والصورة تُقرأ من القاعدة عند العرض.
        // بناؤها هنا كان يعني نسخةً ثانية قد تشيخ عن أصلها.
        const existing = (window.getCartData ? window.getCartData() : [])
            .find(i => i.id == window.PRODUCT_ID && i.variant_id == currentVariantId);
        const currentQtyInCart = existing ? existing.quantity : 0;

        if (currentQtyInCart + q > variant.stock) {
            if (typeof showToast==='function') showToast(`Only ${variant.stock - currentQtyInCart} more available!`,'error');
            return;
        }

        if (!(await window.cartAdd(window.PRODUCT_ID, currentVariantId, q))) return;

        if (typeof showToast==='function')     showToast('Added to cart!','success');
        qty.value = 1;
        const cb = document.querySelector('[data-bs-target="#cartSidebar"]');
        if (cb) { cb.classList.add('cart-bounce'); setTimeout(()=>cb.classList.remove('cart-bounce'),500); }
    });

    // ── Wishlist ─────────────────────────────────────────────────
    function buildWishlistProduct() {
        const variant = productVariants.find(v => v.id === currentVariantId) || productVariants[0] || {};
        return {
            id: window.PRODUCT_ID,
            variant_id: currentVariantId,
            color_name: variant.color_name || '',
            name: window.PRODUCT_NAME,
            price: variant.final_price || 0,
            image: variant.image || '',
            image_path: variant.image || '',
        };
    }

    function isInWishlist(prod) {
        const wl = JSON.parse(localStorage.getItem('wishlist') || '[]');
        return wl.some(i => i.id == prod.id && (i.variant_id ?? null) == (prod.variant_id ?? null));
    }

    function dedupeWishlist() {
        let wl = JSON.parse(localStorage.getItem('wishlist') || '[]');
        const seen = new Set();
        wl = wl.filter(item => {
            const key = item.id + ':' + (item.variant_id ?? '');
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
        localStorage.setItem('wishlist', JSON.stringify(wl));
    }

    function refreshWishBtnIcon(btnEl) {
        if (!btnEl) return;
        const inList = isInWishlist(buildWishlistProduct());
        if (btnEl.id === 'wishBtn2') {
            btnEl.innerHTML = inList ? '❤️ Added to Wishlist' : '🤍 Add to Wishlist';
        } else {
            btnEl.innerHTML = inList ? '❤️' : '🤍';
        }
    }

    function setupWishBtn(btnEl) {
        if (!btnEl) return;
        refreshWishBtnIcon(btnEl);
        btnEl.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof window.toggleWishlist !== 'function') {
                if (typeof showToast === 'function') showToast('Something went wrong, please refresh the page.', 'error');
                return;
            }
            const prod = buildWishlistProduct();
            window.toggleWishlist(prod.id, btnEl, prod);
            dedupeWishlist();
            refreshWishBtnIcon(document.getElementById('wishBtn'));
            refreshWishBtnIcon(document.getElementById('wishBtn2'));
            if (typeof showToast === 'function') {
                showToast(isInWishlist(prod) ? 'Added to wishlist!' : 'Removed from wishlist.', 'success');
            }
        });
    }
    setupWishBtn(document.getElementById('wishBtn'));
    setupWishBtn(document.getElementById('wishBtn2'));

    if (typeof window.initScrollReveal==='function') requestAnimationFrame(window.initScrollReveal);
});
