# تقرير تنفيذ إعادة تنظيم CSS + إصلاح الوضع الليلي

> **الحالة: مكتمل ومُتحقَّق منه.**
> التاريخ: 2026-08-25 · الخطة الأصلية: [CSS-REFACTOR-PLAN.md](CSS-REFACTOR-PLAN.md)

---

## 1. النتيجة بالأرقام

| | قبل | بعد |
|---|---:|---:|
| ملفات CSS | 14 | **66** |
| مجلدات CSS | 3 | **11** |
| أكبر ملف | **2551** سطر (`style.css`) | **260** سطر (`store/pages/home.css`) |
| ملفات > 300 سطر | 2 | **0** |
| مجموع الأسطر | 4823 | 4995 (+172 = تعليقات توضيحية + إصلاحات جديدة) |
| سيلكتورات مفقودة | — | **0** (فحص آلي: 272 → 294، لا شيء ضاع، 22 جديد) |

---

## 2. الشجرة النهائية

```
public/css/
├── store.css              ← ملف دخول المتجر (@import فقط + شرح الترتيب)
├── admin.css              ← ملف دخول لوحة الأدمن (@import فقط)
│
├── base/          (6)   tokens · breakpoints · reset · typography · utilities · accessibility
├── vendor/        (7)   bootstrap-theme · -forms · -tables · -feedback · -navigation
│                        · -buttons · -surfaces
├── layout/        (3)   navbar · footer · store-mode-bar
├── components/   (16)   modals · forms · floating-labels · buttons · hero-slider
│                        · product-card · favorite-button · discount-badge · quantity-box
│                        · price-display · cart-badge · cart-sidebar · breadcrumb
│                        · skeleton · back-to-top · notifications
├── animations/    (4)   keyframes · page-transitions · micro-interactions · reduced-motion
├── store/pages/   (7)   home · home-slider · products · product-details · checkout
│                        · my-info · wishlist
└── admin/        (21)
    ├── layout/     (4)  head · shell · navbar · footer
    ├── components/ (8)  stat-cards · data-table · page-header · permissions-grid
    │                    · search-form · export-button · float-group · pickers
    └── pages/      (9)  home · dashboard · products · orders · users · support
                         · settings · branding · login
```

**كل الملفات المحذوفة الـ 14 محفوظة في `css-backup-2026-08-24/` في جذر المشروع**
(نُقلت خارج `public/` حتى لا تكون قابلة للوصول من المتصفح). احذفها متى شئت.

---

## 3. إصلاحات الوضع الليلي — الـ 12 عطلاً كلها مُصلَحة ومقيسة

### 3.1 السبب الجذري

`public/js/core/theme.js` كان يضبط `body.dark-mode` فقط. Bootstrap 5.3 يقرأ وضعه
المظلم من **`data-bs-theme="dark"` على `<html>`** — وهي سمة لم تكن موجودة إطلاقاً.
الآن تُضبط في مكانين:

1. **قبل أول رسم** عبر `themeBootScript()` في `app/helpers/assets_helper.php` (سكربت
   inline في `<head>`) — هذا يصلح أيضاً **الومضة البيضاء** التي كانت تظهر عند كل تنقّل
   في الوضع الليلي.
2. **عند التبديل** عبر `setTheme()` الجديدة في `theme.js`.

وأُضيف لـ `base/tokens.css` سيلكتور مرافق `html[data-bs-theme="dark"] body` بجانب
`body.dark-mode`، فصارت متغيرات الثيم صحيحة من أول إطار.

### 3.2 جدول التباين (WCAG AA = 4.5:1) — قياس فعلي في المتصفح

| العنصر | فاتح | ليلي | |
|---|---:|---:|---|
| نص الصفحة | 15.21 | 16.02 | ✅ |
| **نصوص لوحة Sort & Filter** | 17.06 | 14.64 | ✅ *(كانت مختفية)* |
| **عنوان قسم داخل الفلاتر** | 17.06 | 14.64 | ✅ |
| **`(any match)` — `.text-muted`** | 17.06 | 14.64 | ✅ |
| **عدّاد المنتجات `badge bg-light`** | 13.78 | 10.33 | ✅ *(كان بقعة بيضاء)* |
| **`.text-muted` عام (49 استخدام)** | 5.33 | 7.50 | ✅ *(كان 1.4:1)* |
| **`.btn-outline-dark`** | 15.21 | 16.02 | ✅ *(كان مختفياً تماماً)* |
| **`.btn-outline-primary`** | 5.61 | 6.34 | ✅ *(كان 3.98 / 3.1)* |
| **Next box — `.page-link`** | 17.06 | 14.64 | ✅ *(كان صندوقاً أبيض)* |
| **Prev المعطّل** | 5.33 | 7.50 | ✅ |
| **الصفحة النشطة في الترقيم** | 6.29 | 7.90 | ✅ |
| خلية جدول الأدمن | 17.06 | 14.64 | ✅ |
| عنوان صفحة الأدمن | 15.21 | 16.02 | ✅ |

