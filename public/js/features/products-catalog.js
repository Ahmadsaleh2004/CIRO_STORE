// ══════════════════════════════════════════════════════════════
// js/features/products-catalog.js — the product display, the slider and the filtering
// ══════════════════════════════════════════════════════════════

/**
 * Toggle Wishlist (Central)
 */
window.toggleWishlist = (id, btnElement, productData = null) => {
    const wishlist = JSON.parse(localStorage.getItem('wishlist')) || [];
    const index  = wishlist.findIndex(item => item.id === id);

    if (index > -1) {
        wishlist.splice(index, 1);
        if (btnElement) btnElement.innerHTML = '🤍';
    } else {
        if (productData) {
            wishlist.push(productData);
        }
        if (btnElement) btnElement.innerHTML = '❤️';
    }
    localStorage.setItem('wishlist', JSON.stringify(wishlist));
    if (typeof updateCounters === 'function') updateCounters();
};

/**
 * Skeleton Loading
 */
function showSkeletons(containerId, count = 6) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = Array(count).fill(`
        <div class="col-lg-4 col-md-6 mb-4">
            <div class="skeleton-card">
                <div class="skeleton skeleton-img"></div>
                <div class="skeleton skeleton-line"></div>
                <div class="skeleton skeleton-line short"></div>
            </div>
        </div>`).join('');
}
window.showSkeletons = showSkeletons;

/**
 * Render the home page slider — now built entirely from the database, through
 * window.dbHomeSliders (from BrandingModel::getActiveSlidersForHome).
 */
function renderSlider(homeSliders) {
    const sliderInner  = document.getElementById('slider-inner');
    const sliderSection = document.getElementById('mainSlider');
    if (!sliderInner) return;

    // ⚠️ Do not rebuild what the server already rendered.
    //
    // The slider is now rendered in home.php and arrives ready in the HTML. Rebuilding it
    // here erases an image the browser has already loaded and downloads it again — a
    // visible flash and a wasted request, and the loss of fetchpriority="high" on the first
    // slide, which is the page's largest contentful paint.
    //
    // The function remains for live updates (the admin panel's preview, for instance) and
    // as a fallback should the server ever stop rendering it.
    if (sliderInner.children.length > 0) return;

    if (!homeSliders || !homeSliders.length) {
        // No slider data yet — hide the section entirely rather than leave an empty space
        if (sliderSection) sliderSection.style.display = 'none';
        return;
    }
    if (sliderSection) sliderSection.style.display = '';

    sliderInner.innerHTML = homeSliders.map((slide, index) => {
        const items = slide.items || [];
        const count = items.length;
        // A class setting the flex and hover behaviour from the image count (see home-slider.css)
        const countClass = count >= 5 ? 'compact-count' : ('count-' + count);

        const itemsHtml = items.map(item => {
            // ⚠️ The structure here must match app/views/home.php character for character.
            //
            // The page is rendered from the server first (because the slider is the LCP),
            // and this function rebuilds it on a live update. A structural difference between
            // the two means what the visitor sees changes shape after an update for no
            // comprehensible reason — and home-slider.css is written for one structure.
            const title = item.title || '';
            const desc  = item.description || '';

            const img = `<img src="${item.image_path}" alt="${escHtml(title || desc)}"
                              class="slide-item-img" loading="lazy">`;

            const caption = (title || desc)
                ? `<div class="slide-item-caption">`
                    + (title ? `<div class="slide-item-title">${escHtml(title)}</div>` : '')
                    + (desc ? `<div class="slide-item-desc">${escHtml(desc)}</div>` : '')
                    + `</div>`
                : '';

            const inner = `<div class="slide-item">${img}${caption}</div>`;

            return item.link_url
                ? `<a href="${escHtml(item.link_url)}" class="slide-item-link">${inner}</a>`
                : inner;
        }).join('');

        return `
        <div class="carousel-item ${index === 0 ? 'active' : ''}">
            <div class="slide-items-row ${countClass}">
                ${itemsHtml}
            </div>
        </div>`;
    }).join('');
}
window.renderSlider = renderSlider;

/**
 * Render Home Sections
 */
