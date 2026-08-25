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

        const qtyInput = document.getElementById('productQty');
        if (qtyInput) { qtyInput.max = variant.stock; qtyInput.value = variant.stock > 0 ? 1 : 0; }

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

        const stockBadge = document.getElementById('stockBadge');
        if (stockBadge) {
            stockBadge.classList.remove('bg-danger', 'bg-warning', 'text-dark', 'bg-success');
            if (variant.stock === 0) {
                stockBadge.classList.add('bg-danger');
                stockBadge.textContent = 'Out of Stock';
            } else if (variant.stock <= 50) {
                stockBadge.classList.add('bg-warning', 'text-dark');
                stockBadge.textContent = `Limited (${variant.stock} left)`;
            } else {
                stockBadge.classList.add('bg-success');
                stockBadge.textContent = `In Stock (${variant.stock})`;
            }
        }

        currentVariantId = variant.id;
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
        const expanded = panel.style.display !== 'none';
        panel.style.display = expanded ? 'none' : 'block';
        icon.textContent = expanded ? '▾' : '▴';
        this.setAttribute('aria-expanded', String(!expanded));
    });

    // ── Qty & Cart ───────────────────────────────────────────────
    const qty   = document.getElementById('productQty');
    const plus  = document.getElementById('plusBtn');
    const minus = document.getElementById('minusBtn');

    if (plus && qty)  plus.onclick  = () => { const v=parseInt(qty.value); const max=parseInt(qty.max)||Infinity; if(v<max) qty.value=v+1; };
    if (minus && qty) minus.onclick = () => { const v=parseInt(qty.value); if(v>1) qty.value=v-1; };

    document.getElementById('addCartBtn')?.addEventListener('click', () => {
        if (!qty) return;
        const q = parseInt(qty.value);
        const variant = productVariants.find(v => v.id === currentVariantId);
        if (!variant || variant.stock <= 0) return;

        const product = {
            id: window.PRODUCT_ID,
            variant_id: currentVariantId,
            color_name: variant.color_name,
            name: window.PRODUCT_NAME,
            price: variant.final_price,
            image_path: variant.image,
            stock: variant.stock,
        };
        let cart = JSON.parse(localStorage.getItem('cart')||'[]');
        const ex = cart.find(i => i.id == product.id && i.variant_id == product.variant_id);
        const currentQtyInCart = ex ? ex.quantity : 0;

        if (currentQtyInCart + q > variant.stock) {
            if (typeof showToast==='function') showToast(`Only ${variant.stock - currentQtyInCart} more available!`,'error');
            return;
        }

        if (ex) { ex.quantity += q; ex.stock = variant.stock; } else { cart.push({...product, quantity:q}); }
        localStorage.setItem('cart',JSON.stringify(cart));
        if (typeof refreshCartUI==='function') refreshCartUI();
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
