# تسليم: أكمل تنظيف مشروع Cairo Store من المرحلة 4

> **انسخ هذا الملف كاملاً والصقه كأول رسالة في السيشن الجديدة.**

---

أنت تكمل عملاً بدأه Claude في سيشن سابقة. المشروع `C:\xampp\htdocs\STORE` — متجر PHP
بمعمارية MVC مخصّصة (بلا فريم ورك). أُنجزت المراحل 0 إلى 3 بالكامل، وأنت تبدأ من
**المرحلة 4**.

**اقرأ أولاً هذه الملفات في المشروع** (بهذا الترتيب) لتفهم ما جرى:
`CLEANUP-PLAN.md` · `CLEANUP-REPORT-PHASE-0-1.md` · `CLEANUP-REPORT-PHASE-2.md` ·
`CLEANUP-REPORT-PHASE-3.md`

---

## 1. المنهج — التزم به حرفياً

هذا أهم قسم. صاحب المشروع يقدّر الدقة والصدق أكثر من السرعة.

### 1.1 قبل أي تعديل

```bash
git checkout -b cleanup/phase-N-<اسم>     # branch مستقل لكل مرحلة
php scripts/smoke-test.php                 # baseline أخضر قبل اللمس
```

### 1.2 بعد كل خطوة منطقية

- `php scripts/smoke-test.php` — 44 راوت GET + صياغة المعدَّل
- `php scripts/audit-imports.php` — كلاسات غير مستوردة (Fatal كامن) + استيرادات ميتة
- commit فوري برسالة تشرح **لماذا** لا **ماذا**

### 1.3 التحقق — لا تكتفِ بـ`php -l`

سكربت الدخان يغطي GET فقط. **مسارات POST الستون لا يغطيها.** لكل تعديل يمسّ POST:

- **اختبار وحدة مباشر** للمنطق المستخرج (`php -r` مع تأكيدات ✓/✗)
- **اختبار HTTP حقيقي** بجلسة مزوّرة على القرص + CSRF (الطريقة في القسم 4)
- **نظّف بيانات الاختبار بعدها دائماً** — منتجات، إشعارات، جلسات

### 1.4 الصدق في التقارير

- لو اكتشفت عطلاً سابقاً لعملك: **أعد إنتاجه أولاً** لتثبت أنه ليس منك، ثم أصلحه ووثّقه.
- لو أخطأت: قل ذلك بوضوح في التقرير. (السيشن السابقة أفرغت ملفاً بالخطأ بسبب regex فيه
  `\P`، واستُرجع بـ`git checkout --`. وثّقت ذلك في تقرير المرحلة 3.)
- لو رقم يبدو جيداً لكنه مضلّل، **فصّله**. مثال: أسطر الكنترولرز زادت +101، لكن التوثيق
  زاد +631 والكود الفعلي نزل −530.
- **لا تدّعِ تحققاً لم تفعله.**

### 1.5 حدود العمل

- **لا تنفّذ ولا تخطّط لمرحلة لم يوافق عليها.** أنجز المرحلة، اكتب التقرير، توقّف واسأل.
- لو بند في الخطة تبيّن أنه خطأ عند فحص الكود الحقيقي — **قل ذلك ولا تنفّذه**. (في
  المرحلة 3 أُلغيت طبقة الـvalidator لأن التحقق مخصّص لكل نقطة لا مكرَّر.)

### 1.6 لغة التواصل

**عربي.** التعليقات في الكود عربية، ورسائل الـcommit عربية، والتقارير عربية.
أسماء الكلاسات والدوال إنجليزية.

---

## 2. الحالة الآن

### 2.1 git

23 commit على `master`، الشجرة نظيفة. كل مرحلة على branch مدموج بـ`--no-ff`.

```
تقرير المرحلة 3
دمج: إكمال توثيق OpenAPI — تغطية 100%
دمج المرحلة 3 (أ-د): توحيد respond، استخراج خدمتين، مقدّمة JSON موحّدة
دمج المرحلة 2: صفر SQL في الكنترولرز
دمج: إصلاح last_activity + مرحلة الهروب الأمني
دمج المرحلة 1: حذف الكود الميت وتوحيد التسمية
إضافة scripts/audit.php · scripts/smoke-test.php · Baseline
```

### 2.2 الأحجام

