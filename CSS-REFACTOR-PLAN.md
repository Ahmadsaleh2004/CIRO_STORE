# خطة إعادة تنظيم ملفات CSS + إصلاح الوضع الليلي (Dark Mode)

> **الحالة:** خطة فقط — لم يُعدَّل أي ملف بعد. بانتظار موافقتك.
> **التاريخ:** 2026-08-24
> **المشروع:** `C:\xampp\htdocs\STORE`

---

## 0. الأذونات (Permissions) المطلوبة — اقرأ هذا أولاً

طلبتَ كل الأذونات مقدماً حتى تتركني أشتغل وتذهب. هذه هي **كل** الأذونات التي سأحتاجها من أول
سطر إلى آخر سطر. أضِفها إلى `.claude/settings.local.json` تحت `permissions.allow`، أو اختر
"Always allow" عند أول طلب لكل واحد منها.

### 0.1 أدوات تعديل الملفات
```
Write
Edit
Read
```

### 0.2 أوامر Bash (أعمل بها معظم الشغل في هذا الوضع)
```
Bash(mkdir:*)
Bash(cat:*)
Bash(sed:*)
Bash(cp:*)
Bash(mv:*)
Bash(rm:*)
Bash(ls:*)
Bash(find:*)
Bash(grep:*)
Bash(head:*)
Bash(tail:*)
Bash(wc:*)
Bash(awk:*)
Bash(sort:*)
Bash(uniq:*)
Bash(diff:*)
Bash(tee:*)
Bash(php -l:*)
```
- `cp` / `mv` — لنقل الملفات إلى المجلدات الجديدة وأخذ نسخ احتياطية.
- `rm` — لحذف الملفات القديمة **بعد** التحقق فقط (المرحلة الأخيرة).
- `sed` / `awk` — لاستخراج المقاطع من `style.css` الضخم ونقلها للملفات الجديدة.
- `php -l` — فحص صحة صيغة ملفات PHP التي سأعدّلها (`head.php` والهيلبر الجديد).

### 0.3 أدوات المتصفح (للتحقق البصري من الوضع الليلي)
```
mcp__Claude_Browser__preview_start
mcp__Claude_Browser__navigate
mcp__Claude_Browser__computer
mcp__Claude_Browser__read_page
mcp__Claude_Browser__javascript_tool
mcp__Claude_Browser__resize_window
mcp__Claude_Browser__read_console_messages
mcp__Claude_Browser__tabs_context
mcp__Claude_Browser__tabs_create
```
سأفتح `http://localhost/STORE/public` وأبدّل الثيم بـ `localStorage` وألتقط لقطات قبل/بعد.
**شرط:** لازم Apache و MySQL شغّالين في XAMPP قبل ما تروح.

> **ملاحظة:** لن أستخدم `git` (المجلد ليس مستودع git)، ولن أستخدم أي `npm` / `node` — المشروع
> بلا أدوات بناء ولن أضيف أي منها.

### 0.4 نسخة `settings.local.json` جاهزة للصق
```json
{
  "permissions": {
    "allow": [
      "Read", "Write", "Edit",
      "Bash(mkdir:*)", "Bash(cat:*)", "Bash(sed:*)", "Bash(cp:*)", "Bash(mv:*)",
      "Bash(rm:*)", "Bash(ls:*)", "Bash(find:*)", "Bash(grep:*)", "Bash(head:*)",
      "Bash(tail:*)", "Bash(wc:*)", "Bash(awk:*)", "Bash(sort:*)", "Bash(uniq:*)",
      "Bash(diff:*)", "Bash(tee:*)", "Bash(php -l:*)",
      "mcp__Claude_Browser__preview_start",
      "mcp__Claude_Browser__navigate",
      "mcp__Claude_Browser__computer",
      "mcp__Claude_Browser__read_page",
      "mcp__Claude_Browser__javascript_tool",
      "mcp__Claude_Browser__resize_window",
      "mcp__Claude_Browser__read_console_messages",
      "mcp__Claude_Browser__tabs_context",
      "mcp__Claude_Browser__tabs_create"
    ]
  }
}
```

