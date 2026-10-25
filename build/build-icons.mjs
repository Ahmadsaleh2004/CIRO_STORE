/**
 * build/build-icons.mjs
 * Builds public/images/icons.svg — one sprite of the store's interface icons — from
 * Bootstrap Icons in node_modules.
 *
 * ── The problem, measured ──────────────────────────────────
 *
 * The store used emoji as its icons: 🏪 in the navbar and the footer, 🛒 on every card,
 * ❤️ 🌙 👤 🏠 beside them. And the first emoji on a page makes Chrome load its colour-emoji
 * font **inside the first layout**, while the screen is still blank. Tracing /wishlist with
 * and without its emoji, twice each, on the same machine:
 *
 *     first layout     1,130 ms  →  585 ms
 *     style & layout     5.7 s   →  3.8 s   (Lighthouse's 4× CPU throttle)
 *     first paint        3.8 s   →  2.85 s
 *
 * Measured on Windows; PageSpeed runs on Linux, whose colour-emoji font is far heavier.
 * And it takes one emoji anywhere in the rendered page, not only on screen — the first
 * layout covers the whole document, the footer included.
 *
 * ── Why a sprite file ──────────────────────────────────────
 *
 * Inlining every icon's path would add its bytes to every HTML response, and those are not
 * cached. One sprite is downloaded once and served from the cache afterwards; each icon is a
 * `<use href="icons.svg?v=…#i-name">` (see icon() in assets_helper.php and window.icon in
 * js/core/utils.js). Icons take the text colour through `fill="currentColor"`.
 *
 * ── The list is the contract ───────────────────────────────
 *
 * Only the icons named here exist in the sprite. icon('x') for a name not listed renders an
 * empty box, so a new icon is added here first and the build rerun.
 *
 * Bootstrap Icons is MIT-licensed; the version is pinned in package.json with -E.
 */

import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'node_modules', 'bootstrap-icons', 'icons');
const target = join(root, 'public', 'images', 'icons.svg');

const ICONS = [
    // navigation and chrome
    'shop', 'house', 'person', 'heart', 'heart-fill', 'heartbreak', 'bell', 'cart', 'bag',
    'moon', 'sun', 'chat-dots', 'box-arrow-right', 'award', 'shield-lock', 'key', 'stars',
    // forms and actions
    'eye', 'eye-slash', 'pencil', 'floppy', 'trash', 'plus-lg', 'check-circle', 'lock',
    'sliders', 'sort-alpha-down', 'currency-dollar', 'cash', 'credit-card', 'clipboard',
    'file-text', 'truck',
    // details and information
    'box-seam', 'geo-alt', 'telephone', 'envelope', 'envelope-heart', 'mailbox', 'clock',
    'calendar', 'globe', 'tag', 'lightning-fill', 'star-fill', 'fire', 'people', 'bullseye',
    // categories
    'phone', 'laptop', 'headphones', 'controller',
    // error pages
    'compass', 'tools',
];

function fail(message) {
    process.stderr.write(`\n  ✗ ${message}\n\n`);
    process.exit(1);
}

const symbols = ICONS.map((name) => {
    const path = join(source, `${name}.svg`);
    if (!existsSync(path)) {
        fail(`No icon named "${name}" in bootstrap-icons — check the name, or run npm ci.`);
    }

    const svg = readFileSync(path, 'utf8');
    const viewBox = /viewBox="([^"]+)"/.exec(svg)?.[1];
    const inner = /<svg[^>]*>([\s\S]*)<\/svg>/.exec(svg)?.[1];
    if (!viewBox || !inner) {
        fail(`Could not read the shape out of ${name}.svg — has the package's format changed?`);
    }

    return `<symbol id="i-${name}" viewBox="${viewBox}">${inner.replace(/\s*\n\s*/g, '')}</symbol>`;
});

const sprite =
    '<svg xmlns="http://www.w3.org/2000/svg">'
    + '<!-- Bootstrap Icons (MIT), built by build/build-icons.mjs — do not edit by hand -->'
    + symbols.join('')
    + '</svg>\n';

writeFileSync(target, sprite);

process.stdout.write(
    `  ✓ icons     ${ICONS.length} symbols · ${(Buffer.byteLength(sprite) / 1024).toFixed(1)} KB `
        + '→ public/images/icons.svg\n\n'
);