| الطبقة | ملفات | أسطر |
|---|---:|---:|
| `app/views` | 49 | **7,160** ← هدف المرحلة 5 |
| `app/Controllers` | 24 | 7,056 (منها 2,067 توثيق) |
| `public/js` | 28 | **4,948** ← هدف المرحلة 6 |
| `app/Models` | 16 | 4,725 |
| `public/css` | 66 | 4,997 |
| `app/Core` | 8 | **666** ← هدف المرحلة 4 |
| `app/helpers` | 6 | 488 |

### 2.3 المؤشرات

| المؤشر | القيمة | الهدف |
|---|---:|---|
| SQL في الكنترولرز | **0** | ✅ منجز |
| وصول DB من الـviews | **0** | ✅ منجز |
| كنترولرز بلا توثيق OpenAPI | **0** | ✅ منجز |
| كود ميت في `Core` | **0** | ✅ منجز |
| أسطر `<script>` مضمّنة في الـviews | **579** | ← المرحلة 5 |
| أسطر `<style>` مضمّنة في الـviews | **55** | ← المرحلة 5 (الهدف 0) |
| partials في `views/shared` | **1** | ← المرحلة 5 (الهدف >5) |
| مواضع `<?= ?>` تحتاج مراجعة | 85 من 771 | رُوجعت كلها يدوياً وثبت أنها آمنة |

---

## 3. الأدوات المتاحة

```bash
php scripts/smoke-test.php              # 44 راوت + صياغة المعدَّل (سريع)
php scripts/smoke-test.php --lint-all   # + كل ملفات PHP (بطيء، للتحقق النهائي)
php scripts/smoke-test.php --verbose    # اطبع كل راوت لا الفاشل فقط
php scripts/audit.php                   # قياس حالة الكود
php scripts/audit.php --json            # للمقارنة الآلية
php scripts/audit-imports.php           # كلاسات غير مستوردة + استيرادات ميتة
php scripts/audit-escaping.php          # تصنيف مخرجات الـviews أمنياً
php scripts/audit-escaping.php app/views --list ATTR
composer docs:generate                  # public/docs/openapi.yaml (يجب أن يمرّ بصفر تحذيرات)
```

`scripts/audit-baseline.json` لقطة ما قبل التنظيف — قارن بها.

---

## 4. مصائد تعلّمتها السيشن السابقة — لا تكررها

### 4.1 نهايات السطور CRLF

**كل ملفات المشروع بنهايات CRLF.** `sed` بأنماط تنتهي بـ`$` لن تطابق، و`str_replace`
بأنماط فيها `\n` لن تطابق.

```php
$raw    = file_get_contents($file);
$isCrlf = str_contains($raw, "\r\n");
$src    = $isCrlf ? str_replace("\r\n", "\n", $raw) : $raw;
// ... عدّل $src ...
file_put_contents($file, $isCrlf ? str_replace("\n", "\r\n", $src) : $src);
```

### 4.2 لا تحذف كتل كود بـregex

`preg_replace` يُرجع `null` عند فشل النمط، و`file_put_contents($f, null)` **يفرّغ الملف**.
هذا ما حدث فعلاً بسبب `\P` في نمط (وهي في PCRE بداية خاصية Unicode لا حرف عادي).

**البديل:** احذف بعدّ الأقواس سطراً سطراً، أو استعمل أداة التحرير الدقيقة. وإن أصررت على
`preg_replace`، تحقق `if ($new === null) { /* لا تكتب */ }`.

### 4.3 الـshell يمسخ الـescaping

`php -r '...'` مع backslashes ونصوص متداخلة يفشل بطرق صامتة. **اكتب سكربتاً في
scratchpad وشغّله بـ`php file.php`** بدل `php -r`.

### 4.4 محدّد الـregex

`application/json` داخل نمط بمحدّد `/` يكسره. اهرب `\/` أو استعمل `#` محدّداً.

### 4.5 `php -l` لا يكفي

مسخ الـshell أنتج `use AppModelsStockNotificationModel;` — صياغة PHP صحيحة تماماً، وكلاس
غير موجود. **شغّل `php scripts/audit-imports.php` بعد أي تعديل على `use`.**

### 4.6 الجلسات منفصلة تماماً

جلسة المستخدم `PHPSESSID` وجلسة الأدمن `admin_session` منفصلتان بالاسم والمحتوى.
**لا تستدعِ `isUser()` في الـbootstrap** — تبدأ `session_start()` باسم `PHPSESSID` قبل أن
تضبط `startAdminSession()` اسم جلسة الأدمن، فتنكسر مصادقة الأدمن بالكامل.