---

## 1. الوضع الحالي — ما وجدته فعلياً

### 1.1 أحجام الملفات
| الملف | الأسطر | `!important` |
|---|---:|---:|
| `public/css/style.css` | **2551** | **383** |
| `public/css/admin/admin.css` | **741** | 41 |
| `public/css/pages/home.css` | 237 | 18 |
| `public/css/pages/products.css` | 234 | 38 |
| `public/css/pages/home-slider.css` | 222 | 26 |
| `public/css/admin/admin-login.css` | 219 | 0 |
| `public/css/pages/product-details.css` | 176 | 37 |
| `public/css/admin/admin-layout/admin-footer.css` | 134 | 0 |
| `public/css/pages/checkout.css` | 103 | 8 |
| `public/css/admin/admin-layout/admin-navbar.css` | 86 | 15 |
| `public/css/pages/my-info.css` | 50 | 7 |
| `public/css/admin/branding.css` | 29 | 2 |
| `public/css/admin/admin-layout/admin-head.css` | 23 | 2 |
| `public/css/pages/wishlist.css` | 18 | 2 |
| **المجموع** | **4823** | **579** |

### 1.2 المشاكل البنيوية المؤكَّدة

**(أ) تكرار كامل بين `style.css` وملفات الصفحات** — نفس السيلكتورات معرَّفة مرتين:

| السيلكتور | في `style.css` | مكرَّر أيضاً في |
|---|---|---|
| `.store-breadcrumb` + `.sep` + `.current` | 1749–1770 | `pages/products.css` (نسخة **حرفية**) |
| `#results-count` | 1775–1780 | `pages/products.css` |
| `.section-view-all` | 1786–1798 | `pages/products.css` |
| `.section-carousel-*` | 1888–1953 | `pages/home.css` |
| `.home-product-card` | 1955–2034 | `pages/home.css` |
| `.quantity-box` / `.quantity-input` | 987–1062 | `pages/product-details.css` **و** `pages/products.css` |
| `.product-description` | 1199–1206 | `pages/product-details.css` |
| صور المنتجات "image-only" | 488–543 | `pages/home.css` |
| `.dropdown-menu` (أنيميشن) | 2059–2072 | `admin/admin-layout/admin-navbar.css` (نسخة حرفية) |
| `#mainNavbar .dropdown .btn` responsive | 2422 | `admin/admin.css` 396 و 410 |

**(ب) نظامان مستقلان للـ Responsive داخل نفس الملف** — `style.css` فيه 26 كتلة `@media`
موزّعة على مكانين منفصلين: كتلة "Mobile UX" (1417–1623) وكتلة "Comprehensive Responsive Fixes
— **Appended**" (2285–2536). نفس البريك بوينت (`max-width: 767px`) يظهر **4 مرات** في الملف
(الأسطر 219، 582، 972، 1471، 2342) و`576px` يظهر 3 مرات. نفس الشيء في `admin.css`
(كتلة responsive أصلية + كتلة "Appended").

**(ج) لا يوجد `url()` في أي ملف CSS** — إذن نقل الملفات بين المجلدات **آمن تماماً**، لا توجد
مسارات صور نسبية تنكسر.

**(د) ملفات الصفحات تُحمَّل من الـ Controllers عبر `extraHead`** وليس من `head.php`:
`HomeController:82`, `ProductController:69,169`, `CheckoutController:33`, `MyInfoController:42`,
`AdminMyInfoController:40`, `WishlistController:21`, `AdminBrandingController:36`.

---

## 2. مشاكل الوضع الليلي — السبب الجذري

### 2.1 السبب الجذري الواحد

المشروع يستعمل **Bootstrap 5.3.3**، والثيم يُبدَّل بإضافة `body.dark-mode` فقط
(`public/js/core/theme.js`). لكن Bootstrap 5.3 يقرأ الوضع الليلي من سمة
**`data-bs-theme="dark"` على `<html>`** — وهذه السمة **غير موجودة إطلاقاً في المشروع**.

