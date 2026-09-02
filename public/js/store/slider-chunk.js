// ══════════════════════════════════════════════════════════════
// public/js/store/slider-chunk.js — re-dealing the home slider's images
// on a phone
// ══════════════════════════════════════════════════════════════
//
// A slide holds every image the admin put in that slider row — up to six. On a laptop
// they sit side by side and it reads well. At 375px each one measured 61 CSS pixels
// wide against 320 tall: a column of slivers, not photographs of products.
//
// The first attempt fixed the width by HIDING the surplus with CSS. That was the wrong
// trade: it made the remaining images legible by making the others unreachable — a
// visitor on a phone simply never saw them, and nothing said so.
//
// This deals them again instead. On a phone each original slide becomes as many slides
// as it needs at three images each: a slide of five turns into 3 + 2, a slide of six
// into 3 + 3. Nothing is hidden and nothing is lost; the carousel is one swipe longer.
//
// ⚠️ The grouping is per original slide, never across them. Each slide is one row in
// home_slider_items — an editorial grouping somebody made in the control panel — and
// letting a leftover image drift into the next slider's images would silently rewrite
// that choice.
//
// ⚠️ Nodes are MOVED, never re-rendered from strings. appendChild relocates an existing
// element, so an image the browser has already fetched stays fetched, and the first
// image keeps the fetchpriority="high" that home.php sets on it — it is the page's
// largest contentful paint, and rebuilding this markup would download it a second time.
//
// ⚠️ And the CSS still hides the overflow until this file has run: home-slider.css hides
// the fourth image onward while the row lacks data-chunked. That is what keeps the first
// paint from showing six slivers and then jumping to three — the count is right before
// this code runs, and this code only rehouses what was already hidden.

(function () {
    'use strict';

    const INNER_ID = 'slider-inner';
    const CAROUSEL_ID = 'mainSlider';

    // Three per slide on a phone, two at the 320px floor — where three would be ~100px
    // each, the very complaint this file exists to answer.
    //
    // ⚠️ These two numbers are duplicated in store/pages/home-slider.css, which hides the
    // same overflow before this file runs. They have to agree: if the CSS shows three and
    // this deals two, the third image blinks in and then moves. Change them together.
    const PHONE_MAX = 767;
    const NARROW_MAX = 320;

    /** The original grouping, captured once: an array of arrays of item elements. */
    let original = null;
    /** The chunk size currently applied — 0 means the server's own grouping. */
    let applied = null;
    let resizeTimer = null;

    function chunkSizeFor(width) {
        if (width > PHONE_MAX) return 0;
        return width <= NARROW_MAX ? 2 : 3;
    }

    /** count-N drives flex and hover behaviour in home-slider.css; 5+ is compact-count. */
    function countClass(n) {
        return n >= 5 ? 'compact-count' : 'count-' + n;
    }

    function capture(inner) {
        const slides = [];
        const rows = inner.querySelectorAll('.slide-items-row');
        for (let i = 0; i < rows.length; i++) {
            slides.push(Array.prototype.slice.call(rows[i].children));
        }
        return slides;
    }

    /** Splits one slide's items into groups of at most `size` (0 = keep as one group). */
    function group(items, size) {
        if (!size || items.length <= size) return [items];
        const out = [];
        for (let i = 0; i < items.length; i += size) {
            out.push(items.slice(i, i + size));
        }
        return out;
    }

    function build(inner, size) {
        let groups = [];
        for (let i = 0; i < original.length; i++) {
            groups = groups.concat(group(original[i], size));
        }

        const fragment = document.createDocumentFragment();
        for (let g = 0; g < groups.length; g++) {
            const slide = document.createElement('div');
            slide.className = 'carousel-item' + (g === 0 ? ' active' : '');

            const row = document.createElement('div');
            row.className = 'slide-items-row ' + countClass(groups[g].length);
            // Tells home-slider.css that this row has been dealt and every child in it is
            // meant to be visible.
            row.setAttribute('data-chunked', '');

            for (let j = 0; j < groups[g].length; j++) {
                row.appendChild(groups[g][j]); // a move, not a copy
            }

            slide.appendChild(row);
            fragment.appendChild(slide);
        }

        // Bootstrap's carousel holds a reference to the active element. Replacing the
        // slides under it leaves that reference pointing at a detached node, and the next
        // swipe throws. Disposing first and re-creating afterwards is the supported way.
        const carousel = document.getElementById(CAROUSEL_ID);
        const instance = window.bootstrap && window.bootstrap.Carousel
            ? window.bootstrap.Carousel.getInstance(carousel)
            : null;
        if (instance) instance.dispose();

        inner.textContent = '';
        inner.appendChild(fragment);

        if (carousel && window.bootstrap && window.bootstrap.Carousel) {
            new window.bootstrap.Carousel(carousel);
        }
    }

    function sync() {
        const inner = document.getElementById(INNER_ID);
        if (!inner) return; // every page but the home page

        if (original === null) {
            original = capture(inner);
            if (original.length === 0) return; // no slider configured
        }

        const size = chunkSizeFor(window.innerWidth);
        if (size === applied) return; // nothing changed — do no DOM work at all

        // ⚠️ The first run on a laptop does nothing, deliberately. The markup already IS
        // the server's grouping, so rebuilding it would move every node, dispose and
        // re-create the carousel, and hand back exactly what was there — on the page whose
        // largest contentful paint is this very element. Only recording the state is left.
        if (applied === null && size === 0) {
            applied = 0;
            return;
        }

        applied = size;
        build(inner, size);
    }

    /** Called again after renderSlider replaces the markup on a live update. */
    function refresh() {
        original = null;
        applied = null;
        sync();
    }

    window.addEventListener('resize', function () {
        // Only the crossing of a breakpoint matters, and sync() returns immediately when
        // the size is unchanged — so a drag across the screen costs one timer, not one
        // rebuild per pixel.
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(sync, 150);
    });

    window.sliderChunkRefresh = refresh;

    sync();
})();
