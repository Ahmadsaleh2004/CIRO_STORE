<?php

/**
 * app/helpers/assets_helper.php
 * The asset tags (CSS) plus the theme bootstrap script.
 *
 * Loaded automatically from public/index.php by a glob over the helpers directory, so
 * it needs no manual require.
 *
 * Why this file?
 * After style.css was split into small files, each page has a single "bundle" (store or
 * admin) rather than a long list of <link> tags inside the view.
 * The bundle itself is nothing but a file of @imports — see public/css/store.css.
 */

/**
 * The entry files per bundle, in order.
 * admin loads store first, because the admin panel reuses the store's entire layer.
 *
 * The 'admin-auth' bundle was removed from here: nothing called it, and it contradicted
 * what public/css/admin/pages/login.css declares outright in its header — that it is a
 * standalone file not depending on tokens.css. The two standalone admin pages declare
 * their CSS file directly in $bareCss.
 *
 * @return list<string>
 */
function cssBundleFiles(string $bundle): array
{
    return match ($bundle) {
        'admin' => ['css/store.css', 'css/admin.css'],
        default => ['css/store.css'],
    };
}

/**
 * The manifest `npm run build` produces — each bundle's fingerprinted file name.
 *
 * Read once per request. Its absence is not an error but means "not built yet", so
 * cssBundle falls back to the chain of @imports.
 *
 * @return array<string, string>
 */
