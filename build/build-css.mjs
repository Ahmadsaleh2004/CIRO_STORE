/**
 * build/build-css.mjs
 * Merges the @import chains into one minified file per bundle, with a content fingerprint.
 *
 * ── The problem ──────────────────────────────────────────────
 *
 * store.css and admin.css are files of nothing but @import: 36 and 19 of them. And the
 * browser does not know any of them exists until it has downloaded the parent file and
 * parsed it — so the downloading is sequential by nature, not parallel. Fifty-five ordered
 * requests over a slow connection means a long white page.
 *
 * And assets_helper.php had documented this upgrade itself from the beginning:
 * "If we later need a single request, the upgrade is to merge the files into
 * public/css/dist/<bundle>.css and return one tag from here — with no change in the views
 * at all." This is that, carried out literally.
 *
 * ── What this script guards ──────────────────────────────────
 *
 * **The order carries meaning.** store.css says so plainly in its header: many rules collide
 * at the same specificity, and the last one wins. So the merge follows the @import order
 * literally, reorders nothing, and removes no duplicates.
 *
 * **The fingerprint comes from the content, not from the clock.** The file's name carries a
 * truncated sha256, so a change in content changes the URL and invalidates the cache by
 * itself — while unchanged content keeps the URL, so the visitor benefits from their cache.
 * A timestamp would have invalidated the cache on every deploy even if not one character
 * had changed.
 *
 * ── Two traps checked before this was written ────────────────
 *
 *   · **Nested imports**: none (verified) — every import lives in the two entry files
 *     alone. So no recursive resolution is needed, and were one to appear later the script
 *     fails plainly rather than dropping the file silently.
 *   · **Relative paths inside url()**: zero (verified). Had any existed they would break
 *     when the content moves into dist/, one level deeper — and the script refuses in that
 *     case.
 */

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { transform } from 'lightningcss';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const cssRoot = join(root, 'public', 'css');
const distDir = join(cssRoot, 'dist');

const BUNDLES = ['store', 'admin'];

/** Reads the @import order from an entry file — in order, unsorted and undeduplicated. */
function readImports(entryPath) {
  const source = readFileSync(entryPath, 'utf8');
  const pattern = /@import\s+url\(\s*["']([^"']+)["']\s*\)\s*;/g;

  const out = [];
  let match;
  while ((match = pattern.exec(source)) !== null) {
    out.push(match[1]);
  }

  return out;
}

/**
 * Returns the first relative path inside a url(), or null if there is none.
 *
 * It accepts: data:, http(s):, //, /, # and var() variables.
 * It rejects everything else — because that resolves relative to the file's location, and
 * the location changes.
 */
function findRelativeUrl(css) {
  const pattern = /url\(\s*(?:"([^"]*)"|'([^']*)'|([^)]*))\)/g;

  let match;
  while ((match = pattern.exec(css)) !== null) {
    const value = (match[1] ?? match[2] ?? match[3] ?? '').trim();
    if (value === '') continue;

    if (/^(data:|https?:|\/\/|\/|#|var\()/i.test(value)) continue;

    return value.length > 60 ? `${value.slice(0, 60)}…` : value;
  }

  return null;
}

function fail(message) {
  process.stderr.write(`\n  ✗ ${message}\n\n`);
  process.exit(1);
}

// ── The cleanup: old fingerprints are not left to pile up ─────
if (existsSync(distDir)) {
  for (const file of readdirSync(distDir)) {
    rmSync(join(distDir, file));
  }
} else {
  mkdirSync(distDir, { recursive: true });
}

const manifest = {};

for (const bundle of BUNDLES) {
  const entry = join(cssRoot, `${bundle}.css`);
  if (!existsSync(entry)) {
    fail(`Missing entry file: public/css/${bundle}.css`);
  }

  const imports = readImports(entry);
  if (imports.length === 0) {
    fail(`No @import in public/css/${bundle}.css — has its form changed?`);
  }

  const parts = [];

  for (const relative of imports) {
    const path = join(cssRoot, relative);
    if (!existsSync(path)) {
      fail(`Missing imported file: public/css/${relative}`);
    }

    const content = readFileSync(path, 'utf8');

    if (/@import/.test(content)) {
      fail(`A nested import in ${relative} — the script resolves one level only.`);
    }

    // A relative url() would break: the content moves into dist/, one level deeper.
    //
    // ⚠️ The value is extracted and then checked; it is not tested with a negative
    // lookahead inside the pattern. The first attempt was:
    //     /url\(\s*['"]?(?!data:|https?:|\/|#)/
    // and it is broken: `['"]?` is optional, so when the lookahead fails after the quote
    // the engine backtracks to a zero-width match of the quote and tests the lookahead at
    // the quote itself — and `"` is not data:, so the lookahead succeeds and a false alarm
    // fires. It actually fired on data:image/svg+xml in bootstrap-forms.css.
    const relativeUrl = findRelativeUrl(content);
    if (relativeUrl !== null) {
      fail(`A relative path inside url() in ${relative}: ${relativeUrl} — it will break after the merge.`);
    }

    parts.push(`/* ${relative} */\n${content}`);
  }

  const merged = parts.join('\n');

  const { code } = transform({
    filename: `${bundle}.css`,
    code: Buffer.from(merged),
    minify: true,
    // No targets: minification alone. Lowering modern syntax to older forms can change a
    // rule's meaning, and the project neither asked for that nor measured its effect.
  });

  const hash = createHash('sha256').update(code).digest('hex').slice(0, 12);
  const name = `${bundle}.${hash}.css`;

  writeFileSync(join(distDir, name), code);
  manifest[bundle] = `css/dist/${name}`;

  const before = Buffer.byteLength(merged);
  const after = code.length;
  process.stdout.write(
    `  ✓ ${bundle.padEnd(6)} ${String(imports.length).padStart(2)} files · ` +
      `${(before / 1024).toFixed(1)} KB → ${(after / 1024).toFixed(1)} KB ` +
      `(${Math.round((1 - after / before) * 100)}% smaller) → ${name}\n`
  );
}

// The manifest is what assets_helper.php reads to learn the fingerprinted file's name.
writeFileSync(join(distDir, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);

process.stdout.write('\n  ✓ public/css/dist/manifest.json\n\n');