function renderHomeSections(allProducts) {
    const tagConfig = {
        'best-seller': { label: '⭐ Best Seller', cls: 'hpc-tag-best'    },
        'new':         { label: '🆕 New',          cls: 'hpc-tag-new'     },
        'limited':     { label: '🔥 Limited',       cls: 'hpc-tag-limited' },
        'regular':     { label: '🏷️ Sale',          cls: 'hpc-tag-regular' },
    };

    const buildCarousel = (trackId, list) => {
        const track = document.getElementById(trackId);
        if (!track) return;

        track.innerHTML = list.map(p => {
            const imgSrc = imagePathOrEmpty(p.image || p.image_path || '');
            const tag    = tagConfig[p.tag] || { label: '', cls: '' };
            return `
            <div class="carousel-item-wrap reveal">
                <a href="${window.BASE_URL}/product?id=${p.id}" class="home-product-card">
                    ${tag.label ? `<span class="hpc-tag ${tag.cls}">${tag.label}</span>` : ''}
                    ${window.buildProductPicture(imgSrc, escHtml(p.name), 'hpc-img')}
                    <div class="hpc-body">
                        <div class="hpc-name">${escHtml(p.name)}</div>
                        <div class="hpc-price">$${p.price}</div>
                    </div>
                </a>
            </div>`;
        }).join('');
    };

    buildCarousel('best-sellers-track',  allProducts.filter(p => p.tag === 'best-seller').slice(0, 7));

    const newArrivals = allProducts
        .filter(p => p.tag === 'new')
        .sort((a, b) => new Date(b.date_added || 0) - new Date(a.date_added || 0))
        .slice(0, 7);
    buildCarousel('new-arrivals-track', newArrivals);

    const exploreProducts = [...allProducts]
        .sort(() => Math.random() - 0.5)
        .slice(0, 7);
    buildCarousel('other-products-track', exploreProducts);

    document.querySelectorAll('.section-carousel-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const track = document.getElementById(btn.dataset.target);
            if (!track) return;
            const cardWidth = track.querySelector('.carousel-item-wrap')?.offsetWidth + 18 || 300;
            const dir = btn.classList.contains('prev-btn') ? -1 : 1;
            track.scrollBy({ left: dir * cardWidth * 2, behavior: 'smooth' });
        });
    });

    requestAnimationFrame(() => {
        if (typeof window.initScrollReveal === 'function') window.initScrollReveal();
    });
}
window.renderHomeSections = renderHomeSections;

// ── Catalog Helpers (used in pages/products.php) ──────────────

// ══════════════════════════════════════════════════════════════
// A card's counter ceiling = the stock **minus what is in the cart**
// ══════════════════════════════════════════════════════════════
//
// The list cards print `max="<?= $stock ?>"` — the absolute stock. It is the same fault
// as on the details page: somebody with five in stock and two in their cart raises the
// counter to five and is then refused on pressing add, with a toast saying
// "Only 3 more available". Stopping them after the choice rather than before it.
//
// And all the data is already on the card: the add button carries data-product-id,
// data-variant-id and data-stock. So nothing is needed from the server.
function cartQtyForCard(productId, variantId) {
    const cart = window.getCartData ? window.getCartData() : [];
    const line = cart.find(
        i => Number(i.id) === Number(productId)
          && Number(i.variant_id) === Number(variantId)
    );

    return line ? Number(line.quantity) || 0 : 0;
}

/** Resets `max` on every visible card, and disables the ones with nothing available. */
function applyCatalogQtyLimits() {
    document.querySelectorAll('[data-action="add-to-cart"]').forEach(btn => {
        const productId = btn.getAttribute('data-product-id');
        const variantId = btn.getAttribute('data-variant-id');
        const stock     = Number(btn.getAttribute('data-stock')) || 0;

        const remaining = Math.max(0, stock - cartQtyForCard(productId, variantId));

        const input = document.getElementById('qty-' + productId);
        if (input) {
            input.max = String(remaining);
            if (Number(input.value) > remaining) {
                input.value = String(Math.max(remaining, 1));
            }
        }

        btn.disabled = remaining === 0;
        btn.title = remaining === 0
            ? 'You already have all available stock in your cart.'
            : '';
    });
}

