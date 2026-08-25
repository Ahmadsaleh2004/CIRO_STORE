# خطة تنظيف المشروع — Controllers · Models · Views · Core · Helpers · JS

> **الحالة:** خطة فقط — لم يُعدَّل أي ملف. بانتظار موافقتك.
> **التاريخ:** 2026-08-25 · **المشروع:** `C:\xampp\htdocs\STORE`

---

## 0. الأذونات المطلوبة — اقرأ هذا أولاً

نفس أذونات شغل الـCSS، زائد `git`. الصق هذا في `.claude/settings.local.json`:

```json
{
  "permissions": {
    "allow": [
      "Read", "Write", "Edit",
      "Bash(git init:*)", "Bash(git add:*)", "Bash(git commit:*)",
      "Bash(git status:*)", "Bash(git diff:*)", "Bash(git log:*)",
      "Bash(git checkout:*)", "Bash(git branch:*)", "Bash(git stash:*)",
      "Bash(git restore:*)", "Bash(git rm:*)", "Bash(git mv:*)",
      "Bash(mkdir:*)", "Bash(cat:*)", "Bash(sed:*)", "Bash(cp:*)", "Bash(mv:*)",
      "Bash(rm:*)", "Bash(ls:*)", "Bash(find:*)", "Bash(grep:*)", "Bash(head:*)",
      "Bash(tail:*)", "Bash(wc:*)", "Bash(awk:*)", "Bash(sort:*)", "Bash(uniq:*)",
      "Bash(diff:*)", "Bash(tee:*)", "Bash(php:*)", "Bash(composer dump-autoload:*)",
      "Bash(curl:*)",
      "mcp__Claude_Browser__preview_start",
      "mcp__Claude_Browser__navigate",
      "mcp__Claude_Browser__computer",
      "mcp__Claude_Browser__read_page",
      "mcp__Claude_Browser__javascript_tool",
      "mcp__Claude_Browser__resize_window",
      "mcp__Claude_Browser__read_console_messages",
      "mcp__Claude_Browser__read_network_requests",
      "mcp__Claude_Browser__tabs_context",
      "mcp__Claude_Browser__tabs_create"
    ]
  }
}
```

**ملاحظات على الأذونات:**
- `Bash(php:*)` أوسع من `php -l` لأني رح أشغّل سكربت الدخان (`php scripts/smoke-test.php`).
- `Bash(curl:*)` لضرب الراوتات من سطر الأوامر.
- `composer dump-autoload` مرة واحدة بعد إعادة تسمية الموديل في المرحلة 1.
- **لن أستخدم** `git push` ولا أي أمر يتصل بشبكة خارجية. كل شي محلي.
- **شرط:** Apache + MySQL شغّالين في XAMPP.

---

## 1. تصحيح رقم أعطيتك إياه سابقاً

قلت لك إن `AdminProductsController` **1027 سطر**. الرقم صحيح كعدد أسطر الملف، لكنه
**مضلِّل**: قِسته بدقة الآن، و**282 سطر منه توثيق OpenAPI** (`#[OA\Post(...)]`) مش كود.

| الملف | إجمالي | OpenAPI | **كود فعلي** |
|---|---:|---:|---:|
| `AdminProductsController` | 1027 | 282 | **745** |
| `AdminAuthController` | 732 | 130 | **602** |
| `AdminOrdersController` | 679 | 231 | **448** |
| `AdminManageAdminsController` | 478 | 109 | **369** |
| `AdminUsersController` | 425 | 129 | **296** |
| `AdminNotificationController` | 273 | 153 | **120** ← 56% منه توثيق |

**المجموع: ~1436 سطر توثيق OpenAPI عبر 14 كنترولر.**

هذا يغيّر التشخيص: الكنترولرز أنحف مما بدا، وكودها الفعلي أفضل تنظيماً مما توقعت
(`storeAdd` مثلاً بيفوّض فعلاً لـ`parseAndUploadVariants()` و`AdminProductModel::create()`).
لسا محتاجة تنظيف، بس المشكلة **موزّعة** أكتر مما هي متكدّسة في ملف واحد.