### 4.7 طريقة اختبار مسارات POST

```php
// جلسة أدمن مزوّرة على القرص
$path = session_save_path() ?: sys_get_temp_dir();
$sid  = "test" . bin2hex(random_bytes(6));
$tok  = bin2hex(random_bytes(32));
file_put_contents("$path/sess_$sid",
    "admin_id|i:1;admin_name|s:11:\"Ahmad Saleh\";admin_role|s:1:\"A\";"
  . "csrf_token|s:64:\"$tok\";last_active|i:" . time() . ";");
// جلسة مستخدم: user_id|i:7;user_name|s:5:"Ahmad";csrf_token|s:64:"$tok";
```

```bash
curl -s -b "admin_session=$SID" -X POST http://localhost/STORE/public/admin/... \
     -d "csrf_token=$TOK&..."
# رفع ملف: -F "variants[0][image]=@/path/to.png;type=image/png"
```

**احذف ملف الجلسة بعدها.**

---

## 5. اتفاقيات المشروع — احترمها

| القرار | السبب |
|---|---|
| **الموديلات كلها `static`** | 16 موديلاً متّسقين، لا DI container ولا اختبارات وحدة تحتاج mocking. لا تحوّلها. |
| **استجابة JSON بشكل `{success, message, ...}`** | الـfrontend يعتمد عليه في `js/core/utils.js`. لا تغيّر أسماء المفاتيح. |
| **نقاط JSON تُرجع 200 حتى عند فشل التحقق** | سلوك قائم، النتيجة تُقرأ من `success`. موثّق في الـspec كما هو. |
| **`.env` خارج git** | فيه أسرار حقيقية. البديل `.env.example`. |
| **التوثيق يُكمَل لا يُحذف** | `composer docs:generate` يجب أن يبقى بصفر تحذيرات وتغطية 100%. |
| **`public/docs/` متتبَّع** | مخدوم فعلاً عبر Swagger UI. |
| **`public/images/` متتبَّع** | قاعدة البيانات تشير إليه بالمسار. |
| **حارسا `function_exists` الباقيان مقصودان** | `BackupModel` (فحص `exec`) و`getVisitorGender` (سكربتات CLI). |

---

## 6. المرحلة 4 — الـCore *(يوم واحد · مخاطرة متوسطة)*

### 6.1 المشكلة

`app/Core/Controller.php` — الدالة `view()` تفرض تسلسلاً مثبّتاً:

```php
require_once APPROOT . '/views/inc/head.php';
require_once APPROOT . '/views/inc/navbar.php';
require_once $viewFile;
require_once APPROOT . '/views/inc/footer.php';
```

ثلاث مشاكل:

1. **لا مرونة في الـlayout.** لهذا **ثلاث صفحات تكتب `<!DOCTYPE html>` كاملاً بيدها**:
   `app/views/admin/login.php` · `app/views/admin/store-reauth.php` ·
   `app/views/auth/reset-password.php`
2. **`require_once` غلط** — يمنع عرض نفس الـview مرتين في طلب واحد (عطل كامن).
3. **`die("View file [...] not found!")`** بدل صفحة 404 حقيقية، ويسرّب مسار الخادم.

**النموذج الصحيح موجود أصلاً** في `app/Core/AdminController.php::adminView()` — يحقن
`$adminName` و`$csrf` و`$newOrders` و`$newMessages` تلقائياً. المطلوب توحيدهما.

### 6.2 المطلوب

```php
protected function view(string $view, array $data = [], string $layout = 'store'): void
// 'store' | 'admin' | 'bare'
```

- `store` → head + navbar + view + footer (السلوك الحالي)
- `admin` → `admin/inc/head` + `admin/inc/navbar` + view + `admin/inc/footer`
- `bare` → الـview وحده (للصفحات المستقلة)

**أعد كتابة `AdminController::adminView()` لتفوّض إلى `view(..., 'admin')`** مع إبقاء حقن
متغيرات الأدمن.

**احتفظ بـ`touchUserActivity()`** في مسار `store` **فقط** — لا تنقلها ولا تعمّمها. اقرأ
التعليق فوقها في `Controller.php` قبل اللمس.

### 6.3 خطوات

