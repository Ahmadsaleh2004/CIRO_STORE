// ══════════════════════════════════════════════════════════════
// js/core/utils.js — the shared pure functions
// ══════════════════════════════════════════════════════════════

/**
 * escHtml — escaping text as protection against XSS
 */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
window.escHtml = escHtml;
window.escapeHtml = escHtml; // For complete backward compatibility

/**
 * imagePathOrEmpty — a guard against null and undefined for an image path, nothing more.
 *
 * ⚠️ **This function fixes no path at all.** It used to be called fixImagePath, a name
 * promising what its PHP counterpart does (app/helpers/functions.php): prefixing images/
 * onto any path without a slash, passing absolute URLs through unchanged, and returning a
 * placeholder when empty. This does none of that.
 *
 * The old name was a trap: in the slider editor somebody built the path by hand,
 * believing the fixing was available in the browser, and out came /airpods.jpg instead of
 * /images/airpods.jpg — breaking every product image there.
 *
 * **The rule:** fixing an image path is always the server's responsibility. Pass the
 * path through fixImagePath() in PHP before sending it to the browser, and never build it
 * in JavaScript.
 */
function imagePathOrEmpty(imgPath) {
    return imgPath || '';
}
window.imagePathOrEmpty = imagePathOrEmpty;

// The old name stays aliased to the new one so no external caller breaks, and because
// removing it in one go is a wider change than this cleanup. All three callers inside the
// project were converted.
window.fixImagePath = imagePathOrEmpty;

/**
 * formatRelativeTime — turning a date into a relative time (Just now, 5m ago, and so on)
 */