**واكتشاف ثانٍ:** التوثيق **غير متّسق** — 10 كنترولرز فيها **صفر** توثيق OpenAPI، منها
`AuthController` (528 سطر) و`CheckoutController` (217) و`MyInfoController` (220). يعني
`public/docs/openapi.yaml` المولَّد ناقص نصف الـAPI. هذا بند مستقل بحد ذاته.

---

## 2. الوضع الحالي — مقيس

| الطبقة | ملفات | أسطر |
|---|---:|---:|
| `app/views` | 49 | **7140** |
| `app/controllers` | 24 | **6947** (منها ~1436 توثيق) |
| `public/js` | 28 | **4940** |
| `app/models` | 15 | **4460** |
| `app/core` | 9 | 626 |
| `app/helpers` | 6 | 429 |
| `public/index.php` | 1 | 209 (104 راوت) |

### 2.1 ما هو **سليم** فعلاً (لا تلمسه)

- **حدود MVC محترمة:** صفر استعلام قاعدة بيانات داخل أي view (فحصتها كلها).
- **الأمان مبني صح:** `Middleware::requirePermission()` مستعمل باتّساق في كل كنترولرز
  الأدمن، و`verifyCsrfToken()` مستدعى في كل مسار POST حسّاس.
- **`AdminController::adminView()`** نمط layout صحيح وكامل — يحقن `$adminName`,
  `$csrf`, `$newOrders`, `$newMessages` تلقائياً. **هذا هو النموذج** اللي جانب المتجر
  ناقصه.
- **`AdminController::sendCsv()`** يمنع تكرار تصدير CSV في 4 كنترولرز.
- **`Router.php` + `App.php`** بسيطين وشغّالين. لا داعي لتغييرهما.

### 2.2 ما هو **مكسور أو ميت**

