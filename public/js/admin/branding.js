// ══════════════════════════════════════════════════════════════
// public/js/admin/branding.js — the Manage Slider page
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('brandingForm');
    if (!form) return; // This script runs only on /admin/branding, though it loads on every admin page

    const slidesContainer = document.getElementById('slidesContainer');
    const slideTemplate    = document.getElementById('slideTemplate');
    const itemTemplate     = document.getElementById('itemTemplate');
    const addSlideBtn      = document.getElementById('addSlideBtn');
    const saveBtn          = document.getElementById('saveBrandingBtn');
    const dirtyHint        = document.getElementById('brandingDirtyHint');

    // ── 1) Build the existing slides from window._existingSlidersData ──────
    function buildExistingSlides() {
        const data = window._existingSlidersData || [];
        data.forEach(slide => {
            const slideEl = addSlide();
            (slide.items || []).forEach(item => addItem(slideEl, item));
        });
        renumberSlides();
    }

    // ── 2) Add a new slide (empty, or from existing data) ──────────────────
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

    // ── 3) Add an item or image inside a given slide ───────────────────────
    function addItem(slideEl, itemData) {
        const clone = itemTemplate.content.firstElementChild.cloneNode(true);
        slideEl.querySelector('.items-container').appendChild(clone);

        wireItemEvents(clone);
        if (itemData) fillItemFromData(clone, itemData);
        else {
            setActiveMode(clone, 'product'); // The default for a new, empty item
            setDefaultPanel(clone, 'product');
        }

        return clone;
    }

    // ── 4) Populate an item from existing data (edit mode) ─────────────────
    function fillItemFromData(itemEl, data) {
        // Product panel
        if (data.product_id) {
            itemEl.querySelector('.field-product-id').value = data.product_id;
            const prev = itemEl.querySelector('.product-preview');
            prev.classList.remove('d-none');
            // product_image_url arrives ready from the server (fixImagePath). It is not
            // built here: building it by hand produced /airpods.jpg instead of
            // /images/airpods.jpg, because only fixImagePath in PHP knows the prefix rule.
            prev.querySelector('.product-preview-img').src = data.product_image_url || '';
            prev.querySelector('.product-preview-name').textContent = data.product_name || '';
        }
        itemEl.querySelector('.field-product-link').value = data.product_link_url || '';

        // The title: the saved one if there is one, otherwise the product's name — which
        // is exactly what the read in BrandingModel returns (a COALESCE onto products.name).
        // So what the admin sees in the field is what the visitor sees on the image.
        itemEl.querySelector('.field-product-title').value =
            data.product_title || data.product_name || '';

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

        // ⚠️ No fallback — unlike product mode. There is no source to derive a title from
        // for an image the admin uploaded, and empty here means "no title line" rather than
        // "fill it from somewhere".
        itemEl.querySelector('.field-manual-title').value = data.manual_title || '';
        itemEl.querySelector('.field-manual-description').value = data.manual_description || '';

        // Whichever tab shows by default is the saved active_mode
        setActiveMode(itemEl, data.active_mode === 'manual' ? 'manual' : 'product');
        setDefaultPanel(itemEl, data.active_mode === 'manual' ? 'manual' : 'product');
    }

    // ── 5) Wire one item's events (tab switch, Default, delete, product choice, image upload) ──
    function wireItemEvents(itemEl) {
        itemEl.querySelector('.remove-item-btn').addEventListener('click', () => {
            itemEl.remove();
            renumberSlides();
            markDirty();
        });

        // The display-switching buttons alone (Product/Manual) — they do not change the real active_mode
        itemEl.querySelectorAll('.mode-toggle-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                setActiveMode(itemEl, btn.dataset.mode);
            });
        });

        // The Default buttons — these alone set the real active_mode stored in the database
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

        // Any change to any text field inside this item counts as an unsaved change
        itemEl.querySelectorAll('input, textarea').forEach(el => {
            el.addEventListener('input', markDirty);
        });
    }

    // ── 6) Switching between the two tabs (display only) ───────────────────
    function setActiveMode(itemEl, mode) {
        itemEl.querySelectorAll('.mode-toggle-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.mode === mode);
        });
        itemEl.querySelector('.product-panel').classList.toggle('d-none', mode !== 'product');
        itemEl.querySelector('.manual-panel').classList.toggle('d-none', mode !== 'manual');
    }

    // ── 7) Setting the real tab (Default) — mutually exclusive between the two ──
    function setDefaultPanel(itemEl, panel) {
        itemEl.dataset.activeMode = panel; // Stored in a data attribute so it can be read later when collecting
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

    // ── 8) Renumber every slide and item (for display alone: "Slide 1", "Image 2"…) ──
    function renumberSlides() {
        slidesContainer.querySelectorAll('.slide-card').forEach((slideEl, sIdx) => {
            slideEl.querySelector('.slide-number').textContent = sIdx + 1;
            slideEl.querySelectorAll('.slide-item-card').forEach((itemEl, iIdx) => {
                itemEl.querySelector('.item-number').textContent = iIdx + 1;
            });
        });
    }

    // ── 9) The product picker: live AJAX search (debounced) ────────────────
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
                // ⚠️ One merged class attribute — it used to be duplicated here:
                // `class="d-flex … product-picker-row"` and then `class="u-picker-card"`.
                // The browser takes the first and drops the second silently, so
                // u-picker-card was never applied at all.
                //
                // It is the same family of fault addressed in the views, but
                // DuplicateAttributeTest scanned app/views alone — and markup generated from
                // JavaScript was outside its reach. The scan was widened to cover public/js.
                //
                // ⚠️ And `src` is escaped like its neighbours: it used to be a raw
                // `${p.image}` while every attribute beside it passed through escHtml. A path
                // containing a quote breaks the attribute and opens what follows it.
                resultsEl.innerHTML = data.products.map(p => `
                    <div class="d-flex align-items-center gap-2 p-2 product-picker-row u-picker-card"
                         data-id="${p.id}" data-name="${escHtml(p.name)}"
                         data-image="${escHtml(p.image)}" data-desc="${escHtml(p.description)}"
                         data-link="${escHtml(p.link)}">
                        <img src="${escHtml(p.image)}" class="u-thumb-48-cover" alt="">
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

        // Automatic filling — the admin can edit these afterwards, since they are ordinary
        // fields rather than locked ones
        //
        // And the `if (!field.value)` condition is deliberate in all three: somebody who
        // shortened a title or edited a description and then switched products is not
        // surprised by having what they wrote erased.
        //
        // ⚠️ And this panel alone. The Manual panel has no automatic filling because it has
        // no source: an uploaded image carries neither a name nor a description.
        const linkField  = itemEl.querySelector('.field-product-link');
        const titleField = itemEl.querySelector('.field-product-title');
        const descField  = itemEl.querySelector('.field-product-description');
        if (!linkField.value)  linkField.value  = data.link;
        if (!titleField.value) titleField.value = data.name;
        if (!descField.value)  descField.value  = data.desc;

        bootstrap.Modal.getInstance(document.getElementById('productPickerModal')).hide();
        markDirty();
    }

    // ── 10) Tracking changes to enable and disable the save button ─────────
    function markDirty() {
        saveBtn.disabled = false;
        saveBtn.classList.remove('btn-disabled-faded');
        // `d-none` in the markup carries !important — only classList removes it.
        dirtyHint.classList.remove('d-none');
    }

    // ── 11) Before submitting: copy each item's dataset active_mode into a hidden field ──
    //        (because the form needs a real field name, name="slides[i][items][j][active_mode]")
    form.addEventListener('submit', () => {
        renameAllFieldsBeforeSubmit();
    });

    function renameAllFieldsBeforeSubmit() {
        slidesContainer.querySelectorAll('.slide-card').forEach((slideEl, sIdx) => {
            slideEl.querySelectorAll('.slide-item-card').forEach((itemEl, iIdx) => {
                const prefix = `slides[${sIdx}][items][${iIdx}]`;
                setName(itemEl, '.field-product-id',              `${prefix}[product_id]`);
                setName(itemEl, '.field-product-link',            `${prefix}[product_link_url]`);
                setName(itemEl, '.field-product-title',           `${prefix}[product_title]`);
                setName(itemEl, '.field-product-description',     `${prefix}[product_description]`);
                setName(itemEl, '.field-manual-image',            `${prefix}[manual_image]`);
                setName(itemEl, '.field-existing-manual-image',   `${prefix}[existing_manual_image]`);
                setName(itemEl, '.field-manual-link',             `${prefix}[manual_link_url]`);
                setName(itemEl, '.field-manual-title',            `${prefix}[manual_title]`);
                setName(itemEl, '.field-manual-description',      `${prefix}[manual_description]`);

                // active_mode as a hidden field — added dynamically when it is not there
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

    // ── Start ─────────────────────────────────────────────────────────────
    addSlideBtn.addEventListener('click', () => { addSlide(); renumberSlides(); markDirty(); });
    buildExistingSlides();
});