النتيجة: كل مكوّن Bootstrap يبقى بألوان الوضع الفاتح (خلفية بيضاء، نص `#212529`)، بينما
CSS المشروع يقلب `--text-color` إلى `#e6edf3`. فينتج **نص فاتح فوق خلفية بيضاء = مختفي**،
أو **نص أسود فوق خلفية `#0d1117` = مختفي**.

كل المقاطع المسماة "Bootstrap X — Dark Mode Fix" في `style.css` (الأسطر 1321–1416) هي ترقيع
يدوي واحداً واحداً لهذه المشكلة. وكل مكوّن لم يُرقَّع بعد → مكسور.

### 2.2 قائمة الأعطال المؤكَّدة (كل واحدة تحققت منها في الكود)

| # | العنصر | أين يظهر | ما يحدث في الوضع الليلي | السبب |
|---|---|---|---|---|
| **1** | `.text-muted` | **49 استخدام في 19 view** | نص رمادي غامق `rgba(33,37,41,.75)` فوق `#0d1117` — تباين ≈ 1.4:1 → **غير مقروء** | لا يوجد أي override في المشروع |
| **2** | `.text-dark` | 15 استخدام (badges: `bg-warning text-dark`, `bg-light text-dark`) | `#212529` فوق خلفية داكنة → **مختفي** | لا يوجد override |
| **3** | `.dropdown-menu` (لوحة **Sort & Filter** في `admin/product/index.php:60`) | لوحة الفلاتر كاملة | اللوحة تبقى **بيضاء**، بينما `.form-check-label` مجبَر على `color: var(--text-color)` = `#e6edf3` → **كل نصوص الفلاتر تختفي**. و`.form-check-input` مجبَر على `background: #21262d` → مربعات سوداء على خلفية بيضاء | `style.css:1348` + `style.css:1330-1345` يقلبان اللون بينما خلفية الـ dropdown ثابتة بيضاء |
| **4** | `.page-link` / `.pagination` (**Next box**) | `product/product.php:230`, `admin/orders/index.php:180`, `admin/product/index.php:363`, `admin/users/index.php:176`, `admin/support.php:125` | صندوق **أبيض ساطع** وسط صفحة داكنة، والحالة `disabled` رمادي على أبيض | **صفر** قواعد للـ pagination في كل المشروع |
| **5** | `.btn-outline-dark` | `home.php:35`, `checkout/confirmation.php:23` | `color:#212529; border-color:#212529` → **زر مختفي تماماً** | القاعدة الخضراء الموحدة (`style.css:624`) تغطي `btn-secondary/dark/primary/success/outline-secondary` فقط |
| **6** | `.btn-outline-primary` | 10 مواضع (زر Sort & Filter نفسه، Filter، Edit Permissions…) | `#0d6efd` على `#0d1117` — تباين 3.1:1، تحت حد WCAG AA | غير مغطى بالقاعدة الموحدة |
| **7** | سهم `.form-select` (**قائمة Sort في صفحة المنتجات**) | `product/product.php:41` | `style.css:381` يفرض `background-color: var(--input-bg)` لكن السهم صورة SVG بلون `#343a40` مضمَّنة في `background-image` → **السهم يختفي على الخلفية الداكنة** | Bootstrap يغيّر الـ SVG فقط عبر `data-bs-theme` |
| **8** | `.bg-light` | `admin/product/index.php:141` (عدّاد المنتجات داخل الفلاتر) | `#f8f9fa` + `text-dark` — بقعة بيضاء | لا override |
| **9** | `.dropdown-item` | `inc/navbar.php`, `admin/inc/navbar.php` | نص `#212529` على خلفية `dropdown-menu` بيضاء — يعمل بالصدفة، لكنه يكسر تناسق الثيم | لا override |
| **10** | `background:#fff` مضمّن | `admin/my-info.php:161` | صندوق أبيض ثابت لا يتأثر بالثيم | inline style |
| **11** | `.btn-outline-secondary:hover` | زر Clear في الفلاتر | القاعدة الخضراء تغطي الحالة العادية فقط، لا `:hover` → قفزة لون مفاجئة | `style.css:632-641` |
| **12** | `<hr style="border-color:var(--section-border)">` | `admin/product/index.php:126` | خط داكن على لوحة dropdown بيضاء → مختفي | نفس تعارض #3 |

