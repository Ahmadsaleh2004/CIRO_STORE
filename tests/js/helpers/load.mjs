/**
 * tests/js/helpers/load.mjs
 * Loads one of the project's JS files into the global scope — as the browser does.
 *
 * The public/js files are not ES modules: they are loaded by <script> tags and export by
 * assigning to window. So nothing can be `import`ed from them, and converting them for the
 * sake of testing would have meant testing a copy other than the one that runs.
 *
 * Executing through Function on globalThis reproduces the same environment: `window` exists
 * (jsdom), and the top-level declarations become global.
 */

import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

/**
 * @param {string} relative a path under public/, such as 'js/core/utils.js'
 */
export function loadScript(relative) {
    const source = readFileSync(join(root, 'public', relative), 'utf8');

    // An indirect eval: it runs in the global scope rather than in this function's, so a
    // top-level `function foo()` becomes available exactly as it is in the browser.
    // eslint-disable-next-line no-eval
    (0, eval)(source);
}
