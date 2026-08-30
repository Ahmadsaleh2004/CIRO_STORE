// ══════════════════════════════════════════════════════════════
// js/features/product-details.js — the product details page's interactions
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

        // ⚠️ No `qtyInput.max = variant.stock` here.
        //
        // The absolute stock is not the right ceiling: whatever this user already has of
        // this variant in their cart is spoken for. The whole calculation lives in
        // applyQtyLimits below, called after currentVariantId is set at the end of this
        // function.
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

        // The rule lives in stockBadge() in js/core/utils.js, mirroring getStockBadge() in
        // PHP. It used to be written here as an if/else — the third copy of the same rule in
        // the project. The details page alone shows the green badge, so the second argument
        // is true (the same thing the view does when rendering from the server).
        const stockBadgeEl = document.getElementById('stockBadge');
        if (stockBadgeEl) {
            const badge = stockBadge(variant.stock, true);
            stockBadgeEl.classList.remove('bg-danger', 'bg-warning', 'text-dark', 'bg-success');
            // badge.class may carry two classes ('bg-warning text-dark'), so it is split
            badge.class.split(' ').forEach(c => stockBadgeEl.classList.add(c));
            stockBadgeEl.textContent = badge.label;
        }

        currentVariantId = variant.id;

        // Once the chosen variant is fixed: the availability is recomputed for it, not for
        // the previous one.
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
        // ⚠️ The state is read from `d-none`, not from style.display.
        //
        // The panel starts with `d-none` in the markup, and style.display starts as an
        // empty string — so `expanded` was true on the first click, hiding something already
        // hidden and showing nothing. And even if it had shown, it would not have helped:
        // `d-none` carries !important and beats any inline style.
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
    // The counter's ceiling = the stock **minus what is in the cart**
    // ══════════════════════════════════════════════════════════
    //
    // The ceiling used to be the absolute stock: `max="<?= $stock ?>"` in the view, and
    // `qtyInput.max = variant.stock` when the colour changed. So somebody with five in
    // stock and two in their cart had a counter that let them pick five, and was then
    // refused on pressing "Add To Cart" with a toast saying "Only 3 more available".
    //
    // Which means the user is stopped **after** choosing rather than **before** — the
    // worst order: the number looks acceptable and is then refused with no visible reason
    // on screen.
    //
    // ── And why the calculation lives here rather than on the server ──
    //
    // Because the cart changes after the page renders: the user opens the sidebar and
    // removes a line, or adds from another tab. A value PHP computes once at load goes
    // stale immediately and reproduces the fault from the other side — permitting less
    // than it should.
    //
    // And all the data is already in the browser: each variant's stock in
    // PRODUCT_VARIANTS, and the cart in cart.js's mirror. So the ceiling stays correct
    // after every change with no reload, as long as we listen for `cart:updated`.
    function cartQtyFor(variantId) {
        const cart = window.getCartData ? window.getCartData() : [];
        // `Number()` on both sides then `===`: both really are numbers —
        // CartModel::getForUser casts id and variant_id with (int), and pageData writes them
        // as numbers — but the explicit conversion makes that a legible contract rather than
        // an assumption about the shape of JSON coming from the server.
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

    /** Sets the counter's ceiling and the state of the (+) and "Add To Cart" buttons from what is available. */
    function applyQtyLimits() {
        if (!qty) return;

        const variant   = productVariants.find(v => v.id === currentVariantId);
        const remaining = remainingFor(variant);

        qty.max = String(remaining);
        qty.dataset.remaining = String(remaining);

        // Zero available: the counter shows zero and accepts no increase, and both buttons
        // are disabled. (The stock may exist entirely inside the cart — a different case
        // from being out of stock, so the block is not hidden and "notify me when
        // available" is not shown.)
        const value = Math.min(Math.max(1, Number(qty.value) || 1), Math.max(remaining, 1));
        qty.value = remaining === 0 ? 0 : value;

        if (plus) plus.disabled = remaining === 0 || Number(qty.value) >= remaining;

        // ⚠️ The add button is not touched if it is disabled for another reason: a
        // signed-out visitor sees it disabled with data-action="self-enable" so it opens the
        // login modal. Enabling it here breaks that path.
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

    // Typing by hand is constrained too — otherwise the user gets around both buttons by
    // typing the number straight into the field.
    if (qty) qty.addEventListener('input', applyQtyLimits);

    // Every cart change recomputes it: removing a line in the sidebar must restore the
    // availability immediately, not after a page reload.
    document.addEventListener('cart:updated', applyQtyLimits);

    applyQtyLimits();

    addCartBtn?.addEventListener('click', async () => {
        if (!qty) return;
        const q = parseInt(qty.value);
        const variant = productVariants.find(v => v.id === currentVariantId);
        if (!variant || variant.stock <= 0) return;

        // ⚠️ A full product object is no longer built: the server stores "what and how
        // many" alone, and the name, colour, price and image are read from the database at
        // display time. Building them here meant a second copy that could go stale against
        // its original.
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