---

## 3. الملفات التي **ستُحذف**

> **لن يُحذف أي ملف قبل التحقق.** كل ملف أصلي يُنسخ أولاً إلى
> `public/css/_backup-2026-08-24/` ويُحذف من هناك في الخطوة الأخيرة فقط بعد موافقتك.

| # | الملف المحذوف | لماذا |
|---|---|---|
| 1 | `public/css/style.css` | يُقسَّم إلى 24 ملفاً في `base/` `vendor/` `layout/` `components/` `animations/` |
| 2 | `public/css/admin/admin.css` | يُقسَّم إلى 12 ملفاً في `admin/` |
| 3 | `public/css/admin/branding.css` | يُنقل إلى `admin/pages/branding.css` |
| 4 | `public/css/admin/admin-login.css` | يُنقل إلى `admin/pages/login.css` |
| 5 | `public/css/admin/admin-layout/admin-head.css` | يُنقل إلى `admin/layout/head.css` |
| 6 | `public/css/admin/admin-layout/admin-navbar.css` | يُنقل إلى `admin/layout/navbar.css` (مع حذف تكرار `.dropdown-menu`) |
| 7 | `public/css/admin/admin-layout/admin-footer.css` | يُنقل إلى `admin/layout/footer.css` |
| 8 | `public/css/pages/home.css` | يُنقل إلى `store/pages/home.css` (منزوع التكرار) |
| 9 | `public/css/pages/home-slider.css` | يُنقل إلى `store/pages/home-slider.css` |
| 10 | `public/css/pages/products.css` | يُنقل إلى `store/pages/products.css` (منزوع التكرار) |
| 11 | `public/css/pages/product-details.css` | يُنقل إلى `store/pages/product-details.css` (منزوع التكرار) |
| 12 | `public/css/pages/checkout.css` | يُنقل إلى `store/pages/checkout.css` |
| 13 | `public/css/pages/my-info.css` | يُنقل إلى `store/pages/my-info.css` |
| 14 | `public/css/pages/wishlist.css` | يُنقل إلى `store/pages/wishlist.css` |

**مجلدات تُحذف:** `public/css/pages/` و `public/css/admin/admin-layout/` (تصبح فارغة).

**عدد الملفات المحذوفة: 14** (منها 12 نقل، و2 تقسيم حقيقي).

---

## 4. الملفات والمجلدات التي **ستُنشأ**

### 4.1 الشجرة الكاملة الجديدة

