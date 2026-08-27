/**
 * build/build-js.mjs
 * يدمج ملفات JS في حزم مضغوطة مبصومة — كما يفعل build-css.mjs بالأنماط.
 *
 * ── المشكلة، مقيسة ─────────────────────────────────────────
 *
 * الصفحة الرئيسية كانت تطلب **ثمانية عشر ملف JS**. والمتصفح يسمح بستّ
 * اتصالات متزامنة لكل نطاق على HTTP/1.1، فتقف الملفات في طابور:
 *
 *     أول ملف يبدأ:      467 ms
 *     آخر ملف ينتهي:     999 ms
 *     DOMContentLoaded: 1051 ms
 *
 * والسلايدر لا وجود له في HTML إطلاقاً: يبنيه products-catalog.js من
 * window.dbHomeSliders. وهو الرابع عشر في الطابور — فيبقى مكانه فارغاً
 * أكثر من ثانية بعد ظهور الصفحة. وهذا بالضبط ما يُحسّ بطئاً.
 *
 * ── الترتيب هو العقد ───────────────────────────────────────
 *
 * ملفات المشروع ليست وحدات ES: تتشارك نطاقاً عاماً، ويعتمد اللاحق على
 * ما عرّفه السابق. فالدمج يتبع ترتيب الـfooter **حرفاً بحرف** — وهو
 * نفس ترتيب تنفيذ وسوم <script>.
 *
 * ولهذا لا bundling ذكي ولا شجرة اعتماد: مجرّد ضمّ بالترتيب. أي إعادة
 * ترتيب تكسر النطاق العام بصمت.
 *
 * ── حزم مستقلّة لا حزمة واحدة ──────────────────────────────
 *
 * لأن صفحات الأدمن تُحمّل ملفات لا تحتاجها صفحات المتجر والعكس. حزمة
 * واحدة كانت ستُجبر زائر المتجر على تنزيل لوحة التحكّم كاملة.
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
 * الحزم — كل قائمة تعكس ترتيب الـfooter المقابل حرفاً بحرف.
 *
 * ⚠️ page-data.js **ليس في أي حزمة**. يبقى وسماً منفصلاً بلا defer
 * لأنه ينسخ جزيرة بيانات الصفحة إلى window، وكل ما تحته يقرأ منها.
 * ضمّه إلى الحزمة يبقيه أوّلاً داخلها، لكن الحزمة نفسها defer — فتصير
 * البيانات متاحة متأخّرةً عمّا هي عليه اليوم.
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

    // يُحمَّل فوق حزمة المتجر للمستخدم المسجّل وحده.
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
            fail(`ملف غائب في حزمة ${name}: js/${relative}`);
        }

        const code = readFileSync(path, 'utf8');

        // ⚠️ 'use strict' في ملف يصير عامّاً على الحزمة كلها بعد الضمّ.
        // ملفات المشروع تعتمد على النطاق العام غير الصارم (إسناد إلى
        // window، ودوال في المستوى الأعلى)، فتفعيل الوضع الصارم على
        // ملف لا يقصده يكسره بصمت. كل ملف يُغلَّف في دالة تنفَّذ فوراً
        // كي يبقى وضعه الصارم محبوساً فيه.
        //
        // والتغليف لا يخفي شيئاً: التصريحات في المستوى الأعلى في هذه
        // الملفات إمّا مُسندة إلى window صراحةً، أو مقصود بها الخصوصية.
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
        // es2017: يطابق ما تكتبه الملفات فعلاً (async/await، لا مزيد).
        // هدف أحدث لا يفيد، وأقدم يعيد كتابة async إلى مولّدات ضخمة.
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
        `  ✓ ${name.padEnd(11)} ${String(files.length).padStart(2)} ملفاً · ` +
            `${(before / 1024).toFixed(1)} KB → ${(after / 1024).toFixed(1)} KB ` +
            `(${Math.round((1 - after / before) * 100)}% أقل)\n`
    );
}

writeFileSync(join(distDir, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);

process.stdout.write(
    `\n  المجموع: ${(totalBefore / 1024).toFixed(1)} KB → ${(totalAfter / 1024).toFixed(1)} KB\n` +
        '  ✓ public/js/dist/manifest.json\n\n'
);
