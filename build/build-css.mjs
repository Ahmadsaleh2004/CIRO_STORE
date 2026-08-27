/**
 * build/build-css.mjs
 * يدمج سلاسل @import في ملف واحد مضغوط لكل حزمة، ببصمة محتوى.
 *
 * ── المشكلة ──────────────────────────────────────────────────
 *
 * store.css و admin.css ملفا @import فقط: 36 و19 استيراداً. والمتصفح
 * لا يعرف بوجود أيٍّ منها حتى ينزّل الملف الأب ويحلّله — فالتنزيل
 * متسلسل بطبعه، لا متوازٍ. خمسة وخمسون طلباً مرتّباً على اتصال بطيء
 * تعني صفحة بيضاء طويلة.
 *
 * وassets_helper.php كان يوثّق هذه الترقية بنفسه منذ البداية:
 * «إن احتجنا لاحقاً طلباً واحداً فقط، الترقية هي دمج الملفات في
 * public/css/dist/<bundle>.css وإرجاع وسم واحد من هنا — بلا أي تغيير
 * في الـViews». هذا هو تنفيذها حرفياً.
 *
 * ── ما يحرسه هذا السكربت ─────────────────────────────────────
 *
 * **الترتيب حمّال معنى.** store.css يقول ذلك صراحةً في رأسه: كثير من
 * القواعد تتصادم عند نفس الـspecificity، والأخير يفوز. فالدمج يتبع
 * ترتيب @import حرفياً، ولا يعيد ترتيب شيء، ولا يحذف تكراراً.
 *
 * **البصمة من المحتوى لا من الوقت.** اسم الملف يحمل sha256 مقتطعاً،
 * فتغيّر المحتوى يغيّر الرابط ويُبطل التخزين المؤقّت من تلقائه — وثبات
 * المحتوى يُبقي الرابط فيستفيد الزائر من ذاكرته. الطابع الزمني كان
 * سيُبطل التخزين عند كل نشر ولو لم يتغيّر حرف.
 *
 * ── فخّان فُحصا قبل الكتابة ──────────────────────────────────
 *
 *   · **استيراد متداخل**: لا يوجد (مفحوص) — كل الاستيراد في ملفَي
 *     الدخول وحدهما. فلا حاجة لحلّ تعاودي، ولو ظهر لاحقاً يفشل
 *     السكربت صراحةً بدل أن يُسقط الملف بصمت.
 *   · **مسارات نسبية داخل url()**: صفر (مفحوص). لو وُجدت لانكسرت عند
 *     نقل المحتوى إلى dist/ الأعمق بمستوى — والسكربت يرفض حينها.
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

/** يقرأ ترتيب @import من ملف الدخول — بالترتيب، بلا فرز ولا إزالة تكرار. */
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
 * يُرجع أول مسار نسبي داخل url()، أو null إن لم يوجد.
 *
 * يقبل: data: و http(s): و // و / و # و متغيّرات var().
 * يرفض: كل ما عداها — لأنه يُحلّ نسبةً إلى موضع الملف، وموضعه يتغيّر.
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

// ── التنظيف: بصمات قديمة لا تُترك تتراكم ──────────────────────
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
    fail(`ملف الدخول غائب: public/css/${bundle}.css`);
  }

  const imports = readImports(entry);
  if (imports.length === 0) {
    fail(`لا @import في public/css/${bundle}.css — هل تغيّرت صيغتها؟`);
  }

  const parts = [];

  for (const relative of imports) {
    const path = join(cssRoot, relative);
    if (!existsSync(path)) {
      fail(`ملف مستورَد غائب: public/css/${relative}`);
    }

    const content = readFileSync(path, 'utf8');

    if (/@import/.test(content)) {
      fail(`استيراد متداخل في ${relative} — السكربت يفكّ مستوى واحداً فقط.`);
    }

    // url() نسبي كان سينكسر: المحتوى ينتقل إلى dist/ الأعمق بمستوى.
    //
    // ⚠️ القيمة تُستخرج ثم تُفحص، ولا تُختبَر بنافية داخل النمط.
    // المحاولة الأولى كانت:
    //     /url\(\s*['"]?(?!data:|https?:|\/|#)/
    // وهي معطوبة: `['"]?` اختيارية، فحين تفشل النافية بعد علامة
    // الاقتباس يتراجع المحرّك إلى مطابقة صفرية للعلامة ويفحص النافية
    // عند العلامة نفسها — و`"` ليست data: فتنجح النافية ويقع إنذار
    // كاذب. وقع فعلاً على data:image/svg+xml في bootstrap-forms.css.
    const relativeUrl = findRelativeUrl(content);
    if (relativeUrl !== null) {
      fail(`مسار نسبي داخل url() في ${relative}: ${relativeUrl} — سينكسر بعد الدمج.`);
    }

    parts.push(`/* ${relative} */\n${content}`);
  }

  const merged = parts.join('\n');

  const { code } = transform({
    filename: `${bundle}.css`,
    code: Buffer.from(merged),
    minify: true,
    // لا targets: الضغط وحده. تحويل الصيغ الحديثة إلى قديمة قد يغيّر
    // دلالة قاعدة، والمشروع لم يطلب ذلك ولم يُقس أثره.
  });

  const hash = createHash('sha256').update(code).digest('hex').slice(0, 12);
  const name = `${bundle}.${hash}.css`;

  writeFileSync(join(distDir, name), code);
  manifest[bundle] = `css/dist/${name}`;

  const before = Buffer.byteLength(merged);
  const after = code.length;
  process.stdout.write(
    `  ✓ ${bundle.padEnd(6)} ${String(imports.length).padStart(2)} ملفاً · ` +
      `${(before / 1024).toFixed(1)} KB → ${(after / 1024).toFixed(1)} KB ` +
      `(${Math.round((1 - after / before) * 100)}% أقل) → ${name}\n`
  );
}

// البيان هو ما تقرأه assets_helper.php لتعرف اسم الملف المبصوم.
writeFileSync(join(distDir, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);

process.stdout.write('\n  ✓ public/css/dist/manifest.json\n\n');