```
public/css/
│
├── store.css                      ← ملف الدخول للمتجر (@import فقط، لا قواعد)
├── admin.css                      ← ملف الدخول للوحة الأدمن (@import فقط)
│
├── base/                          【 الأساس — مشترك بين المتجر والأدمن 】
│   ├── tokens.css                 متغيرات الألوان :root + body.dark-mode + سلّم المسافات
│   ├── reset.css                  إعادة الضبط، body، html، إصلاح padding الـ Bootstrap
│   ├── typography.css             .section-title، .text-shadow، أحجام النصوص
│   ├── accessibility.css          .skip-nav، :focus-visible، أهداف اللمس 44px
│   └── utilities.css              أدوات الـ responsive المشتركة، .toolbar، .img-error
│
├── vendor/                        【 ترقيع Bootstrap — مكان واحد لكل شيء 】
│   ├── bootstrap-theme.css        ⭐ الملف الرئيسي لإصلاح الوضع الليلي (القسم 5)
│   ├── bootstrap-forms.css        form-check, form-select, form-range, input-group
│   ├── bootstrap-tables.css       .table + .table-* في الوضعين
│   ├── bootstrap-feedback.css     .alert، .badge، .progress
│   ├── bootstrap-navigation.css   .pagination، .dropdown-menu، .nav-tabs، .breadcrumb
│   └── bootstrap-buttons.css      .btn-outline-*، .btn-close، الأزرار المعطّلة
│
├── layout/                        【 هيكل الصفحة — مشترك 】
│   ├── navbar.css                 الناف بار العام + تمييز Wishlist/Cart النشط
│   ├── footer.css
│   └── store-mode-bar.css         الشريط الذي يظهر للأدمن أثناء تصفح المتجر
│
├── components/                    【 مكوّنات مشتركة بين أكثر من صفحة 】
│   ├── buttons.css                الزر الأخضر الموحد + btn-outline-theme
│   ├── forms.css                  inputs، selects، autofill، حقول الوضع الليلي
│   ├── floating-labels.css        .float-group (يستعمله المتجر والأدمن معاً)
│   ├── modals.css                 كل المودالات + .modal-backdrop
│   ├── product-card.css           الكارد الموحد + "image-only"
│   ├── favorite-button.css
│   ├── discount-badge.css
│   ├── quantity-box.css           ⭐ نسخة واحدة بدل 3 نسخ متضاربة
│   ├── price-display.css
│   ├── cart-badge.css             عدّاد السلة + شارة الإشعارات
│   ├── cart-sidebar.css           الـ offcanvas
│   ├── breadcrumb.css             .store-breadcrumb (6 صفحات تستعمله)
│   ├── section-header.css         عناوين الأقسام
│   ├── notifications.css          نظام الإشعارات كاملاً (Phase 15)
│   ├── back-to-top.css
│   └── skeleton.css               شاشات التحميل
│
├── animations/                    【 الحركة 】
│   ├── keyframes.css              كل @keyframes في مكان واحد
│   ├── page-transitions.css       انتقال الصفحات + شريط التحميل
│   ├── micro-interactions.css     تبويبات، dropdown fade، swal toasts، scale on click
│   └── reduced-motion.css         ⭐ كل قواعد prefers-reduced-motion مجمّعة (تُحمَّل أخيراً)
│
├── store/                         【 خاص بالمتجر فقط 】
│   └── pages/
│       ├── home.css
│       ├── home-slider.css
│       ├── products.css           فلاتر + شبكة المنتجات + #results-count
│       ├── product-details.css    specs box + الوصف + صورة المنتج
│       ├── checkout.css
│       ├── my-info.css
│       └── wishlist.css
│
└── admin/                         【 خاص بلوحة الأدمن فقط 】
    ├── layout/
    │   ├── head.css
    │   ├── navbar.css
    │   └── footer.css
    ├── components/
    │   ├── stat-cards.css         بطاقات الإحصائيات + بطاقة الرسم البياني
    │   ├── data-table.css         .admin-table
    │   ├── page-header.css        .admin-page-header
    │   ├── search-form.css        .search-form (orders/users/support)
    │   ├── export-button.css      .btn-export-csv
    │   ├── permissions-grid.css   .perm-grid / .perm-item
    │   └── pickers.css            Category Picker + Product Picker modals
    └── pages/
        ├── home.css               بلاطات الصفحة الرئيسية للأدمن
        ├── dashboard.css          صور المنتجات المصغّرة + أدوات الطباعة
        ├── products.css           بطاقة "إضافة منتج" + صف المنتج المخفي + زر الحذف
        ├── orders.css             صناديق تفاصيل الطلب
        ├── users.css              نظام الـ Strikes
        ├── support.css            بطاقات الرسائل
        ├── settings.css           صناديق الإعدادات
        ├── branding.css           (منقول)
        └── login.css              (منقول — صفحة مستقلة لا تحمّل style.css)
```