| # | المشكلة | الدليل |
|---|---|---|
| 1 | **لا يوجد git** | `ls .git` → غير موجود |
| 2 | **لا توجد اختبارات** | صفر ملف باسم فيه `test` خارج `vendor/` |
| 3 | `app/core/Model.php` كود ميت | ولا موديل من الـ15 يعمل `extends Model` |
| 4 | `Controller::model()` كود ميت | `grep '$this->model('` → صفر نتيجة |
| 5 | autoloader مكرر | [public/index.php:21-31](public/index.php:21) يعيد تعريف `App\` رغم أن `vendor/composer/autoload_psr4.php` يحتوي `'App\\' => array($baseDir . '/app')` |
| 6 | `require_once` زائد | [app/core/AdminController.php:5](app/core/AdminController.php:5) يحمّل `auth_helper.php` رغم أن `public/index.php:14` يحمّل كل الهيلبرز بـ`glob` |
| 7 | `Product_dit` كاسر التسمية | الموديل الوحيد بلا لاحقة `Model` بين 15 |
| 8 | 16 حارس `function_exists()` | 12 منها في `ProductController` — الهيلبرز محمّلة دائماً فالشرط دائماً `true` |
| 9 | 14 استعلام SQL في الكنترولرز | `WishlistController` 6 · `AdminProductsController` 4 · `ProductController` 2 · `CartController` 1 · `AdminSupportController` 1 |
| 10 | `Controller::view()` بلا layouts | يفرض head+navbar+footer دائماً، ويستعمل `require_once` (يمنع عرض view مرتين)، ويستعمل `die()` بدل صفحة خطأ |
| 11 | `app/views/shared/` فيه ملف واحد | لا طبقة partials رغم أن النمط مثبت في `admin/inc/export-csv-button.php` |
| 12 | 579 سطر `<script>` + 55 سطر `<style>` داخل الـviews | `checkout.php` وحده 157+21 |
| 13 | منطق عرض مكرر | `getStockBadge()` موجودة كهيلبر لكن `product_dit.php:150-161` تعيد كتابتها · `$catEmoji` في ملفين · شارة حالة الطلب في 3 views |
| 14 | 10 كنترولرز بلا توثيق OpenAPI | `AuthController` (528) · `CheckoutController` · `MyInfoController` · `ProductController` … |

---

## 3. المبدأ الحاكم للترتيب

**أولاً شبكة أمان، ثم من الأسفل للأعلى حسب اتجاه الاعتماديات.**

```
Views  ──يعتمد على──▶  Controllers  ──يعتمد على──▶  Models  ──▶  Core/Helpers
```

لو نظّفت الـviews أول وبعدها غيّرت شكل البيانات في الكنترولر → بترجع تعيد الـviews.
لهيك: Core/Helpers → Models → Controllers → Views. والـJS آخر شي لأنه مستقل.

**درس من شغل الـCSS:** بنيت أداة A/B بنفسي وقتها، ومسكت عطلاً حقيقياً (تعليق مقطوع أسقط
نظام `.btn-disabled-faded` كاملاً) **قبل** ما ينزل. بدون أداة مماثلة هون، رح تكتشف
الكسرات بالصدفة بعد أسابيع.

---

## 4. المرحلة 0 — شبكة الأمان *(يومان · صفر تغيير سلوك)*

### 4.1 `git init`

```bash
git init
printf 'vendor/\nstorage/\n.env\ncss-backup-2026-08-24/\npublic/docs/\n*.log\n' > .gitignore
git add -A && git commit -m "Baseline: قبل بدء التنظيف"
```

**قرار يحتاج تأكيدك:** هل `.env` يجب أن يبقى خارج git؟ فيه `DB_USERNAME=root` وبيانات
اتصال. توصيتي: نعم، خارج git، مع `.env.example` بديل. قول لي لو عندك رأي مختلف.

بعدها **كل مرحلة = branch مستقل + commit لكل خطوة منطقية**، فتقدر تقارن أو ترجع بأمر واحد.

### 4.2 `scripts/smoke-test.php`

سكربت PHP مستقل (بدون phpunit، بدون composer إضافي) يعمل:

1. يقرأ **44 راوت GET** من `public/index.php` تلقائياً (regex على `$r->get(`) — فما
   بيصير stale لما تضيف راوت.
2. يضرب كل واحد بـ`curl` على `http://localhost/STORE/public`.
3. يفشل لو:
   - كود HTTP مش 200 أو 302 (المتوقّع لصفحات الأدمن بدون جلسة)
   - المخرجات فيها `Fatal error` / `Warning` / `Notice` / `Deprecated`
   - المخرجات فيها `View file [...] not found` أو `Model class [...] not found`
   - الصفحة فاضية (0 بايت) مع كود 200
4. يطبع جدول: راوت · كود · حجم · نتيجة.
5. **`exit(1)` عند أي فشل** — عشان تقدر تربطه لاحقاً بـgit hook.

**التغطية المتوقعة:** 44 راوت GET من أصل 104. الـ60 راوت POST بدها CSRF وجلسة، وما رح
أحاول أزيّفها — بدل هيك، رح أضيف **فحص ثانوي**: `php -l` على كل ملف PHP في المشروع
(88 ملف)، وهذا بيمسك أخطاء الصياغة في مسارات POST كمان.

### 4.3 `scripts/audit.php` — أداة قياس

سكربت يطبع نفس الجداول اللي بهالخطة (أحجام، توثيق OpenAPI، SQL في الكنترولرز، `<script>`
مضمّن، كود ميت). بتشغّله قبل وبعد كل مرحلة فتشوف التقدّم برقم، مش بإحساس.

### ✅ معيار نجاح المرحلة 0
سكربت الدخان يمر بنجاح على الحالة الحالية (baseline أخضر) قبل تعديل أي سطر.

---

## 5. المرحلة 1 — كود ميت وتسميات *(نصف يوم · صفر تغيير سلوك)*

هاي المرحلة كلها **حذف** و**إعادة تسمية**. ما في منطق جديد، وسكربت الدخان بيثبت ذلك.

### 5.1 حذف

| الملف / السطر | الإجراء | لماذا آمن |
|---|---|---|
| `app/core/Model.php` (15 سطر) | **حذف الملف** | صفر ورثة — `grep 'extends Model'` = 0 |
| `app/core/Controller.php` — `model()` (12 سطر) | **حذف الميثود** | `grep '$this->model('` = 0 |
| [public/index.php:20-31](public/index.php:20) | **حذف الـautoloader اليدوي** | composer معرّفه أصلاً؛ سأتحقق بعدها بـ`composer dump-autoload -o` + سكربت الدخان |
| [app/core/AdminController.php:5](app/core/AdminController.php:5) | **حذف `require_once`** | `public/index.php:14` يحمّل كل الهيلبرز |
| 16 حارس `function_exists()` | **تبسيط للاستدعاء المباشر** | ملفاتها محمّلة دائماً |

**استثناءان لا تُحذفان:**
- [app/models/BackupModel.php:58](app/models/BackupModel.php:58) — `function_exists('exec')`
  فحص حقيقي (قد تكون `exec` معطّلة في `php.ini`). **يبقى.**
- [app/helpers/product_variant_helper.php:49](app/helpers/product_variant_helper.php:49) —
  `!function_exists('isUser')`، أراجعها فردياً قبل الحذف.

### 5.2 إعادة تسمية `Product_dit` → `ProductModel`

**14 موقع استدعاء** في 5 كنترولرز + الملف نفسه (10 رسائل `error_log` تحمل الاسم القديم).

الخطوات بالترتيب:
1. `git mv app/models/Product_dit.php app/models/ProductModel.php`
2. تعديل اسم الكلاس + 10 رسائل `error_log`
3. تحديث 4 عبارات `use App\Models\Product_dit;` و14 استدعاء `Product_dit::`
4. تحديث التعليق في [app/models/AdminProductModel.php:11](app/models/AdminProductModel.php:11)
5. `composer dump-autoload -o`
6. سكربت الدخان

**⚠️ ملاحظة:** لو نظام الملفات عندك case-insensitive (Windows افتراضياً)، إعادة التسمية
عبر `git mv` هي الطريقة الصحيحة عشان git يسجّلها كـrename مش delete+add.

### ✅ معيار نجاح المرحلة 1
- سكربت الدخان أخضر
- `php -l` على 88 ملف: صفر خطأ
- `grep -r "Product_dit"` = صفر نتيجة
- عدد الأسطر ينقص ~80 سطر بلا أي تغيير في السلوك

---

## 6. المرحلة 2 — الموديلات *(يومان)*

### 6.1 نقل الـ14 استعلام من الكنترولرز

| الكنترولر | الأسطر | الوجهة |
|---|---|---|
| `WishlistController` | 70, 126, 130, 148, 152, 156 | `StockNotificationModel` (**جديد**) + `ProductModel` |
| `AdminProductsController` | 380, 421, 464, 484 | `AdminProductModel` + `StockNotificationModel` |
| `ProductController` | 29, 153 | `ProductModel` |
| `CartController` | 39 | `ProductModel` |
| `AdminSupportController` | 215 | `SupportModel` |

الاستعلامات الستة في `WishlistController` كلها حول جدول `stock_notifications`، وأربعة
منها مكررة حرفياً في `AdminProductsController` و`ProductController` (نفس
`SELECT id FROM stock_notifications WHERE product_id = ? AND user_id = ?`).
لذلك **موديل جديد `StockNotificationModel`** أنظف من توزيعها.

### 6.2 توحيد أسلوب الموديلات

الـ15 موديل كلهم `static` بالكامل (166 دالة static، صفر instance). هذا **متّسق وشغّال**،
والمشكلة الوحيدة أن وجود `Model` abstract كان يوحي بالعكس — وهو محذوف في المرحلة 1.

**قراري: لا أحوّلهم لـinstance.** التحويل تغيير واسع بلا عائد ملموس هنا (لا يوجد DI
container ولا اختبارات وحدة تحتاج mocking). سأوثّق الاتفاقية بتعليق في كل موديل بدل ذلك.

**لو كنت تفضّل التحويل لـinstance، قول لي** — بس اعرف إنه بيلمس كل الـ24 كنترولر.

### 6.3 تكرار `FROM products`

17 استعلام على جدول `products` موزّعة على 4 موديلات + كنترولر. سأراجعها وأدمج المتطابق
منها فقط — **لن أبني طبقة query builder**، هذا خارج نطاق التنظيف.

### ✅ معيار نجاح المرحلة 2
- `grep -c "prepare(\|->query(" app/controllers/*.php` = **صفر**
- سكربت الدخان أخضر
- فحص يدوي لـ: الويش ليست، "نبّهني عند التوفر"، السلة، رد الدعم

---

## 7. المرحلة 3 — الكنترولرز *(3-4 أيام)*

### 7.1 استخراج طبقة `app/services/`

الميثودات الطويلة في `AdminProductsController` (`storeAdd` 104 · `storeEdit` 107) طويلة
بسبب **التحقق المضمّن**، مش بسبب خلط المسؤوليات — الكود بيفوّض فعلاً للموديل.

| الخدمة الجديدة | تجمع من |
|---|---|
| `ProductImageService` | `parseAndUploadVariants` · `extractFileEntry` · `cleanupUploadedImages` (87 سطر من `AdminProductsController`) |
| `StockNotifier` | `notifyUsersProductBackInStock` · `checkAndNotifyOutOfStock` (86 سطر) + المكرر في `WishlistController` |
| `AdminAuditLogger` | `notifyProductChange` + استدعاءات `AdminModel::logAction` المكررة عبر 6 كنترولرز |

### 7.2 طبقة تحقق خفيفة

بدل `if (empty($x)) $this->jsonError('...')` مكررة عشرات المرات، هيلبر واحد:

```php
$data = validate($_POST, [
    'name'         => 'required|string|max:255',
    'category_ids' => 'required|array|min:1',
]);
```

~120 سطر لملف الـvalidator، وبيشيل ~200 سطر تحقق مكرر. **هذا أكبر مكسب مفرد في هذه المرحلة.**

### 7.3 إكمال توثيق OpenAPI

الـ10 كنترولرز بلا توثيق — أضيف `#[OA\...]` لها بنفس نمط الموجود، ثم
`composer docs:generate`. **هذا يزيد عدد الأسطر** (~400 سطر توثيق) وهو مقصود: التوثيق
الناقص أسوأ من التوثيق الطويل.

**⚠️ قرار لك:** لو ما بتستخدم `openapi.yaml` فعلياً، البديل المعاكس هو **حذف** التوثيق
الموجود (1436 سطر) بدل إكماله. قول لي أي اتجاه.

### ✅ معيار نجاح المرحلة 3
- ولا ميثود عام فوق 60 سطر كود فعلي
- سكربت الدخان أخضر + فحص يدوي لكل مسار POST في الأدمن (إضافة/تعديل/حذف منتج، الطلبات، المستخدمين)

---

## 8. المرحلة 4 — الـCore *(يوم واحد)*

### 8.1 `Controller::view()` — دعم layouts

الحالة: يفرض `head + navbar + view + footer` بشكل مثبّت. **النتيجة:** 4 صفحات
(`admin/login.php`, `store-reauth.php`, `auth/reset-password.php`, وجزئياً
`confirmation.php`) مضطرة تكتب `<!DOCTYPE html>` كامل بإيدها.

**النموذج موجود أصلاً** في `AdminController::adminView()` — سأوحّدهما:

```php
protected function view(string $view, array $data = [], string $layout = 'store'): void
// 'store' | 'admin' | 'bare'
```

- `require_once` → `require` (الأولى تمنع عرض نفس الـview مرتين — عطل كامن)
- `die("View file not found")` → صفحة 404 حقيقية + `error_log`

### 8.2 الهيلبرز

429 سطر عبر 6 ملفات — **الطبقة الأنظف في المشروع.** التعديل الوحيد: نقل الهيلبرز من
`glob()` في [public/index.php:14](public/index.php:14) إلى `composer.json` تحت
`autoload.files`، فيصير تحميلها موثّقاً وقابلاً للتحسين بـ`dump-autoload -o`.

### ✅ معيار نجاح المرحلة 4
- الصفحات الأربع تستعمل `layout: 'bare'` بدل HTML مكتوب يدوياً
- سكربت الدخان أخضر
- مقارنة بصرية قبل/بعد للصفحات الأربع

---

## 9. المرحلة 5 — الـViews *(3-4 أيام)*

### 9.1 طبقة partials

`app/views/shared/` فيه **ملف واحد**. النمط مثبت وشغّال في
`admin/inc/export-csv-button.php`. المرشحون:

| Partial جديد | يُستخدم في |
|---|---|
| `shared/product-card.php` | `product.php` · نتائج البحث |
| `shared/rating-stars.php` | `product_dit.php` (3 مواضع) |
| `shared/order-status-badge.php` | `account/my-info.php` · `admin/orders/index.php` · `admin/orders/details.php` |
| `shared/category-buttons.php` | `home.php` · `product.php` (`$catEmoji` مكرر حرفياً) |
| `shared/stock-badge.php` | يستدعي `getStockBadge()` الموجودة — ويحذف النسخة اليدوية في [product_dit.php:150-161](app/views/product/product_dit.php:150) |

### 9.2 إخراج `<script>` و `<style>` المضمّنة

**579 سطر JS + 55 سطر CSS** داخل الـviews:

| الملف | script | style | الوجهة |
|---|---:|---:|---|
| `checkout/checkout.php` | 157 | 21 | `js/features/checkout.js` + الـ`<style>` يُدمج في `store/pages/checkout.css` |
| `admin/my-info.php` | 144 | 0 | `js/admin/my-info.js` |
| `admin/store-reauth.php` | 63 | 0 | `js/admin/store-reauth.js` |
| `auth/reset-password.php` | 58 | 25 | `js/features/reset-password.js` + CSS |
| باقي الـ17 ملف | 157 | 9 | حسب الحالة |

**`checkout.php` أولوية:** تعليق داخل `store/pages/checkout.css` يشرح صراحةً أن كل قاعدة
هناك محتاجة `!important` لأنها تتصارع مع `<style>` مضمّن في الـview. حلّ هذا يشيل سبب
تلك الـ`!important`ات.

### 9.3 تمرير البيانات عبر `data-*` بدل JS مضمّن

الـ`<script>` المضمّنة أغلبها موجودة عشان تحقن قيم PHP في JS. الحل القياسي:
`<div id="checkoutRoot" data-cart='<?= htmlspecialchars(json_encode($cart)) ?>'>`
والـJS يقراها من `dataset`.

### 9.4 تصعيد الهروب (escaping)

**120 موقع `<?= ?>` بلا `htmlspecialchars`.** أغلبها أعداد صحيحة آمنة، لكن منها قيم من
قاعدة البيانات (`$order['status']`, `$broadcastTargetType`, `$tiles[0]['color']`).
سأمرّ عليها واحداً واحداً وأصنّفها: آمن (int/enum) · يحتاج هروب · يحتاج `json_encode`.

### ✅ معيار نجاح المرحلة 5
- صفر `<style>` داخل أي view
- `<script>` مضمّن فقط حيث يمرّر بيانات (وليس منطقاً)
- **مقارنة A/B بصرية** بنفس أداة شغل الـCSS، على 3 مقاسات، للصفحات المتأثرة

---

## 10. المرحلة 6 — الـJS *(2-3 أيام)*

4940 سطر عبر 28 ملف، البنية (`core/` `features/` `admin/` `shared/`) سليمة أصلاً.
الفحص لسا ما تم بعمق — سأعمل تدقيقاً منفصلاً قبل اقتراح خطة، بس المؤشرات الأولية:
`admin/products.js` (369) و`features/auth.js` (336) و`features/notifications.js` (330)
هي الأكبر، ويُضاف لها الـ579 سطر القادمة من المرحلة 5.

---

## 11. الجدول الزمني والمخاطر

| المرحلة | مدة | مخاطرة | قابلية التراجع |
|---|---|---|---|
| 0 — شبكة أمان | يومان | **صفر** | — |
| 1 — كود ميت | نصف يوم | **منخفضة جداً** | git |
| 2 — موديلات | يومان | متوسطة | git branch |
| 3 — كنترولرز | 3-4 أيام | **عالية** | git branch |
| 4 — core | يوم | متوسطة | git branch |
| 5 — views | 3-4 أيام | متوسطة | git branch |
| 6 — JS | 2-3 أيام | متوسطة | git branch |

### المخاطر المعلَنة

| الخطر | الاحتمال | الضمانة |
|---|---|---|
| مسارات POST غير مغطاة بسكربت الدخان (60 راوت) | **عالٍ** | فحص يدوي مُوجَّه في نهاية كل مرحلة + `php -l` شامل |
| المرحلة 3 تلمس منطق الأدمن الحسّاس (رفع صور، صلاحيات) | عالٍ | branch مستقل، commit لكل خدمة مستخرجة، ولا أدمج قبل فحصك |
| إعادة تسمية `Product_dit` على نظام ملفات case-insensitive | متوسط | `git mv` + `composer dump-autoload -o` + تحقق آلي |
| تغيير `require_once` → `require` في `view()` قد يكشف عرضاً مزدوجاً كامناً | منخفض | سكربت الدخان يقرأ حجم كل صفحة — التكرار يظهر كقفزة في الحجم |
| الـ`<style>` المضمّن في `checkout.php` قد يكون يعتمد عليه JS | متوسط | أقرأ `checkout.js` قبل النقل |

---

## 12. ما أحتاجه منك قبل البدء

1. **موافقة** على الخطة (أو تعديلاتك).
2. **الأذونات** من القسم 0.
3. **XAMPP شغّال** (Apache + MySQL).
4. **ثلاثة قرارات:**
   - **`.env` خارج git؟** (توصيتي: نعم، مع `.env.example`)
   - **توثيق OpenAPI: أُكمله للـ10 كنترولرز الناقصة، أم أحذفه كله؟** (توصيتي: أكمله لو
     بتستخدم `openapi.yaml`؛ احذفه لو لأ — الوضع الحالي أسوأ الخيارين)
   - **الموديلات: أتركها static أم أحوّلها لـinstance؟** (توصيتي: اتركها static)
5. **هل أنفّذ المراحل بالتسلسل بلا توقف، أم أتوقف بعد كل مرحلة لمراجعتك؟**
   (توصيتي: بلا توقف حتى نهاية المرحلة 1، ثم مراجعة، ثم بلا توقف حتى نهاية المرحلة 3)

---

## 13. توصيتي المختصرة

**ابدأ بالمرحلتين 0 و1 معاً في جلسة واحدة.** يومان ونصف، صفر تغيير في السلوك، وبتطلع
منهم بـ:
- مستودع git تقدر ترجع منه بأي لحظة
- سكربت يفحص 44 راوت بأمر واحد
- ~80 سطر كود ميت محذوف
- تسمية متّسقة عبر كل الموديلات

وبعدها تقرر إذا بدك تكمل — وأنت مرتاح لأن أي شي بعدها صار قابلاً للتراجع.