// Every cart change recomputes it — removing a line in the sidebar must restore the
// availability on the cards immediately. The event is emitted by refreshCartUI in cart.js
// after the initial fetch and after every change.
document.addEventListener('cart:updated', applyCatalogQtyLimits);
document.addEventListener('DOMContentLoaded', applyCatalogQtyLimits);

window.applyCatalogQtyLimits = applyCatalogQtyLimits;

window.changeQtyDB = (id, val) => {
    const input = document.getElementById('qty-' + id);
    if (!input) return;

    // ⚠️ `Number.isNaN`, not `|| Infinity`.
    //
    // It used to be `parseInt(max) || Infinity`, and `parseInt('0')` is zero — which is
    // falsy. So a card with zero available (all of its stock already in the cart) fell
    // through to Infinity, that is, to **no ceiling at all**: the counter rose without
    // limit in the one case where it should have stopped dead.
    const parsed = parseInt(input.getAttribute('max'), 10);
    const max    = Number.isNaN(parsed) ? Infinity : parsed;

    const v = parseInt(input.value, 10) + val;
    if (v >= 1 && v <= max) input.value = v;
};

// ⚠️ It no longer writes to localStorage: the cart has lived on the server since
// migration 0011, and cartAdd in js/features/cart.js is the only way in for writes.
//
// And the stock check stays here despite being a client-side check: its purpose is an
// immediate message ("two left") rather than protection. The protection lives in
// placeOrder, inside a transaction that locks the row — and a cart is an intention, not a
// reservation.
window.addToCartDB = async (id, variantId, price, stock) => {
    const input = document.getElementById('qty-' + id);
    const qty   = parseInt(input?.value || 1);
    if (!window.dbProducts?.find(x => sameId(x.id, id))) return;

    const existing = (window.getCartData ? window.getCartData() : [])
        .find(i => sameId(i.id, id) && sameVariant(i.variant_id, variantId));
    const currentQtyInCart = existing ? existing.quantity : 0;

    if (currentQtyInCart + qty > stock) {
        if (typeof showToast === 'function') showToast(`Only ${stock - currentQtyInCart} more available!`, 'error');
        return;
    }

    if (!(await window.cartAdd(id, variantId, qty))) return;

    if (typeof showToast === 'function') showToast('Added to cart!', 'success');
    if (input) input.value = 1;
    const cb = document.querySelector('[data-bs-target="#cartSidebar"]');
    if (cb) { cb.classList.add('cart-bounce'); setTimeout(() => cb.classList.remove('cart-bounce'), 500); }
};

// ── The home page: the slider and the product sections ───────
// Moved out of an inline <script> block in views/home.php. The data itself
// (window.dbHomeSliders and window.dbProducts) still arrives from the two json_encode
// lines there — passing data, not logic.
document.addEventListener('DOMContentLoaded', () => {
    // dbHomeSliders is declared by the home page alone; the products page declares only
    // dbProducts. The check is on that specifically, so this does not run on a page it does
    // not belong to.
    if (!Array.isArray(window.dbHomeSliders)) return;

    renderSlider(window.dbHomeSliders);

    if (window.dbProducts && window.dbProducts.length > 0) {
        // renderHomeSections does not call renderSlider internally, so it is called above
        const prods = window.dbProducts.map(p => ({
            ...p,
            image: p.image_path || p.image || '',
        }));
        renderHomeSections(prods);
    }
});

// ── The products page: the wishlist buttons ──────────────────
// Moved out of an inline <script> block in views/product/product.php. Each button already
// carries its product's data in data-product, so no PHP injection is needed here.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.favorite-btn[data-product]').forEach(btn => {
        let p;
        try {
            p = JSON.parse(btn.dataset.product);
        } catch (e) {
            console.error('favorite-btn: invalid data-product', e);
            return;
        }
        const wl = JSON.parse(localStorage.getItem('wishlist') || '[]');
        if (wl.some(i => sameId(i.id, p.id))) btn.innerHTML = '❤️';
        btn.addEventListener('click', () => window.toggleWishlist(p.id, btn, p));
    });
});