### 4.2 ملف PHP جديد واحد

```
app/helpers/assets_helper.php      ← دالة cssLinks('store'|'admin') تطبع وسوم <link>
```
يُحمَّل تلقائياً عبر الـ glob الموجود في `public/index.php:14` — لا يحتاج تسجيل يدوي.

### 4.3 الإحصائية

| | قبل | بعد |
|---|---:|---:|
| مجلدات CSS | 3 | **12** |
| ملفات CSS | 14 | **51** |
| أكبر ملف | 2551 سطر | **≈ 200 سطر** |
| ملفات > 300 سطر | 2 | **0** |

---

## 5. إصلاح الوضع الليلي — ماذا سأكتب بالضبط

### 5.1 القرار الأساسي: تفعيل `data-bs-theme`

سأعدّل `public/js/core/theme.js` ليضبط **`document.documentElement.setAttribute('data-bs-theme','dark')`**
بجانب `body.dark-mode` الحالي (لن أحذف `body.dark-mode` — كل CSS المشروع يعتمد عليه).

هذا وحده يصلح تلقائياً: pagination، dropdown-menu، dropdown-item، text-muted، bg-light،
form-select arrow، table، close button، nav-tabs، accordion، list-group، card، وكل مكوّن
Bootstrap مستقبلي.

**+ إضافة مهمة:** سأضبط السمة أيضاً في PHP داخل `<html>` في `app/views/inc/head.php` و
`app/views/admin/inc/head.php` عبر سكربت صغير inline يقرأ `localStorage` **قبل** رسم الصفحة،
لمنع "ومضة بيضاء" (FOUC) عند تحميل كل صفحة في الوضع الليلي — وهي مشكلة موجودة حالياً.

**⚠️ المخاطرة المعلَنة:** تفعيل `data-bs-theme` يغيّر ألوان Bootstrap الافتراضية في **كل**
الصفحات دفعة واحدة. بعض الترقيعات اليدوية الـ 383 الحالية قد تصبح زائدة أو متعارضة. لهذا
المرحلة 5 من التنفيذ مخصّصة بالكامل لمراجعة كل صفحة بصرياً في الوضعين.

### 5.2 الإصلاحات الصريحة في `vendor/bootstrap-theme.css`

حتى لو غطّى `data-bs-theme` معظم الحالات، سأكتب صراحةً:

| العطل | الإصلاح |
|---|---|
| #1 `.text-muted` | ربطه بـ `var(--placeholder-color)` في الوضعين — يضمن تباين ≥ 4.5:1 |
| #2 `.text-dark` | داخل `.badge`: ربطه بلون نص ثابت داكن **مع** خلفية فاتحة ثابتة (الشارة لا تقلب) |
| #3 لوحة Sort & Filter | `.dropdown-menu` تأخذ `background: var(--card-bg)` و `color: var(--text-color)` و `border: var(--section-border)` — فيتوافق مع `.form-check-label` بدل التعارض معه |
| #4 Next box / pagination | ملف `vendor/bootstrap-navigation.css` جديد: `.page-link` بـ `var(--card-bg)` + `var(--text-color)`، `.active` بـ `var(--accent)`، `.disabled` بـ `var(--placeholder-color)` |
| #5 `.btn-outline-dark` | إضافته للقاعدة الخضراء الموحدة (اتساقاً مع بقية الأزرار) |
| #6 `.btn-outline-primary` | نسخة theme-aware: `color: var(--accent)` + `border-color: var(--accent)` + hover ممتلئ |
| #7 سهم `.form-select` | SVG مضمَّن بلون `currentColor` عبر `data:` URI، نسخة فاتحة ونسخة داكنة |
| #8 `.bg-light` | ربطه بـ `var(--bg-color)` مع `color: var(--text-color)` |
| #9 `.dropdown-item` | `color: var(--text-color)`، hover بـ `var(--bg-color)` |
| #10 `admin/my-info.php:161` | حذف `background:#fff` المضمّن واستبداله بكلاس `.qr-box` في `admin/pages/settings.css` |
| #11 `.btn-outline-secondary:hover` | إضافته لمجموعة الـ hover الخضراء |
| #12 `<hr>` المضمّن | استبداله بكلاس `.filter-divider` |