function cssManifest(): array
{
    static $manifest = null;
    if ($manifest !== null) {
        return $manifest;
    }

    $path = ROOTPATH . '/public/css/dist/manifest.json';
    if (!is_file($path)) {
        return $manifest = [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return $manifest = is_array($decoded) ? $decoded : [];
}

/**
 * Prints a bundle's <link> tags.
 *
 * ── Two paths, chosen between automatically ─────────────────
 *
 * **Built** (the manifest exists): one tag per bundle, pointing at a concatenated,
 * minified, fingerprinted file. store.css was 36 imports and admin.css 19, and the
 * browser does not know any of them exist until it has downloaded and parsed the parent
 * file — so the downloading is inherently serial rather than parallel.
 * Measured: 55 requests and 112 KB → two requests and 59 KB.
 *
 * **Not built** (no manifest): the chain of @imports, as it was. And this is the
 * preferred development mode — every file appears separately in DevTools, and editing
 * one of them shows immediately with no rebuild.
 *
 * Which is why there is no configuration flag choosing between them: **the manifest's
 * existence is the choice**. A separate flag would have allowed two meaningless states —
 * built and disabled, and not built but enabled (the latter being a page with no styling
 * at all).
 *
 * The fingerprint in the file name busts the cache on its own: changed content changes
 * the name, and unchanged content keeps it, so the visitor benefits from their cache.
 *
 * ⚠️ The admin bundle loads store first (see cssBundleFiles), and the order is
 * preserved on both paths.
 */
function cssBundle(string $bundle = 'store'): string
{
    $manifest = cssManifest();

    $out = '';
    foreach (cssBundleFiles($bundle) as $file) {
        // 'css/store.css' → 'store'
        $key  = basename($file, '.css');
        $href = $manifest[$key] ?? $file;
        $out .= '    <link rel="stylesheet" href="' . URLROOT . '/' . $href . '">' . "\n";
    }

    return $out;
}

/**
 * Prints a <script> tag for a JavaScript file, with content-based cache busting.
 *
 * ── The fault it exists for ─────────────────────────────────
 *
 * The files under js/ were served with no Cache-Control at all — with an ETag and a
 * Last-Modified alone. That leaves the decision to the browser, and many of them cache
 * the file heuristically and never ask about it again.
 *
 * And the effect was measured, not supposed: after fixing a function in
 * js/core/utils.js, the served file contained the fix while window did not know it — the
 * browser was running an old copy. Verified with fetch(cache:'reload'), which showed the
 * difference.
 *
 * And that is the worst thing that can happen to a security fix: it ships and never
 * arrives.
 *
 * ── The fix ─────────────────────────────────────────────────
 *
 * `?v=<fingerprint>` derived from the file's content. Changed content changes the URL
 * and busts the cache with certainty, and unchanged content keeps it, so the visitor
 * benefits from their cache.
 *
 * And the fingerprint comes from the content rather than the time: a timestamp changes
 * on every copy of the files, busting the cache for no reason, and every deployment
 * re-downloads everything.
 *
 * ⚠️ Why a query string rather than a fingerprinted name, as with the CSS bundles?
 * Because the JavaScript files reference one another by name, and because they are not
 * build output — they are edited directly. The query gives the same busting with no
 * build step.
 *
 * @param string $path A relative path under public/, such as 'js/core/utils.js'
 * @param bool   $defer
 */
/**
 * The external libraries: version and integrity hash, defined once.
 *
 * ── Why here rather than in the views ────────────────────────
 *
 * The tag used to be hand-written in **seven** places: the store and admin layouts,
 * the two head files, and three standalone bare pages (admin/login, store-reauth and
 * auth/reset-password). With the version number repeated in every one of them.
 *
 * And the duplication here is not ugliness but a direct security risk: adding
 * `integrity` or upgrading a version gets applied to six places and forgotten in the
 * seventh — which is exactly what was actually the case before this phase:
 * `head-bare.php` alone carried an integrity hash, and the other six carried nothing.
 * And because the tags were scattered, nobody could see the difference.
 *
 * Now the version and the hash live in one constant, and an upgrade is a two-line edit.
 *
 * ── Refreshing the hash when upgrading a version ─────────────
 *
 *   curl -sL <url> | openssl dgst -sha384 -binary | openssl base64 -A
 *
 * ⚠️ A wrong hash means the browser **refuses the file silently** — no error on the
 * page, just a missing library and modals that do not open. Always change the two
 * together.
 *
 * @var array<string, array{url: string, sri: string}>
 */
const VENDOR_ASSETS = [
    'bootstrap-css' => [
        'url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
        'sri' => 'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH',
    ],
    'bootstrap-js' => [
        'url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
        'sri' => 'sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz',
    ],

    // ⚠️ It used to be `sweetalert2@11` — an open range pulling in whatever 11.x is
    // published tomorrow, whatever it contains, onto the checkout page and the admin
    // panel alike. The number here is what that range resolved to at install time, so
    // there is no behavioural change — the change is that the behaviour is now **known**.
    //
    // ⚠️ And it is `sweetalert2.min.js`, not `sweetalert2.all.min.js`.
    //
    // The difference is not size: the `all` build carries its styles **inside the
    // JavaScript** and injects them at runtime into a <style> tag it creates itself. And
    // the content security policy in public/.htaccess forbids `style-src 'unsafe-inline'`
    // — so the browser refused that tag, and every SweetAlert dialog appeared as bare
    // text at the bottom of the page, with no styling and no positioning.
    //
    // And the effect went past appearance into function: `orders.js` awaits confirmation
    // from `await Swal.fire(...)` before taking an order, and the confirm button was not
    // visible at all — so "take order" looked broken while being perfectly sound.
    //
    // The bare build injects nothing, and its styles come from 'sweetalert2-css' below as
    // an external stylesheet that `style-src` permits. **The two are always included
    // together** — either without the other reproduces the same fault.
    'sweetalert2' => [
        'url' => 'https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.min.js',
        'sri' => 'sha384-hW8ZCQHtRH+nVOAkHZ4amZvYsAtKn1ZOvMV6dNag1Rb1thWmLZMBKTRxFV0cOxiK',
    ],
    'sweetalert2-css' => [
        'url' => 'https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.min.css',
        'sri' => 'sha384-dCW5imOdApH6OwpFau8cZNKjqVbJYnCA5q+8YsMYP3XwXKsV6Jfz1u6MZLnXaBsS',
    ],

    // Chart.js — the admin dashboard alone.
    //
    // The tag used to be printed as a string from AdminDashboardController, with neither
    // `integrity` nor `crossorigin` — meaning whatever the host sent was executed on a
    // page displaying sales and user data. Having it here returns it to the same contract
    // the other libraries are bound by.
    'chartjs' => [
        'url' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
        'sri' => 'sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g',
    ],
];

/**
 * A <script> tag for an external library, with its integrity hash.
 *
 * `crossorigin="anonymous"` is required, not decoration: without it the browser does
 * not read the cross-origin response, so it cannot verify the hash at all — and
 * `integrity` becomes a line with no effect.
 *
 * @throws \InvalidArgumentException On an unknown key — a programming error rather than
 *         a runtime condition, and failing loudly beats a silently incomplete tag.
 */
function vendorJs(string $key, bool $defer = true): string
{
    $asset = VENDOR_ASSETS[$key] ?? null;
    if ($asset === null) {
        throw new \InvalidArgumentException("Unknown vendor asset [{$key}].");
    }

    return '<script src="' . $asset['url'] . '"'
        . ' integrity="' . $asset['sri'] . '"'
        . ' crossorigin="anonymous"'
        . ($defer ? ' defer' : '') . '></script>' . "\n";
}

/** A <link> tag for an external stylesheet, with its integrity hash. */
function vendorCss(string $key): string
{
    $asset = VENDOR_ASSETS[$key] ?? null;
    if ($asset === null) {
        throw new \InvalidArgumentException("Unknown vendor asset [{$key}].");
    }

    return '<link rel="stylesheet" href="' . $asset['url'] . '"'
        . ' integrity="' . $asset['sri'] . '"'
        . ' crossorigin="anonymous">' . "\n";
}

/**
 * The `?v=` cache-busting suffix for an asset served straight out of public/.
 *
 * The files in public/css/dist and public/js/dist carry their fingerprint in the NAME, so
 * they need nothing from here. Everything else — a page's own stylesheet, a script tag —
 * is served under a fixed URL, and without a suffix the browser is free to keep whatever
 * copy it already has. It does exactly that: a stale product-details.css was holding the
 * phone rules out of the page long after the file on disk had them.
 *
 * The stamp is the first ten hex characters of the file's SHA-256: the same content always
 * produces the same URL, so a deploy that does not touch a file does not throw its cached
 * copy away either.
 *
 * @param string $relative Path under public/, with or without a leading slash.
 */
function assetStamp(string $relative): string
{
    static $stamps = [];

    $relative = ltrim($relative, '/');

    if (!isset($stamps[$relative])) {
        $disk = ROOTPATH . '/public/' . $relative;
        // A missing file is no reason to stop the page: the tag is printed without a
        // fingerprint and a 404 shows in the console — a clearer diagnosis than a blank page.
        $stamps[$relative] = is_file($disk)
            ? '?v=' . substr(hash_file('sha256', $disk), 0, 10)
            : '';
    }

    return $stamps[$relative];
}

function jsTag(string $path, bool $defer = true): string
{
    $relative = ltrim($path, '/');

    return '<script src="' . URLROOT . '/' . $relative . assetStamp($relative) . '"'
        . ($defer ? ' defer' : '') . '></script>' . "
";
}

/**
 * The JavaScript bundle manifest `npm run build` produces.
 *
 * @return array<string, string>
 */
function jsManifest(): array
{
    static $manifest = null;
    if ($manifest !== null) {
        return $manifest;
    }

    $path = ROOTPATH . '/public/js/dist/manifest.json';
    if (!is_file($path)) {
        return $manifest = [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return $manifest = is_array($decoded) ? $decoded : [];
}

/**
 * Prints a <script> tag for a concatenated bundle — or the individual file tags when
 * nothing has been built.
 *
 * ── Why ─────────────────────────────────────────────────────
 *
 * The home page used to request **eighteen JavaScript files**. And a browser allows six
 * concurrent connections per host over HTTP/1.1, so they queue. Measured:
 *
 *     first file starts:  467 ms
 *     last file finishes: 999 ms
 *     DOMContentLoaded: 1051 ms
 *
 * And the slider does not exist in the HTML: products-catalog.js builds it — the
 * fourteenth in the queue. So its space stays empty for more than a second after the
 * page appears.
 *
 * ── The two paths ──────────────────────────────────────────
 *
 * **Built**: one tag. **Not built**: the individual files as they were — the preferred
 * development mode, since every file appears separately in DevTools and an edit to one
 * shows immediately with no rebuild.
 *
 * And the manifest's existence is the choice, exactly as in cssBundle.
 *
 * ⚠️ The order inside a bundle is the order of these lists in build/build-js.mjs, which
 * is the footer's order character for character. The files share the global scope and
 * each depends on what the previous one defined — so any reordering breaks it silently.
 *
 * @param string       $bundle The bundle name: store | admin | store-auth
 * @param list<string> $fallback The individual file paths, for when nothing is built
 * @param bool         $defer
 */
function jsBundle(string $bundle, array $fallback, bool $defer = true): string
{
    $manifest = jsManifest();

    if (isset($manifest[$bundle])) {
        $path = $manifest[$bundle];
        // The fingerprint in the name busts the cache, so no ?v= is needed
        return '<script src="' . URLROOT . '/' . $path . '"'
            . ($defer ? ' defer' : '') . '></script>' . "
";
    }

    $out = '';
    foreach ($fallback as $file) {
        $out .= jsTag($file, $defer);
    }

    return $out;
}

/**
 * Prints a JSON data island that is copied into the global scope.
 *
 * ── Why ─────────────────────────────────────────────────────
 *
 * Fourteen pages used to pass their data to JavaScript through an inline <script>
 * block:
 *
 *     <script>window.dbProducts = <?= json_encode(...) ?>;</script>
 *
 * And that is **an executable block**, so any serious CSP blocks it. Which is why the
 * project's policy stayed in report-only mode: enforcing it would have broken all
 * fourteen pages.
 *
 * ── The fix ─────────────────────────────────────────────────
 *
 * `<script type="application/json">` **is not an executable block**. The browser does
 * not run it, and `script-src` does not concern it at all. So the data becomes an element
 * on the page that js/core/page-data.js reads and copies onto window.
 *
 * ── What did not change ─────────────────────────────────────
 *
 * **The `window.X` contract, exactly as it was.** The same names and the same values,
 * so not one line changes across the thirty-four JavaScript files. The data arrives by
 * another route, and whoever reads it cannot tell the difference.
 *
 * ⚠️ Three constraints on the content:
 *
 *   1. JSON_HEX_TAG is required: a value containing `</script>` would have closed the
 *      tag and opened an injection route. The encoding turns < and > into \u003C and
 *      \u003E.
 *   2. The data is read at DOMContentLoaded, so this tag must be printed **before** any
 *      script that reads window — and it is: the views print before the footer.
 *   3. The values pass into the JSON as they are — do not put anything in them the
 *      visitor must not see. This has not changed from the previous behaviour.
 *
 * @param array<string, mixed> $data Name/value pairs to copy onto window
 */
function pageData(array $data): string
{
    if ($data === []) {
        return '';
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
    );

    if ($json === false) {
        error_log('[Cairo Store] pageData: json_encode failed — ' . json_last_error_msg());
        return '';
    }

    return '<script type="application/json" data-page-data>' . $json . '</script>' . "
";
}

/**
 * A <link> tag for a single page's own CSS file (called from the controllers through
 * extraHead).
 *
 * Paths are relative to public/css — pageCss('store/pages/home.css'). Each one is
 * fingerprinted by assetStamp, for the reason described there: these sheets are the only
 * assets in the project served under an unchanging URL, and an edit to one used not to
 * reach a browser that had already seen it.
 */
function pageCss(string ...$paths): string
{
    $out = '';
    foreach ($paths as $p) {
        $relative = 'css/' . ltrim($p, '/');
        $out .= '<link rel="stylesheet" href="' . URLROOT . '/' . $relative
            . assetStamp($relative) . '">' . "\n";
    }
    return $out;
}

/**
 * A small script printed inside <head> before any visible content.
 *
 * It reads the stored theme and sets data-bs-theme on <html> immediately. Two reasons:
 *
 * 1) Bootstrap 5.3 reads its dark mode from data-bs-theme on <html> alone. The project
 *    used to set body.dark-mode only, so every Bootstrap component (the pagination, the
 *    dropdowns, .text-muted, the select arrow…) stayed on daylight colours over a dark
 *    background.
 *
 * 2) js/core/theme.js runs after the page paints, so a white flash appeared on every
 *    navigation in dark mode. Setting the attribute here precedes the first paint.
 *
 * class="dark-mode" on <body> stays as it is — all of the project's CSS depends on it —
 * and theme.js adds it on load.
 */
function themeBootScript(): string
{
    // ⚠️ Line endings are normalised to LF before the string leaves this function.
    //
    // The CSP hash in public/.htaccess is computed over the tag's contents byte for
    // byte, newlines included. And .gitattributes checks this file out as CRLF on
    // Windows and LF everywhere else — so the same source produces two different
    // hashes on two platforms, and whichever one is written into the policy, the
    // script is blocked on the other. It was measured both ways:
    //
    //     CRLF (Windows / XAMPP)  sha256-6TLKQaFcqhxCDjTk0QjqyBctIHNhyj10R3cAU65yIvQ=
    //     LF   (Linux / Docker)   sha256-n33GJ973YEUTpbG1xOj611JYTc5wI4V+8xmEtfPaLjk=
    //
    // Normalising here makes the emitted bytes — and therefore the hash — the same
    // everywhere, so one value in the policy is correct on every platform. This is
    // the same reason Migrator::checksum normalises before hashing.
    return str_replace("\r\n", "\n", <<<'HTML'
    <script>
    (function () {
        try {
            var t = localStorage.getItem('theme');
            document.documentElement.setAttribute('data-bs-theme', t === 'dark' ? 'dark' : 'light');
        } catch (e) {
            document.documentElement.setAttribute('data-bs-theme', 'light');
        }
    })();
    </script>

HTML);
}

/**
 * publicFileToDelete(string $relPath): ?string
 *
 * Turns a relative path stored in the database (images/x.jpg, say) into an absolute
 * path on disk **provided it stays inside public/**, and returns null if it escapes or
 * does not exist.
 *
 * Why it exists: the places deleting product images used to build the path as
 * `ROOTPATH . '/public/' . ltrim($p, '/')`. And `ltrim` strips leading slashes **without
 * stopping `..`** — so a value like `../../.env` escaped the directory. The sources of
 * these values are safe today (`uploadVariantImage` generates the whole name on the
 * server: product_<time>_<hex>.<ext>), so no hole is open — but the guard belongs in the
 * function rather than in the caller's habits, because any new write path into that
 * column becomes a silent hole.
 *
 * Containment through realpath rather than a string check: realpath resolves both `..`
 * and symbolic links, and comparing on the resolved result is the only comparison that
 * cannot be fooled.
 *
 * @param  string $relPath The path as stored (relative to public/)
 * @return string|null The absolute path safe to delete, or null if refused
 */
function publicFileToDelete(string $relPath): ?string
{
    $relPath = trim($relPath);
    if ($relPath === '') {
        return null;
    }

    $publicRoot = realpath(ROOTPATH . '/public');
    if ($publicRoot === false) {
        return null;
    }

    $candidate = realpath($publicRoot . DIRECTORY_SEPARATOR . ltrim($relPath, '/\\'));
    if ($candidate === false || !is_file($candidate)) {
        return null;   // Missing, or a directory
    }

    // The trailing separator is deliberate: without it a sibling directory whose name is
    // a prefix (public_backup, say) passes the str_starts_with check.
    if (!str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)) {
        error_log('[Cairo Store] refused to delete a file outside public/: ' . $relPath);
        return null;
    }

    return $candidate;
}
