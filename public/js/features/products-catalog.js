// ══════════════════════════════════════════════════════════════
// js/features/products-catalog.js — ميزات وعرض المنتجات والسلايدر والفلترة
// ══════════════════════════════════════════════════════════════

/**
 * Toggle Wishlist (Central)
 */
window.toggleWishlist = (id, btnElement, productData = null) => {
    let wishlist = JSON.parse(localStorage.getItem('wishlist')) || [];
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
 * Render Slider (Home Page) — الآن مبني بالكامل من قاعدة البيانات
 * عبر window.dbHomeSliders (من BrandingModel::getActiveSlidersForHome)
 */
function renderSlider(homeSliders) {
    const sliderInner  = document.getElementById('slider-inner');
    const sliderSection = document.getElementById('mainSlider');
    if (!sliderInner) return;

    // ⚠️ لا تُعِد بناء ما صُيِّر على الخادم.
    //
    // السلايدر صار يُصيَّر في home.php ويصل في HTML جاهزاً. وإعادة
    // بنائه هنا تمحو صورةً حمّلها المتصفح سلفاً وتُنزّلها من جديد —
    // وميضٌ مرئي وطلب مهدور، وضياع fetchpriority="high" على الشريحة
    // الأولى وهي أكبر عنصر مرئي في الصفحة.
    //
    // الدالة تبقى للتحديث الحيّ (معاينة لوحة التحكّم مثلاً) وللارتداد
    // لو صار الخادم لا يُصيّر يوماً.
    if (sliderInner.children.length > 0) return;

    if (!homeSliders || !homeSliders.length) {
        // لا توجد بيانات سلايدر بعد — أخفِ القسم بالكامل بدل ترك مساحة فارغة
        if (sliderSection) sliderSection.style.display = 'none';
        return;
    }
    if (sliderSection) sliderSection.style.display = '';

    sliderInner.innerHTML = homeSliders.map((slide, index) => {
        const items = slide.items || [];
        const count = items.length;
        // كلاس يحدد سلوك الفليكس/الهوفر حسب عدد الصور (راجع home-slider.css)
        const countClass = count >= 5 ? 'compact-count' : ('count-' + count);

        const itemsHtml = items.map(item => {
            const img = `<img src="${item.image_path}" alt="${escHtml(item.description || '')}"
                              class="slide-item-img" loading="lazy">`;
            const desc = item.description
                ? `<div class="slide-item-caption">${escHtml(item.description)}</div>`
                : '';
            const inner = `<div class="slide-item">${img}${desc}</div>`;

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
window.changeQtyDB = (id, val) => {
    const input = document.getElementById('qty-' + id);
    if (!input) return;
    const max = parseInt(input.getAttribute('max')) || Infinity;
    const v = parseInt(input.value) + val;
    if (v >= 1 && v <= max) input.value = v;
};

// ⚠️ لم تعد تكتب في localStorage: السلّة على الخادم منذ هجرة 0011،
// وcartAdd في js/features/cart.js هي المدخل الوحيد للكتابة.
//
// وفحص المخزون يبقى هنا رغم أنه فحصٌ في العميل: غرضه رسالةٌ فورية
// («بقيت قطعتان») لا حماية. الحماية في placeOrder داخل معاملة تقفل
// الصفّ — والسلّة نيّةٌ لا حجز.
window.addToCartDB = async (id, variantId, price, stock) => {
    const input = document.getElementById('qty-' + id);
    const qty   = parseInt(input?.value || 1);
    if (!window.dbProducts?.find(x => x.id == id)) return;

    const existing = (window.getCartData ? window.getCartData() : [])
        .find(i => i.id == id && i.variant_id == variantId);
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

// ── الصفحة الرئيسية: السلايدر وأقسام المنتجات ─────────────────
// نُقل من كتلة <script> مضمّنة في views/home.php. البيانات نفسها
// (window.dbHomeSliders و window.dbProducts) ما زالت تصل من سطرَي
// json_encode هناك — تمرير بيانات لا منطق.
document.addEventListener('DOMContentLoaded', () => {
    // dbHomeSliders تعلنها الرئيسية وحدها؛ صفحة المنتجات تعلن dbProducts
    // فقط. الفحص عليها تحديداً كي لا يعمل هذا على صفحة لا تخصّه.
    if (!Array.isArray(window.dbHomeSliders)) return;

    renderSlider(window.dbHomeSliders);

    if (window.dbProducts && window.dbProducts.length > 0) {
        // renderHomeSections لا تستدعي renderSlider داخلياً، فيُستدعى أعلاه
        const prods = window.dbProducts.map(p => ({
            ...p,
            image: p.image_path || p.image || '',
        }));
        renderHomeSections(prods);
    }
});

// ── صفحة المنتجات: أزرار المفضّلة ─────────────────────────────
// نُقل من كتلة <script> مضمّنة في views/product/product.php. كل زر
// يحمل بيانات منتجه في data-product أصلاً، فلا حاجة لأي حقن PHP هنا.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.favorite-btn[data-product]').forEach(btn => {
        let p;
        try {
            p = JSON.parse(btn.dataset.product);
        } catch (e) {
            console.error('favorite-btn: data-product غير صالح', e);
            return;
        }
        const wl = JSON.parse(localStorage.getItem('wishlist') || '[]');
        if (wl.some(i => i.id == p.id)) btn.innerHTML = '❤️';
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

        let visible = [];
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
