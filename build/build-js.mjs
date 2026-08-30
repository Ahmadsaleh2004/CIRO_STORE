/**
 * build/build-js.mjs
 * Merges the JS files into minified, fingerprinted bundles — as build-css.mjs does for the
 * styles.
 *
 * ── The problem, measured ──────────────────────────────────
 *
 * The home page used to request **eighteen JS files**. And a browser allows six concurrent
 * connections per host on HTTP/1.1, so the files queue up:
 *
 *     first file starts:  467 ms
 *     last file finishes: 999 ms
 *     DOMContentLoaded:  1051 ms
 *
 * And the slider does not exist in the HTML at all: products-catalog.js builds it from
 * window.dbHomeSliders. And that is fourteenth in the queue — so its place stays empty for
 * more than a second after the page appears. Which is exactly what feels slow.
 *
 * ── The order is the contract ──────────────────────────────
 *
 * The project's files are not ES modules: they share a global scope, and each later one
 * depends on what an earlier one defined. So the merge follows the footer's order **letter
 * for letter** — which is the same order the <script> tags execute in.
 *
 * Which is why there is no clever bundling and no dependency graph: just concatenation in
 * order. Any reordering breaks the global scope silently.
 *
 * ── Separate bundles rather than one ───────────────────────
 *
 * Because the admin pages load files the store pages do not need, and the reverse. A single
 * bundle would have forced a store visitor to download the whole control panel.
 */

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { transformSync } from 'esbuild';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const jsRoot = join(root, 'public', 'js');
const distDir = join(jsRoot, 'dist');

/**
 * The bundles — each list mirrors its footer's order letter for letter.
 *
 * ⚠️ page-data.js is **in no bundle**. It stays a separate tag without defer because it
 * copies the page's data island onto window, and everything below it reads from there.
 * Folding it into the bundle would keep it first inside the bundle, but the bundle itself
 * is deferred — so the data would become available later than it does today.
 */
const BUNDLES = {
    // app/views/inc/footer.php
    store: [
        'core/inline-actions.js',
        'core/utils.js',
        'core/csrf.js',
        'core/ui.js',
        'core/flash-toast.js',
        'core/theme.js',
        'core/modal-input-colors.js',
        // Colours the variant buttons from data-swatch through the CSSOM — a variant's
        // colour comes from the database so it cannot become a class, and a style= cannot
        // remain once 'unsafe-inline' is removed from style-src.
        'store/variant-swatches.js',
        'features/cart.js',
        'features/products-catalog.js',
        'features/auth.js',
        'features/wishlist.js',
        'main.js',
        'shared/order-cancel.js',
    ],

    // app/views/admin/inc/footer.php
    admin: [
        'core/inline-actions.js',
        'core/utils.js',
        'core/csrf.js',
        'core/ui.js',
        'core/flash-toast.js',
        'core/theme.js',
        'features/auth.js',
        'admin/products.js',
        'admin/branding.js',
        'admin/category-picker.js',
        'admin/orders.js',
        'admin/users.js',
        'admin/admins.js',
        'admin/manage-admins.js',
        'admin/admin-notifications.js',
        'admin/backup.js',
        'admin/support.js',
        'admin/site-settings.js',
        'shared/order-cancel.js',
        'admin/admin-layout/admin-navbar.js',
        'main.js',
    ],

    // Loaded on top of the store bundle for a signed-in user alone.
    'store-auth': ['features/notifications.js'],
};

function fail(message) {
    process.stderr.write(`\n  ✗ ${message}\n\n`);
    process.exit(1);
}

if (existsSync(distDir)) {
    for (const file of readdirSync(distDir)) {
        rmSync(join(distDir, file));
    }
} else {
    mkdirSync(distDir, { recursive: true });
}

const manifest = {};
let totalBefore = 0;
let totalAfter = 0;

for (const [name, files] of Object.entries(BUNDLES)) {
    const parts = [];

    for (const relative of files) {
        const path = join(jsRoot, relative);
        if (!existsSync(path)) {
            fail(`Missing file in the ${name} bundle: js/${relative}`);
        }

        const code = readFileSync(path, 'utf8');

        // ⚠️ A 'use strict' in one file becomes global to the whole bundle after
        // concatenation. The project's files rely on the non-strict global scope (assigning
        // to window, and functions at the top level), so switching strict mode on for a
        // file that did not ask for it breaks it silently. Each file is wrapped in an
        // immediately-invoked function so its strict mode stays confined to it.
        //
        // And the wrapping hides nothing: the top-level declarations in these files are
        // either assigned to window explicitly, or private by intent.
        const needsWrapper = /^\s*(['"])use strict\1/m.test(code);
        parts.push(
            needsWrapper
                ? `/* js/${relative} */\n(function(){\n${code}\n})();`
                : `/* js/${relative} */\n${code}`
        );
    }

    const merged = parts.join('\n;\n');

    const { code, warnings } = transformSync(merged, {
        loader: 'js',
        minify: true,
        // es2017: it matches what the files actually use (async/await, no more).
        // A newer target gains nothing, and an older one rewrites async into huge
        // generators.
        target: 'es2017',
        legalComments: 'none',
    });

    for (const w of warnings) {
        process.stdout.write(`  ⚠ ${name}: ${w.text}\n`);
    }

    const hash = createHash('sha256').update(code).digest('hex').slice(0, 12);
    const fileName = `${name}.${hash}.js`;

    writeFileSync(join(distDir, fileName), code);
    manifest[name] = `js/dist/${fileName}`;

    const before = Buffer.byteLength(merged);
    const after = Buffer.byteLength(code);
    totalBefore += before;
    totalAfter += after;

    process.stdout.write(
        `  ✓ ${name.padEnd(11)} ${String(files.length).padStart(2)} files · ` +
            `${(before / 1024).toFixed(1)} KB → ${(after / 1024).toFixed(1)} KB ` +
            `(${Math.round((1 - after / before) * 100)}% smaller)\n`
    );
}

writeFileSync(join(distDir, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);

process.stdout.write(
    `\n  Total: ${(totalBefore / 1024).toFixed(1)} KB → ${(totalAfter / 1024).toFixed(1)} KB\n` +
        '  ✓ public/js/dist/manifest.json\n\n'
);
