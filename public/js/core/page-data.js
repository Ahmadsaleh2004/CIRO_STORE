// ══════════════════════════════════════════════════════════════
// public/js/core/page-data.js — moving the page's data into the global scope
// ══════════════════════════════════════════════════════════════
//
// Fourteen pages used to pass their data through an inline <script> block:
//
//     <script>window.dbProducts = { … };</script>
//
// That is an executable block, so any serious CSP blocks it — which is why the project's
// policy stayed in report-only mode the whole time.
//
// Now the views print a <script type="application/json"> island — which is not an
// executable block, so script-src does not concern it at all — and this file copies it
// onto window.
//
// ⚠️ **It must load first, before any file that reads window.**
// Its place in footer.php and admin/inc/footer.php is at the top of the script list,
// before the third-party bundles. Moving it later breaks every page depending on its data.
//
// And why not defer, like the rest? Because defer postpones execution until after the
// document is parsed — exactly what we want for the others, but here it means another
// deferred file could precede it in order should the tags ever be rearranged. Loading
// synchronously makes the precedence a property that no reordering can overturn.

// ── A second pass once parsing completes ─────────────────────
//
// Synchronous loading guarantees precedence, but along with it guarantees that what has
// not been parsed yet **is not seen**. And an island printed below the footer — after this
// file's tag — vanished without a trace: no error, no warning, just an undefined
// `window.X` and an entire feature that does not work.
//
// And that actually happened: admin/orders/details.php assigned its island to
// $extraScripts, and the footer prints $extraScripts after this file. So
// ADMIN_ORDER_DETAILS never reached window, window.handleTakeIt (defined from it) was
// never defined, and the "Take It" button looked broken.
//
// So the first pass stays synchronous for its precedence, and a second pass at
// DOMContentLoaded picks up whatever was created late. Each element is marked as it is
// processed, so it is not read twice and does not warn about itself.
(function () {
    'use strict';

    const PROCESSED = 'data-page-data-loaded';

    function absorb() {
        const islands = document.querySelectorAll(
            'script[type="application/json"][data-page-data]:not([' + PROCESSED + '])'
        );

        for (let i = 0; i < islands.length; i++) {
            const island = islands[i];
            island.setAttribute(PROCESSED, '');

            const raw = island.textContent;
            if (!raw) continue;

            let payload;
            try {
                payload = JSON.parse(raw);
            } catch (e) {
                // Malformed data means a page with no function, and silence here makes the
                // cause untraceable. The page keeps working on what is left, and we report it
                // outright.
                console.error('[page-data] could not parse the data island:', e);
                continue;
            }

            if (!payload || typeof payload !== 'object') continue;

            for (const key in payload) {
                if (!Object.prototype.hasOwnProperty.call(payload, key)) continue;

                // ⚠️ Nothing already present is overwritten. Two islands carrying the same
                // key is a programming error, and writing silently would make the winner
                // follow the elements' order in the document — an order nobody intended.
                if (key in window && window[key] !== undefined && window[key] !== null) {
                    console.warn('[page-data] the key [' + key + '] is already defined — left as it is.');
                    continue;
                }

                window[key] = payload[key];
            }
        }
    }

    absorb();

    // ⚠️ The second pass is a safety net, not a licence: any file reading window directly
    // in its body (rather than inside DOMContentLoaded) will run before it. So the island's
    // correct place remains **before the footer**, and
    // tests/Unit/PageDataIslandTest.php enforces that.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', absorb);
    }
})();