### 5.3 فحص تباين إجباري
بعد الإصلاح سأمرّ على كل لون نص/خلفية جديد وأتأكد أنه ≥ **4.5:1** (WCAG AA) في الوضعين،
وأدرج الجدول في تقرير النهاية.

---

## 6. كيف ستُحمَّل 51 ملفاً؟ (قرار يحتاج رأيك)

| الخيار | كيف | إيجابيات | سلبيات |
|---|---|---|---|
| **أ — `@import` (المقترح)** ⭐ | `store.css` و `admin.css` يحويان `@import` فقط | تعديل سطر واحد في `head.php`. تصحيح سهل في DevTools (كل ملف منفصل) | تحميل متسلسل — أبطأ قليلاً على الإنترنت، غير محسوس على `localhost` |
| **ب — وسوم `<link>` متعددة** | `assets_helper.php` يطبع 30 وسم `<link>` | تحميل متوازٍ | 30 طلب HTTP، و`head` طويل |
| **ج — مجمِّع PHP** | `assets_helper.php` يدمج الملفات في `public/css/dist/store.css` ويعيد التوليد عند تغيّر أي `filemtime` | طلب واحد، أسرع شيء، الملفات تبقى مقسّمة | كود إضافي + مجلد `dist` يحتاج صلاحية كتابة |

**توصيتي:** الخيار **أ** الآن (بسيط وآمن)، مع كتابة `assets_helper.php` بحيث يدعم **ج** لاحقاً
بتبديل سطر واحد إن أردت. إن وافقت على الخطة بدون تحديد، سأنفّذ **أ**.

---

## 7. ملفات PHP/JS التي ستُعدَّل

| الملف | التعديل |
|---|---|
| `app/views/inc/head.php` | استبدال وسم `style.css` بـ `store.css` + سكربت منع FOUC |
| `app/views/admin/inc/head.php` | استبدال 5 وسوم بـ `store.css` + `admin.css` + سكربت FOUC |
| `app/views/admin/login.php` | تحديث المسارات إلى `admin/pages/login.css` |
| `app/views/admin/store-reauth.php` | نفس الشيء |
| `app/views/auth/reset-password.php` | تحديث مسار `style.css` → `store.css` |
| `app/views/admin/my-info.php` | حذف `style="...background:#fff"` المضمّن (السطر 161) |
| `app/views/admin/product/index.php` | استبدال `<hr style>` بكلاس (السطر 126) |
| `public/js/core/theme.js` | إضافة `data-bs-theme` على `<html>` في الدوال الثلاث |
| `app/controllers/HomeController.php` | تحديث مسار `pages/` → `store/pages/` (سطر 82–83) |
| `app/controllers/ProductController.php` | نفس الشيء (سطر 69، 169–171) |
| `app/controllers/CheckoutController.php` | نفس الشيء (سطر 33) |
| `app/controllers/MyInfoController.php` | نفس الشيء (سطر 42) |
| `app/controllers/WishlistController.php` | نفس الشيء (سطر 21) |
| `app/controllers/AdminMyInfoController.php` | نفس الشيء (سطر 40) |
| `app/controllers/AdminBrandingController.php` | تحديث → `admin/pages/branding.css` (سطر 36) |
| `app/helpers/assets_helper.php` | **ملف جديد** |

**لن أمس:** أي ملف في `app/models/`، `app/core/`، `database/`، `vendor/`، أو أي ملف JS غير
`theme.js`.

---

## 8. ترتيب التنفيذ

