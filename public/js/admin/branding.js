// ══════════════════════════════════════════════════════════════
// public/js/admin/branding.js — إدارة صفحة Manage Slider
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('brandingForm');
    if (!form) return; // هذا السكربت يعمل فقط بصفحة /admin/branding رغم تحميله بكل صفحات الأدمن

    const slidesContainer = document.getElementById('slidesContainer');
    const slideTemplate    = document.getElementById('slideTemplate');
    const itemTemplate     = document.getElementById('itemTemplate');
    const addSlideBtn      = document.getElementById('addSlideBtn');
    const saveBtn          = document.getElementById('saveBrandingBtn');
    const dirtyHint        = document.getElementById('brandingDirtyHint');

    // ── 1) بناء الشرائح الموجودة مسبقاً من window._existingSlidersData ──────
    function buildExistingSlides() {
        const data = window._existingSlidersData || [];
        data.forEach(slide => {
            const slideEl = addSlide();
            (slide.items || []).forEach(item => addItem(slideEl, item));
        });
        renumberSlides();
    }

    // ── 2) إضافة شريحة جديدة (فارغة أو من بيانات موجودة) ────────────────────
    function addSlide() {
        const clone = slideTemplate.content.firstElementChild.cloneNode(true);
        slidesContainer.appendChild(clone);

        clone.querySelector('.remove-slide-btn').addEventListener('click', () => {
            clone.remove();
            renumberSlides();
            markDirty();
        });
        clone.querySelector('.add-item-btn').addEventListener('click', () => {
            addItem(clone, null);
            renumberSlides();
            markDirty();
        });

        return clone;
    }

    // ── 3) إضافة عنصر/صورة داخل شريحة معيّنة ────────────────────────────────
    function addItem(slideEl, itemData) {
        const clone = itemTemplate.content.firstElementChild.cloneNode(true);
        slideEl.querySelector('.items-container').appendChild(clone);

        wireItemEvents(clone);
        if (itemData) fillItemFromData(clone, itemData);
        else {
            setActiveMode(clone, 'product'); // الافتراضي لعنصر جديد فارغ
            setDefaultPanel(clone, 'product');
        }

        return clone;
    }

    // ── 4) تعبئة عنصر ببيانات موجودة مسبقاً (وضع Edit) ───────────────────────
    function fillItemFromData(itemEl, data) {
        // Product panel
        if (data.product_id) {
            itemEl.querySelector('.field-product-id').value = data.product_id;
            const prev = itemEl.querySelector('.product-preview');
            prev.classList.remove('d-none');
            // product_image_url جاهز من الخادم (fixImagePath). لا نبنيه هنا:
            // البناء اليدوي كان يعطي /airpods.jpg بدل /images/airpods.jpg
            // لأن قاعدة البادئة تعرفها fixImagePath في PHP وحدها.
            prev.querySelector('.product-preview-img').src = data.product_image_url || '';
            prev.querySelector('.product-preview-name').textContent = data.product_name || '';
        }
        itemEl.querySelector('.field-product-link').value = data.product_link_url || '';
        itemEl.querySelector('.field-product-description').value =
            data.product_description || data.product_default_description || '';

        // Manual panel
        if (data.manual_image_path) {
            itemEl.querySelector('.field-existing-manual-image').value = data.manual_image_path;
            const prev = itemEl.querySelector('.manual-preview');
            prev.classList.remove('d-none');
            prev.querySelector('.manual-preview-img').src = data.manual_image_url || '';
        }
        itemEl.querySelector('.field-manual-link').value = data.manual_link_url || '';
        itemEl.querySelector('.field-manual-description').value = data.manual_description || '';

        // أيّ تبويب ظاهر افتراضياً = نفس active_mode المحفوظ
        setActiveMode(itemEl, data.active_mode === 'manual' ? 'manual' : 'product');
        setDefaultPanel(itemEl, data.active_mode === 'manual' ? 'manual' : 'product');
    }

    // ── 5) ربط أحداث عنصر واحد (تبديل تبويب، Default، حذف، اختيار منتج، رفع صورة) ──
    function wireItemEvents(itemEl) {
        itemEl.querySelector('.remove-item-btn').addEventListener('click', () => {
            itemEl.remove();
            renumberSlides();
            markDirty();
        });

        // أزرار تبديل العرض فقط (Product/Manual) — لا تغيّر active_mode الفعلي
        itemEl.querySelectorAll('.mode-toggle-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                setActiveMode(itemEl, btn.dataset.mode);
            });
        });

        // أزرار Default — هذه فقط تُحدد active_mode الفعلي المحفوظ بقاعدة البيانات
        itemEl.querySelectorAll('.default-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                setDefaultPanel(itemEl, btn.dataset.panel);
                markDirty();
            });
        });

        itemEl.querySelector('.open-product-picker-btn').addEventListener('click', () => {
            openProductPicker(itemEl);
        });

        itemEl.querySelector('.field-manual-image').addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;
            const prev = itemEl.querySelector('.manual-preview');
            prev.classList.remove('d-none');
            prev.querySelector('.manual-preview-img').src = URL.createObjectURL(file);
            markDirty();
        });

        // أي تغيير بأي حقل نصي داخل هذا العنصر = تغيير غير محفوظ
        itemEl.querySelectorAll('input, textarea').forEach(el => {
            el.addEventListener('input', markDirty);
        });
    }

    // ── 6) التبديل بين التبويبين (عرض فقط) ──────────────────────────────────
    function setActiveMode(itemEl, mode) {
        itemEl.querySelectorAll('.mode-toggle-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.mode === mode);
        });
        itemEl.querySelector('.product-panel').classList.toggle('d-none', mode !== 'product');
        itemEl.querySelector('.manual-panel').classList.toggle('d-none', mode !== 'manual');
    }

    // ── 7) تحديد التبويب الفعلي (Default) — إقصاء متبادل بين الاثنين فقط ────
    function setDefaultPanel(itemEl, panel) {
        itemEl.dataset.activeMode = panel; // نخزّنه بـ data attribute لقراءته لاحقاً عند التجميع
        itemEl.querySelectorAll('.default-btn').forEach(btn => {
            const isThis = btn.dataset.panel === panel;
            btn.classList.toggle('btn-success', isThis);
            btn.classList.toggle('btn-outline-secondary', !isThis);
            btn.textContent = isThis ? '⭐ Default (Active)' : 'Set as Default';
        });
        const indicator = itemEl.querySelector('.active-mode-indicator');
        indicator.innerHTML = panel === 'product'
            ? '<span class="u-accent">✓ Product is the active image</span>'
            : '<span class="u-accent">✓ Manual image is the active image</span>';
    }

    // ── 8) إعادة ترقيم كل الشرائح والعناصر (للعرض البصري فقط: "Slide 1", "Image 2"...) ──
    function renumberSlides() {
        slidesContainer.querySelectorAll('.slide-card').forEach((slideEl, sIdx) => {
            slideEl.querySelector('.slide-number').textContent = sIdx + 1;
            slideEl.querySelectorAll('.slide-item-card').forEach((itemEl, iIdx) => {
                itemEl.querySelector('.item-number').textContent = iIdx + 1;
            });
        });
    }

    // ── 9) Product Picker: بحث AJAX حي (Debounce) ────────────────────────────
    let currentPickerTargetItem = null;
    let searchDebounceTimer = null;

    function openProductPicker(itemEl) {
        currentPickerTargetItem = itemEl;
        const modalEl = document.getElementById('productPickerModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        document.getElementById('productPickerSearchInput').value = '';
        document.getElementById('productPickerResults').innerHTML =
            '<div class="text-center py-3 text-muted">Start typing to search for a product…</div>';
        modal.show();
    }

    document.getElementById('productPickerSearchInput').addEventListener('input', (e) => {
        clearTimeout(searchDebounceTimer);
        const q = e.target.value.trim();
        searchDebounceTimer = setTimeout(() => runProductSearch(q), 300);
    });

    function runProductSearch(q) {
        const resultsEl = document.getElementById('productPickerResults');
        resultsEl.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>';

        fetch(window.URLROOT + '/admin/branding/products/search?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.products.length) {
                    resultsEl.innerHTML = '<div class="text-center py-3 text-muted">No products found.</div>';
                    return;
                }
                resultsEl.innerHTML = data.products.map(p => `
                    <div class="d-flex align-items-center gap-2 p-2 product-picker-row"
                         class="u-picker-card"
                         data-id="${p.id}" data-name="${escHtml(p.name)}"
                         data-image="${escHtml(p.image)}" data-desc="${escHtml(p.description)}"
                         data-link="${escHtml(p.link)}">
                        <img src="${p.image}" class="u-thumb-48-cover">
                        <span class="fw-semibold">${escHtml(p.name)}</span>
                    </div>
                `).join('');

                resultsEl.querySelectorAll('.product-picker-row').forEach(row => {
                    row.addEventListener('click', () => selectProduct(row.dataset));
                });
            });
    }

    function selectProduct(data) {
        if (!currentPickerTargetItem) return;
        const itemEl = currentPickerTargetItem;

        itemEl.querySelector('.field-product-id').value = data.id;
        const prev = itemEl.querySelector('.product-preview');
        prev.classList.remove('d-none');
        prev.querySelector('.product-preview-img').src = data.image;
        prev.querySelector('.product-preview-name').textContent = data.name;

        // تعبئة تلقائية — الأدمن يقدر يعدّلها بعدين لأنها حقول عادية مو مقفولة
        const linkField = itemEl.querySelector('.field-product-link');
        const descField = itemEl.querySelector('.field-product-description');
        if (!linkField.value) linkField.value = data.link;
        if (!descField.value) descField.value = data.desc;

        bootstrap.Modal.getInstance(document.getElementById('productPickerModal')).hide();
        markDirty();
    }

    // ── 10) تتبّع التغييرات لتفعيل/تعطيل زر الحفظ ────────────────────────────
    function markDirty() {
        saveBtn.disabled = false;
        saveBtn.classList.remove('btn-disabled-faded');
        dirtyHint.style.display = 'inline';
    }

    // ── 11) قبل الإرسال: انسخ active_mode المخزّن بـ dataset لكل عنصر إلى حقل مخفي ──
    //        (لأن الفورم يحتاج اسم حقل حقيقي name="slides[i][items][j][active_mode]")
    form.addEventListener('submit', () => {
        renameAllFieldsBeforeSubmit();
    });

    function renameAllFieldsBeforeSubmit() {
        slidesContainer.querySelectorAll('.slide-card').forEach((slideEl, sIdx) => {
            slideEl.querySelectorAll('.slide-item-card').forEach((itemEl, iIdx) => {
                const prefix = `slides[${sIdx}][items][${iIdx}]`;
                setName(itemEl, '.field-product-id',              `${prefix}[product_id]`);
                setName(itemEl, '.field-product-link',            `${prefix}[product_link_url]`);
                setName(itemEl, '.field-product-description',     `${prefix}[product_description]`);
                setName(itemEl, '.field-manual-image',            `${prefix}[manual_image]`);
                setName(itemEl, '.field-existing-manual-image',   `${prefix}[existing_manual_image]`);
                setName(itemEl, '.field-manual-link',             `${prefix}[manual_link_url]`);
                setName(itemEl, '.field-manual-description',      `${prefix}[manual_description]`);

                // active_mode كحقل مخفي — أضِفه ديناميكياً إذا لم يكن موجوداً
                let hiddenMode = itemEl.querySelector('.field-active-mode-hidden');
                if (!hiddenMode) {
                    hiddenMode = document.createElement('input');
                    hiddenMode.type = 'hidden';
                    hiddenMode.className = 'field-active-mode-hidden';
                    itemEl.appendChild(hiddenMode);
                }
                hiddenMode.name  = `${prefix}[active_mode]`;
                hiddenMode.value = itemEl.dataset.activeMode || 'product';
            });
        });
    }

    function setName(itemEl, selector, name) {
        const el = itemEl.querySelector(selector);
        if (el) el.name = name;
    }

    // ── تشغيل ────────────────────────────────────────────────────────────
    addSlideBtn.addEventListener('click', () => { addSlide(); renumberSlides(); markDirty(); });
    buildExistingSlides();
});