1. أضف دعم `$layout` مع إبقاء `'store'` افتراضياً — صفر تغيير سلوك حتى الآن.
2. `require_once` → `require` للـview فقط. شغّل الدخان — لو ظهرت صفحة بحجم مضاعف فهناك
   عرض مزدوج كامن، حقّق فيه.
3. `die()` → صفحة 404 حقيقية: `http_response_code(404)` + `error_log` بالمسار +
   رسالة عامة للمستخدم بلا تسريب مسار.
4. حوّل الصفحات الثلاث إلى `layout: 'bare'`، وانقل `<head>` المكرر إلى
   `app/views/inc/head-bare.php`. **قارن بصرياً قبل/بعد** (الطريقة في القسم 9).
5. `AdminController::adminView()` تفوّض لـ`view()`.
6. **الهيلبرز:** انقل تحميلها من `glob()` في `public/index.php:14` إلى
   `composer.json` تحت `autoload.files`، ثم `composer dump-autoload -o`.
   ⚠️ **انتبه:** `functions.php` يجب أن يُحمَّل أولاً — رتّب المصفوفة يدوياً.

### 6.4 معيار النجاح

- الصفحات الثلاث تستعمل `bare` ولا تكتب `<!DOCTYPE>` بيدها
- 44/44 راوت أخضر + `audit-imports.php` نظيف
- مقارنة بصرية للصفحات الثلاث: صفر فرق تخطيط
- `composer dump-autoload -o` بصفر تحذيرات

---

## 7. المرحلة 5 — الـViews *(3-4 أيام · أكبر مرحلة)*

### 7.1 طبقة partials

`app/views/shared/` فيه **ملف واحد** (`order-cancel-button.php`). النمط مثبت وشغّال في
`app/views/admin/inc/export-csv-button.php` — اتبعه (توثيق المتغيرات المطلوبة في رأس
الملف ثم `include`).

| Partial مقترح | يُستخدم في |
|---|---|
| `shared/product-card.php` | `product/product.php` |
| `shared/rating-stars.php` | `product/product_dit.php` (3 مواضع) |
| `shared/order-status-badge.php` | `account/my-info.php` · `admin/orders/index.php` · `admin/orders/details.php` |
| `shared/category-buttons.php` | `home.php` و`product/product.php` — `$catEmoji` مكرر **حرفياً** |
| `shared/stock-badge.php` | يستدعي `getStockBadge()` الموجودة في `app/helpers/stock_badge_helper.php` — و`product_dit.php` يعيد كتابة منطقها يدوياً بـ`if/elseif` |

⚠️ **`getTag()` معرَّفة داخل `app/views/product/product.php`** — انقلها لهيلبر.

### 7.2 إخراج `<script>` و`<style>` المضمّنة

**579 سطر JS + 55 سطر CSS** داخل الـviews:

| الملف | script | style | الوجهة |
|---|---:|---:|---|
| `checkout/checkout.php` | 157 | 21 | `js/features/checkout.js` + دمج الـCSS في `css/store/pages/checkout.css` |
| `admin/my-info.php` | 144 | 0 | `js/admin/my-info.js` |
| `admin/store-reauth.php` | 63 | 0 | `js/admin/store-reauth.js` |
| `auth/reset-password.php` | 58 | 25 | `js/features/reset-password.js` + CSS |
| الباقي (17 ملف) | 157 | 9 | حسب الحالة |

**ابدأ بـ`checkout.php`** — تعليق داخل `public/css/store/pages/checkout.css` يشرح صراحةً
أن كل قاعدة هناك محتاجة `!important` لأنها تتصارع مع `<style>` مضمّن في الـview. حلّ هذا
يشيل سبب تلك الـ`!important`ات.

⚠️ **اقرأ ملف الـJS الوجهة قبل النقل** — قد يكون فيه منطق يعتمد على الـstyle المضمّن.

### 7.3 تمرير البيانات بـ`data-*`

أغلب الـ`<script>` المضمّنة موجودة لحقن قيم PHP في JS. البديل:

```php
<div id="checkoutRoot" data-cart='<?= htmlspecialchars(json_encode($cart), ENT_QUOTES) ?>'>
```
والـJS يقرأها من `dataset`.

### 7.4 تقليص الـviews الكبيرة

`admin/product/index.php` (374) · `admin/product/edit.php` (371) ·
`checkout/checkout.php` (329) · `admin/my-info.php` (329) · `product/product_dit.php` (320)