**كل بند يتجاوز 4.5:1 في الوضعين. أدنى قيمة: 5.33.**

### 3.3 تفصيل الإصلاحات

| # | العطل | الملف الجديد | الإصلاح |
|---|---|---|---|
| 1 | `.text-muted` غير مقروء (49 استخدام / 19 صفحة) | `vendor/bootstrap-theme.css` | توكن جديد `--muted-text` (فاتح `#5b6472` / ليلي `#9aa4b2`) مضمون التباين |
| 2 | `.text-dark` داخل الشارات | `vendor/bootstrap-feedback.css` | `.badge.bg-light` صارت theme-aware مع نصها، فلم يعد للـ `text-dark` ما يتنازع عليه |
| 3 | **لوحة Sort & Filter** بيضاء ونصوصها فاتحة | `vendor/bootstrap-navigation.css` | `.dropdown-menu` تأخذ `var(--card-bg)`/`var(--text-color)`/`var(--section-border)` — **الألوان فقط**، بلا أي تغيير في شكلها أو ظلها |
| 4 | **Next box / pagination** بلا أي قواعد | `vendor/bootstrap-navigation.css` | ملف كامل: `.page-link` + `:hover` + `.active` + `.disabled` + متغيرات `--bs-pagination-*` |
| 5 | `.btn-outline-dark` مختفٍ | `vendor/bootstrap-buttons.css` | لون وحدود theme-aware — يحتفظ بشكل الـ outline، فلا يتغير مظهره في الوضع الفاتح تقريباً |
| 6 | `.btn-outline-primary` تحت WCAG | `vendor/bootstrap-buttons.css` | `var(--accent-text)` بدل أزرق Bootstrap الثابت |
| 7 | **سهم قائمة Sort يختفي** | `vendor/bootstrap-forms.css` | SVG مضمَّن بنسختين (`#343a40` / `#e6edf3`) + دعم RTL |
| 8 | `.bg-light` بقعة بيضاء | `vendor/bootstrap-feedback.css` | ↑ نفس البند 2 |
| 9 | `.dropdown-item` بلا تنسيق | `vendor/bootstrap-navigation.css` | لون + hover + active + divider + header |
| 10 | `background:#fff` مضمّن في `admin/my-info.php` | `admin/pages/settings.css` | **ليس عطلاً** — تبيّن أنه خلفية QR code ويجب أن تبقى بيضاء لتُقرأ. استُبدل بكلاس `.twofa-qr` يبقي الخلفية بيضاء ويجعل الإطار فقط theme-aware |
| 11 | `.btn-outline-secondary:hover` يقفز رمادياً | `vendor/bootstrap-buttons.css` | ضُمَّ لعائلة الأزرار الخضراء |
| 12 | `<hr style="...">` مضمّن في الفلاتر | `vendor/bootstrap-theme.css` | كلاس `.filter-divider` بنفس هوامش Bootstrap تماماً |

### 3.4 إصلاح إضافي غير مذكور في الخطة

`--placeholder-color` كان يؤدي وظيفتين: نص الـ placeholder **و** النص الثانوي. قيمته في
الوضع الليلي (`#484f58`) تعطي تباين **2.24:1** — أي أن كل النصوص الثانوية في الموقع كانت
شبه غير مقروءة ليلاً. هذا بالضبط وصف "كلمات وكلام لا يظهر".

نُقل **21 استخداماً** من `--placeholder-color` إلى `--muted-text` (البريدكرمب، عدّاد
النتائج، السعر القديم، مواصفات المنتج، وصف المنتج، وقت الإشعار، فاصل المودال، خطوات
الدفع، تسميات إحصائيات الأدمن، تواريخ الـ strikes، صفوف المنتجات المخفية…) بالإضافة إلى
**8 أنماط مضمّنة** في ملفات الـ views.

بقيت ثلاثة استخدامات فقط لـ `--placeholder-color` — وهي الـ placeholder الحقيقي وتسميات
الـ float-group في وضع الراحة، وهذا صحيح.

كما أُضيف توكنان بضمان تباين لأن `--accent` وحده غير كافٍ:
`--accent-text` (نص بلون الأكسنت) و `--accent-strong` (تعبئة صلبة تحتها نص أبيض).

---

## 4. التحقق — كيف تأكدت أن شيئاً لم ينكسر