function formatRelativeTime(dateStr) {
    if (!dateStr) return '';
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60)    return 'Just now';
    if (diff < 3600)  return `${Math.floor(diff/60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
    return `${Math.floor(diff/86400)}d ago`;
}
window.formatRelativeTime = formatRelativeTime;

/**
 * buildProductPicture — building a <picture> with a WebP fallback
 */
window.buildProductPicture = function (imagePath, altText, cssClass = '') {
    const webp = encodeImagePath(imagePath.replace(/\.(jpe?g|png)$/i, '.webp'));
    const src  = encodeImagePath(imagePath);
    const cls  = cssClass ? ` class="${cssClass}"` : '';
    return `<picture>
        <source srcset="${webp}" type="image/webp">
        <img src="${src}" alt="${altText}"${cls} loading="lazy">
    </picture>`;
};

/**
 * encodeImagePath — encodes the path's segments while keeping the slashes.
 *
 * ⚠️ **A space in a srcset is a separator between candidates, not an ordinary character.**
 *
 * This project's image names contain spaces: "apple watch.webp",
 * "ps4 controller.jpg" and "nintendo switch lite.jpg". So the browser read
 *
 *     <source srcset="…/images/apple watch.webp">
 *
 * two candidates — "…/images/apple" and "watch.webp" — and rejected both. It said so
 * verbatim in the console:
 *     Dropped srcset candidate "…/images/apple"
 * ten times in a single load of the products page.
 *
 * The result was that the WebP version — the entire point of <picture> — worked for no
 * image whose name contained a space. And the page looks perfectly fine because the
 * fallback <img> works, so the fault passes silently and the heavier jpg is always served.
 *
 * This mirrors the same encoding in fixImagePath() in PHP — the server-rendered side was
 * fixed there, and this serves the cards the browser builds.
 *
 * encodeURIComponent per segment rather than over the whole path: the latter turns the
 * slashes themselves into %2F and breaks it. Already-encoded segments are left alone so a
 * % is not encoded twice.
 */
function encodeImagePath(path) {
    if (!path) return path;

    const [head, ...rest] = String(path).split('?');
    const query = rest.length ? '?' + rest.join('?') : '';

    // ⚠️ The scheme and host are separated out before encoding.
    //
    // Paths arrive here in two shapes: relative ("images/x.jpg") and absolute
    // ("http://localhost/STORE/public/images/x.jpg") — the latter being what fixImagePath
    // in PHP produces.
    //
    // And encoding the segments without that separation turns "http:" into "http%3A" and
    // breaks the URL entirely. That happened in this function's first version, and a direct
    // check with an absolute path caught it before it reached the browser.
    const schemeMatch = head.match(/^([a-z][a-z0-9+.-]*:\/\/[^/]+)(\/.*)?$/i);
    const origin = schemeMatch ? schemeMatch[1] : '';
    const pathPart = schemeMatch ? schemeMatch[2] || '' : head;

    const encoded = pathPart
        .split('/')
        .map((segment) => (/%[0-9A-Fa-f]{2}/.test(segment) ? segment : encodeURIComponent(segment)))
        .join('/');

    return origin + encoded + query;
}
window.encodeImagePath = encodeImagePath;

/**
 * stockBadge — the stock status badge. **A mirror of getStockBadge() in
 * app/helpers/stock_badge_helper.php, and it must stay identical to it.**
 *
 * This rule used to be written **three times**, across two languages:
 *   1. the PHP helper (serving the server-rendered views)
 *   2. getStockBadgeJs in js/features/wishlist.js
 *   3. an inline if/else block in js/features/product-details.js
 *
 * And the two JavaScript copies already differed from each other: the first without an
 * "in stock" branch and the second with one — the same difference found between PHP and
 * the view in phase 5. Their agreeing today was an accident of maintenance, not a
 * guarantee.
 *
 * ⚠️ The threshold of 50, the labels and the classes are duplicated across two languages
 * deliberately — there is no way around it in a project with no build step to share the
 * constants. **If you change something here, change it in stock_badge_helper.php too**,
 * and the other way round. The two files point at each other.
 *
 * @param {number}  stock        The stock quantity (the column is unsigned, so no negatives)
 * @param {boolean} showInStock  Return a green badge when stock is plentiful?
 *                               Yes on the product details page; no on the products list
 *                               and the wishlist (a badge on every card is visual noise).
 * @returns {{label: string, class: string}|null}
 */
function stockBadge(stock, showInStock = false) {
    const n = Number(stock);

    if (n === 0) {
        return { label: 'Out of Stock', class: 'bg-danger' };
    }
    if (n > 0 && n <= 50) {
        return { label: `Limited (${n} left)`, class: 'bg-warning text-dark' };
    }
    if (showInStock) {
        return { label: `In Stock (${n})`, class: 'bg-success' };
    }
    return null;
}
window.stockBadge = stockBadge;

/**
 * sameId — comparing two identifiers that reached the page by different routes.
 *
 * The same product id arrives here in two types. From JSON — an API response, or a data
 * island read by js/core/page-data.js — it is a **number**, because the database column is
 * an integer and PDO runs with ATTR_EMULATE_PREPARES off, so an INT comes back an int. From
 * the DOM it is a **string**, because every value in `dataset` is a string: there is no
 * other type in an HTML attribute.
 *
 * So `3 === "3"` is false while the two are the same product. That is why these comparisons
 * were written with `==` for so long, and why replacing the operator alone would have been a
 * silent bug: `wishlist.filter(i => i.id !== btn.dataset.id)` keeps nothing, because every
 * number differs from every string. The type has to be unified before the comparison, not
 * the comparison loosened.
 *
 * Unified to a **string**, not a number, because Number('') is 0 — an empty attribute would
 * match the id 0 — and Number(null) is 0 as well, while `null == 0` is false. String() has
 * neither trap. And null or undefined is not an identifier at all, so it matches nothing,
 * itself included.
 *
 * @param {string|number|null|undefined} a
 * @param {string|number|null|undefined} b
 * @returns {boolean}
 */
function sameId(a, b) {
    if (a === null || a === undefined || b === null || b === undefined) return false;
    return String(a) === String(b);
}
window.sameId = sameId;

/**
 * sameVariant — as sameId, for the variant id, where the absence of a value carries meaning.
 *
 * A product with no variants stores `variant_id: null`, and a line in the cart or the
 * wishlist is identified by the pair (product, variant). So two nulls are **the same
 * variant** — the one that does not exist — whereas in sameId two absent ids are simply not
 * an identifier.
 *
 * Which is what `(a ?? null) == (b ?? null)` used to say: `null == null` is true, and
 * `null == 0` is false — a real variant numbered 0 would not equal an absent one. Both
 * hold below.
 *
 * @param {string|number|null|undefined} a
 * @param {string|number|null|undefined} b
 * @returns {boolean}
 */
function sameVariant(a, b) {
    const x = a ?? null;
    const y = b ?? null;
    if (x === null || y === null) return x === y;
    return String(x) === String(y);
}
window.sameVariant = sameVariant;