⚠️ **معظم هذا HTML طبيعي لا منطق مبعثر.** لا تطارد الأرقام. المستهدف: المنطق المتسرّب
والأصول المضمّنة والماركب المكرر — لا الـHTML نفسه.

### 7.5 معيار النجاح

- صفر `<style>` داخل أي view
- `<script>` مضمّن فقط حيث يمرّر بيانات لا منطقاً
- `views/shared` فيه 5 partials فأكثر
- **مقارنة A/B بصرية** على 3 مقاسات لكل صفحة متأثرة (القسم 9)

---

## 8. المرحلة 6 — الـJS *(2-3 أيام)*

4,948 سطر عبر 28 ملف. البنية (`core/` `features/` `admin/` `shared/`) سليمة.
**لم يُفحص بعمق بعد — ابدأ بتدقيق قبل أي خطة.**

الأكبر: `admin/products.js` (369) · `features/auth.js` (336) ·
`features/notifications.js` (330) · `core/ui.js` (325) · `admin/category-picker.js` (299)

يُضاف لها 579 سطراً قادمة من المرحلة 5.

**ابحث عن:** منطق مكرر بين `features/` و`admin/` · معالجات `fetch` متكررة (يوجد
`js/core/csrf.js` — تأكد أن الكل يستعمله) · مستمعات أحداث غير منظَّفة · `innerHTML`
ببيانات من الخادم (XSS في الواجهة).

---

## 9. أداة المقارنة البصرية (للمرحلتين 4 و5)

استُعملت في إعادة تنظيم الـCSS ومسكت عطلاً حقيقياً. المبدأ: نفس الـDOM، حمّل الأصول
القديمة ثم الجديدة، وقارن `getComputedStyle` لعشرات العناصر على 20+ خاصية.

```js
// ⚠️ عطّل الانتقالات أولاً — التبويب غير المعروض لا يُكمل الـtransition
// فتُرجع getComputedStyle قيماً متجمّدة في المنتصف
const s = document.createElement('style');
s.textContent = '*,*::before,*::after{transition:none!important;animation:none!important}';
document.head.appendChild(s);
```

- استعمل `mcp__Claude_Browser__javascript_tool` لا لقطات الشاشة (التبويب قد لا يكون
  معروضاً فتفشل اللقطة)
- `resize_window` بـ`preset: mobile/tablet/desktop`
- ⚠️ صفحة اختبار ثابتة تحتاج `<meta name="viewport">` وإلا بقي العرض 980px
- بعد أي تعديل CSS/JS: `fetch(url, {cache:'reload'})` ثم `navigate` بـ`force: true`

---

## 10. أعطال معروفة لم تُصلَح

| العطل | الموقع | لماذا تُرك |
|---|---|---|
| `AdminBrandingController::save` بـ130 سطر | `app/Controllers/AdminBrandingController.php` | منطق متماسك، يصعب اختباره بلا فورم سلايدر كامل. مرشّح للمرحلة 5 |
| `htmlspecialchars(addslashes($x))` في 5 مواضع | داخل `onclick` | **صحيح فعلاً** لهذا التداخل. `addslashes` لا تهرّب سطراً جديداً — ثغرة نظرية ببيانات أدمن. `json_encode` أمتن لكنه يتطلب إعادة بناء الاقتباسات |
| `products.stock_quantity` لا يُحدَّث أبداً | لوحة الأدمن | المخزون الحقيقي في `product_variants`. موثّق بتعليق في `ProductModel::findStockByIds` |

---

## 11. أول أمر تشغّله

```bash
cd /c/xampp/htdocs/STORE && php scripts/smoke-test.php && php scripts/audit.php
```

**يجب أن يكون أخضر: 44/44.** لو لم يكن، توقّف وحقّق قبل أي تعديل.

**شرط:** Apache و MySQL شغّالان في XAMPP.

---

## 12. المطلوب منك الآن

1. اقرأ التقارير الأربعة المذكورة في الأعلى.
2. شغّل الأمر أعلاه وتأكد أن الـbaseline أخضر.
3. **نفّذ المرحلة 4 فقط.**
4. اكتب `CLEANUP-REPORT-PHASE-4.md` بنفس أسلوب التقارير السابقة: ما أُنجز، الأعطال
   المكتشفة، ما لم يُنجز ولماذا، القياس قبل/بعد، والتحقق.
5. **توقّف واسأل** قبل المرحلة 5.
