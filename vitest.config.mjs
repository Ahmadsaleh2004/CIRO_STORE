// ══════════════════════════════════════════════════════════════
// Vitest — the front-end tests
// ══════════════════════════════════════════════════════════════
//
// The project has no ES modules: 34 files are loaded by <script> tags and share one global
// scope. So a test cannot `import` anything from them.
//
// The answer: the files are loaded into a jsdom environment with eval on the global scope,
// exactly as the browser does. The test then examines **what actually runs**, not an
// altered copy of it made to be testable.
//
// And this is a deliberate decision: converting the files to ES modules would have changed
// 76 global names and 34 files at once, for a gain the user never sees — and the existing
// arrangement works and reads.

import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.mjs'],
        globals: false,
        restoreMocks: true,
    },
});