بُنيت أداة **A/B في المتصفح**: نفس الـ DOM، تُحمَّل مرة بـ CSS القديم من النسخة
الاحتياطية ومرة بالجديد، ثم تُقارَن **الأنماط المحسوبة** (`getComputedStyle`) لعشرات
العناصر على 20+ خاصية.

| الصفحة | 375px | 768px | 1280px | فروق التخطيط | فروق الألوان |
|---|:-:|:-:|:-:|---|---|
| الرئيسية | ✅ | ✅ | ✅ | **0** | 1 (`btn-outline-dark`) |
| المنتجات | ✅ | ✅ | ✅ | **1** (حد الـ range slider) | 4 |
| تفاصيل المنتج | ✅ | ✅ | ✅ | **0** | 5 |
| قائمة الأمنيات | ✅ | ✅ | ✅ | **0** | 1 |
| اتصل بنا | ✅ | ✅ | ✅ | **0** | 1 |
| المودالات (login/register/forgot) | ✅ | — | ✅ | **0** | 3 |
| لوحة الأدمن (نموذج كامل) | ✅ | ✅ | ✅ | **0** | 11 |

**فرق التخطيط الوحيد في المشروع كله:** `#priceRange` فقد حدّه `1px`. القاعدة القديمة
`input { border: 1px solid ... }` كانت تصيب `<input type="range">` أيضاً وتضع إطار حقل
حول الشريط. هذا تحسين مقصود.

كل فروق الألوان مقصودة ومذكورة في الجدول أعلاه.

### فحوص آلية إضافية
- **توازن الأقواس** في الـ 66 ملفاً: سليم.
- **توازن التعليقات**: كشف الفحص **3 ملفات فيها `*/` يتيمة** نتجت عن قطع مقطع في منتصف
  تعليق. كانت تُسقط قواعد كاملة (منها نظام `.btn-disabled-faded`) — صُلِّحت وأُعيد
  الفحص. هذا العطل ظهر أولاً في اختبار A/B (`#addCartBtn` opacity 0.45 → 0.65) قبل أن
  يجده فحص التعليقات.
- **كل الـ 36 `@import`** في `store.css` تحمَّل بنجاح، ولا ملف فارغ.
- **صفر أخطاء 404** في CSS على كل الصفحات.
- `php -l` على كل ملف PHP معدَّل: سليم.

---

## 5. إزالة التكرار — ما حُذف فعلياً

| المكرَّر | كان في | صار |
|---|---|---|
| `.store-breadcrumb` (نسخة حرفية) | `style.css` + `pages/products.css` | `components/breadcrumb.css` |
| `#results-count` | نفس المكانين | `store/pages/products.css` |
| `.section-view-all` | نفس المكانين | `store/pages/home.css` (الرئيسية وحدها تستعمله) |
| الكاروسيل الأفقي كاملاً (150 سطر) | `style.css` + `pages/home.css` | `store/pages/home.css` |
| `.home-product-card` وكل متغيراته | نفس المكانين | `store/pages/home.css` |
| `.quantity-box` (3 نسخ متضاربة) | `style.css` + `product-details.css` + `products.css` | أساس في `components/quantity-box.css` + فروقات فقط في صفحتي التفاصيل والمنتجات |
| `.product-description` / `.new-price` / `.old-price` / `.product-image` | مكانين | `components/` |
| **نظام الإشعارات كاملاً (110 أسطر)** | `style.css` + `admin-footer.css` | `components/notifications.css` |
| `.custom-navbar` + `.counter-badge` + أنيميشن `.dropdown-menu` | `style.css` + `admin-navbar.css` | طبقة المتجر المشتركة |
| `.skip-nav` | `style.css` + `admin-head.css` | `base/accessibility.css` |
| `#mainNavbar .dropdown .btn` responsive | 3 أماكن | `admin/layout/navbar.css` |

كما **حُذف `admin/admin.css` من صفحتَي `login.php` و `store-reauth.php`** — كانتا تحمّلانه
بلا `style.css`، أي أن كل متغيراته كانت غير معرَّفة، وصفحة الـ login تحمل ألوانها بنفسها
أصلاً (`--admin-*`). كان وسم `<link>` ميتاً.

---

## 6. الحفاظ على الـ Cascade

`style.css` القديم فيه **383 `!important`** وكثير منها يعتمد على ترتيب السطور. عولج هذا بـ:

1. **ترتيب `@import` يطابق ترتيب المقاطع الأصلي**، وكل موضع حساس موثَّق بتعليق `WHY`.
2. **قاعدة ثابتة عند توزيع كتل `@media`:** الترتيب النسبي داخل الملف الواحد يبقى كما كان.
   مثلاً `components/cart-sidebar.css` تحمل الترتيب `≤767 → ≤575 → ≤768 → ≤576 → ≤320`
   وهو ترتيب غريب المظهر لكنه هو الترتيب الأصلي وهو ما يحدد النتيجة فعلاً.
