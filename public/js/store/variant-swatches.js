/**
 * variant-swatches.js — colouring the variant colour buttons.
 *
 * Why does this file exist at all?
 *
 * Each variant's colour comes from the database, so it is an open set that cannot become
 * a set of classes. And it used to be written straight into the markup:
 *
 *     style="border-left:14px solid #hex;"
 *
 * And that was the last thing preventing 'unsafe-inline' from being removed from the
 * CSP's style-src. A policy permitting inline styles cannot stop an injected one — so the
 * attribute had to disappear before the directive could be tightened.
 *
 * The fix: the value leaves PHP as data in data-swatch, and is written here into a
 * custom CSS property through the CSSOM. The CSP does not block that: it governs what is
 * in **the markup**, not what a permitted script writes. The rule itself (border-left)
 * stays in base/utilities.css under .u-swatch.
 *
 * The precaution: the value is validated before it is written. Its source is the
 * database and an admin types it, but setProperty with an unexpected value puts strange
 * text into the CSSOM for no benefit. Only the hexadecimal form passes.
 */

(function () {
    'use strict';

    const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

    function paint(root) {
        const nodes = (root || document).querySelectorAll('[data-swatch]');

        Array.prototype.forEach.call(nodes, function (el) {
            const value = el.getAttribute('data-swatch');

            if (!value || !HEX.test(value.trim())) {
                return;
            }

            el.classList.add('u-swatch');
            el.style.setProperty('--swatch', value.trim());
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            paint(document);
        });
    } else {
        paint(document);
    }

    // Exposed globally so any code injecting variant buttons after load can call it.
    window.paintVariantSwatches = paint;
})();
