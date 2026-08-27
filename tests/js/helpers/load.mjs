/**
 * tests/js/helpers/load.mjs
 * يحمّل ملف JS من المشروع في النطاق العام — كما يفعل المتصفح.
 *
 * ملفات public/js ليست وحدات ES: تُحمَّل بوسوم <script> وتُصدِّر بالإسناد
 * إلى window. فلا يمكن `import` شيء منها، ومحاولة تحويلها لأجل الاختبار
 * كانت ستعني اختبار نسخة غير التي تعمل.
 *
 * التنفيذ بـFunction على globalThis يعيد إنتاج البيئة نفسها: `window`
 * موجودة (jsdom)، والتصريحات في المستوى الأعلى تصير عامّة.
 */

import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

/**
 * @param {string} relative مسار تحت public/ مثل 'js/core/utils.js'
 */
export function loadScript(relative) {
    const source = readFileSync(join(root, 'public', relative), 'utf8');

    // indirect eval: ينفّذ في النطاق العام لا في نطاق هذه الدالة، فتصير
    // `function foo()` في المستوى الأعلى متاحةً كما في المتصفح.
    // eslint-disable-next-line no-eval
    (0, eval)(source);
}