// ── Filters & Autocomplete (pages/products.php) ──────────────
document.addEventListener('DOMContentLoaded', () => {
    const searchEl = document.getElementById('search');
    const sortEl   = document.getElementById('sort');
    const slider   = document.getElementById('priceRange');
    const sliderLbl= document.getElementById('priceRangeVal');
    const resetBtn = document.getElementById('reset');
    const countEl  = document.getElementById('results-count');
    const items    = document.querySelectorAll('.product-item');

    if (!searchEl && !sortEl && !items.length) return;

    function applyFilters() {
        const q        = searchEl?.value.toLowerCase().trim() || '';
        const sort     = sortEl?.value || '';
        const maxPrice = parseInt(slider?.value || 9999);
        if (sliderLbl) sliderLbl.textContent = '≤$' + maxPrice;

        const visible = [];
        items.forEach(item => {
            let show = true;
            if (q      && !item.dataset.name.includes(q))      show = false;
            if (parseFloat(item.dataset.price) > maxPrice)     show = false;
            if (sort.startsWith('cat-')) {
                const cat = sort.replace('cat-', '');
                if (!item.dataset.cats.includes(cat))          show = false;
            }
            if (sort === 'price-u100' && parseFloat(item.dataset.price) >= 100) show = false;
            if (sort === 'price-u300' && parseFloat(item.dataset.price) >= 300) show = false;
            if (sort === 'price-u500' && parseFloat(item.dataset.price) >= 500) show = false;
            if (sort === 'price-o500' && parseFloat(item.dataset.price) <  500) show = false;
            item.style.display = show ? '' : 'none';
            if (show) visible.push(item);
        });

        const container = document.getElementById('products-container');
        if (container) {
            if (sort === 'az' || sort === 'za') {
                visible.sort((a,b) => sort==='az'
                    ? a.dataset.name.localeCompare(b.dataset.name)
                    : b.dataset.name.localeCompare(a.dataset.name));
                visible.forEach(el => container.appendChild(el));
            }
            if (sort === 'low' || sort === 'high') {
                visible.sort((a,b) => sort==='low'
                    ? parseFloat(a.dataset.price)-parseFloat(b.dataset.price)
                    : parseFloat(b.dataset.price)-parseFloat(a.dataset.price));
                visible.forEach(el => container.appendChild(el));
            }
        }
        if (countEl) countEl.textContent = `Showing ${visible.length} of ${items.length} products`;
        if (typeof window.initScrollReveal==='function') window.initScrollReveal();
    }

    searchEl?.addEventListener('input',  applyFilters);
    sortEl?.addEventListener('change',   applyFilters);
    slider?.addEventListener('input',    applyFilters);
    resetBtn?.addEventListener('click', () => {
        if (searchEl) searchEl.value = '';
        if (sortEl)   sortEl.value   = '';
        if (slider) { slider.value = 2000; if (sliderLbl) sliderLbl.textContent='≤$2000'; }
        items.forEach(el => el.style.display = '');
        if (countEl) countEl.textContent = `Showing ${items.length} products`;
    });

    if (countEl && items.length) countEl.textContent = `Showing ${items.length} products`;
    if (typeof window.initScrollReveal==='function') window.initScrollReveal();

    // ── Autocomplete ─────────────────────────────────────────
    const acList = document.getElementById('autocomplete-list');
    if (searchEl && acList && window.dbProducts) {
        searchEl.addEventListener('input', () => {
            const q = searchEl.value.toLowerCase().trim();
            acList.innerHTML = '';
            if (!q) { acList.style.display='none'; return; }
            const hits = window.dbProducts.filter(p => p.name.toLowerCase().includes(q)).slice(0,5);
            if (!hits.length) { acList.style.display='none'; return; }
            hits.forEach(p => {
                const li = document.createElement('li');
                li.textContent = p.name;
                li.addEventListener('click', () => {
                    window.location.href = window.BASE_URL + '/product?id=' + p.id;
                });
                acList.appendChild(li);
            });
            acList.style.display = 'block';
        });
        document.addEventListener('click', e => {
            if (!searchEl.contains(e.target)) acList.style.display = 'none';
        });
    }

    // ── URL ?cat= filter ─────────────────────────────────────
    const cat = new URLSearchParams(window.location.search).get('cat');
    if (cat && sortEl) {
        const opt = sortEl.querySelector(`option[value="cat-${cat}"]`);
        if (opt) { sortEl.value = `cat-${cat}`; applyFilters(); }
    }
});