3. ثلاثة مواضع احتاجت ترتيباً صريحاً موثَّقاً في التعليقات:
   - `vendor/bootstrap-surfaces.css` بعد `components/product-card.css` — الكارد يحمل
     `class="card product-card"` فالسيلكتوران يتعادلان في الـ specificity والأخير يفوز
     بلون الحد.
   - `components/modals.css` **قبل** `components/floating-labels.css` — وإلا فازت
     `.modal-body label` على `.float-group label` وظهرت تسميات مودال الدخول بلون النص
     الكامل بدل لون الـ placeholder. (اكتشفه اختبار A/B وصُلِّح.)
   - `animations/reduced-motion.css` آخر ملف دائماً.
4. `base/breakpoints.css` يجمع وحده كل الكتل التي تعيد تعريف `--card-height` /
   `--img-height`، مع تحذير صريح بعدم إعادة ترتيبها.

---

## 7. ملفات PHP / JS المعدَّلة

| الملف | التعديل |
|---|---|
| `app/helpers/assets_helper.php` | **جديد** — `cssBundle()` · `pageCss()` · `themeBootScript()` |
| `app/views/inc/head.php` | حزمة `store` + سكربت الثيم |
| `app/views/admin/inc/head.php` | 5 وسوم → حزمة `admin` + سكربت الثيم |
| `app/views/admin/login.php` | وسم واحد بدل وسمين (حُذف `admin.css` الميت) |
| `app/views/admin/store-reauth.php` | نفس الشيء |
| `app/views/auth/reset-password.php` | `store.css` + سكربت الثيم |
| `app/views/admin/product/index.php` | `<hr style>` → `.filter-divider` |
| `app/views/admin/my-info.php` | نمط مضمّن → `.twofa-qr` |
| `public/js/core/theme.js` | `setTheme()` تضبط `body.dark-mode` **و** `data-bs-theme` |
| 7 كنترولرز | تحديث مسارات `extraHead` إلى `store/pages/` و `admin/pages/` |
| 8 ملفات views | `color:var(--placeholder-color)` → `--muted-text` في الأنماط المضمّنة |

لم يُمس أي ملف في `app/models/` أو `app/core/` أو `database/` أو `vendor/`، ولا أي ملف
JS غير `theme.js`.

---

## 8. ما لم أستطع التحقق منه بصرياً

صفحتان تتطلبان تسجيل دخول ولم أدخل بيانات اعتماد:

- **صفحة الدفع (checkout)** — `store/pages/checkout.css` نقلٌ حرفي + مقياس
  `.step-circle`/`.step-label` المنقول من `style.css` بترتيبه الأصلي.
- **صفحة "معلوماتي"** — `store/pages/my-info.css` نقلٌ حرفي + كتلة `.info-tab-btn`.
- **صفحات الأدمن وهي مسجَّلة الدخول** — تحققتُ منها عبر صفحة اختبار تحمل نفس ماركب لوحة
  التحكم الحقيقي (شريط التنقل، الإحصائيات، الجداول، لوحة Sort & Filter، الترقيم،
  البلاطات، الـ strikes، الفوتر) وأعطت **صفر فروق تخطيط** على المقاسات الثلاثة.

الخطر منخفض لأن السيلكتورات في هذه الملفات محصورة بصفحاتها، لكن يستحسن أن تفتحها بنفسك
مرة واحدة في الوضعين.

---

## 9. التراجع

كل شيء موجود في `css-backup-2026-08-24/`. للعودة للحالة السابقة:

```bash
cp -r css-backup-2026-08-24/* public/css/ && rm -rf public/css/base public/css/vendor public/css/layout public/css/components public/css/animations public/css/store public/css/admin/layout public/css/admin/components public/css/admin/pages public/css/store.css public/css/admin.css
```

ثم أرجِع وسوم `<link>` في `app/views/inc/head.php` و `app/views/admin/inc/head.php`.

---

## 10. ملاحظة أخيرة

`.text-muted` تفوز فقط حيث لا توجد قاعدة حاوية أقوى. داخل `.card p` أو
`.modal-body small` تفوز قاعدة الحاوية (specificity 0,1,1) ويظهر النص بلون النص الكامل —
وهذا هو سلوكه **قبل** التعديل أيضاً، ومقروء في الحالتين، فتُركت كما هي عمداً بدل رفع
الـ specificity وإحداث تغييرات لم تطلبها.