| المرحلة | العمل | يمكن التراجع؟ |
|---|---|---|
| **0** | نسخ `public/css/` كاملاً إلى `public/css/_backup-2026-08-24/` | — |
| **1** | إنشاء كل المجلدات الجديدة (فارغة) | نعم |
| **2** | تقسيم `style.css` → `base/` `vendor/` `layout/` `components/` `animations/`. نسخ حرفي بلا تغيير منطق، فقط توزيع + إزالة التكرار المؤكَّد من القسم 1.2(أ) | نعم |
| **3** | تقسيم `admin.css` → `admin/components/` + `admin/pages/`؛ نقل بقية ملفات الأدمن | نعم |
| **4** | نقل `pages/` → `store/pages/` وإزالة السطور المكرّرة منها | نعم |
| **5** | دمج نظامَي الـ responsive: توزيع كتل `@media` على ملف كل مكوّن (مع الحفاظ على أسبقية الكتل "Appended" لأنها الأحدث) | نعم |
| **6** | إنشاء `store.css` + `admin.css` + `assets_helper.php` وتحديث `head.php` والكنترولرز | نعم |
| **7** | ✅ **نقطة تحقق:** فتح الموقع، مقارنة كل صفحة بالنسخة الاحتياطية بصرياً في الوضع الفاتح | — |
| **8** | إصلاحات الوضع الليلي (القسم 5) — `data-bs-theme` + `bootstrap-theme.css` | نعم |
| **9** | ✅ **نقطة تحقق:** كل صفحة في الوضعين + 3 مقاسات (1280 / 768 / 375) | — |
| **10** | حذف الملفات القديمة الـ 14 + المجلدين الفارغين (بعد موافقتك) | من النسخة الاحتياطية |
| **11** | تقرير نهائي: قبل/بعد + جدول التباين + قائمة كل تغيير | — |

**الصفحات التي سأفحصها في المرحلتين 7 و 9 (14 صفحة):**
الرئيسية · المنتجات (مع الفلاتر والـ pagination) · تفاصيل منتج · السلة · الدفع · تأكيد الطلب ·
معلوماتي · قائمة الأمنيات · من نحن · اتصل بنا · لوحة الأدمن · إدارة المنتجات (لوحة Sort &
Filter) · إدارة الطلبات · تسجيل دخول الأدمن.

---

## 9. المخاطر والضمانات

| الخطر | الاحتمال | الضمانة |
|---|---|---|
| كسر ترتيب الـ cascade عند التقسيم (383 `!important` تعتمد على الترتيب) | **عالٍ** | ترتيب `@import` في `store.css` يطابق ترتيب المقاطع الأصلي في `style.css` حرفياً. `reduced-motion.css` يبقى الأخير |
| `data-bs-theme` يغيّر مظهر صفحات غير متوقعة | متوسط | مرحلة 9 كاملة للفحص البصري + النسخة الاحتياطية جاهزة |
| فقدان قاعدة أثناء نقل 2551 سطراً | متوسط | مقارنة عدد السيلكتورات قبل/بعد بأمر آلي (`grep -c`) في نهاية كل مرحلة |
| إزالة "تكرار" هو في الحقيقة override مقصود | متوسط | لن أحذف إلا التكرار **الحرفي المتطابق**؛ أي اختلاف ولو بقيمة واحدة يُبقى كـ override موثّق بتعليق |
| 30 طلب HTTP إضافي | منخفض | localhost — غير محسوس. والخيار (ج) جاهز إن أردت |

---

## 10. ما أحتاجه منك قبل أن أبدأ

1. **موافقة** على الخطة (أو تعديلاتك عليها).
2. **الأذونات** من القسم 0 — الصقها في `.claude/settings.local.json`.
3. **XAMPP شغّال** (Apache + MySQL) حتى أستطيع الفحص البصري.
4. **قرار القسم 6** — أو اتركه لي وسأنفّذ الخيار (أ).
5. تأكيد أن حذف الملفات الـ 14 في المرحلة 10 مقبول (النسخة الاحتياطية تبقى في
   `public/css/_backup-2026-08-24/` حتى تحذفها أنت).
