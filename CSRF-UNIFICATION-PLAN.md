# خطة توحيد مقدّمة نقاط JSON — الـ34 موضعاً المتبقية

> **الحالة:** خطة فقط — لم يُعدَّل أي ملف. بانتظار موافقتك.
> **التاريخ:** 2026-08-26 · **الفرع المقترح:** `cleanup/csrf-preamble-unification`

---

## 0. تصحيحان قبل كل شيء

أثناء إعداد هذه الخطة سقط ادعاءان قلتُهما لك في التتبّع، وأصحّحهما هنا لأنهما يغيّران
المبرّر:

**1. «النقاط الثماني بلا فحص `REQUEST_METHOD` تقبل GET» — خطأ.**
`Router::dispatch()` يطابق الطريقة والمسار معاً ([Router.php:74](app/Core/Router.php:74))،
وGET على مسار مسجَّل كـPOST يسقط إلى `ErrorPage::notFound`. اختبرتُ ثلاث نقاط عبر HTTP
فرجعت كلها صفحة 404. إذن الفحص داخل الكنترولر **زائد لا ناقص**، ولا ثغرة سلوكية هنا.

**النتيجة:** هذه المرحلة **تنظيف اتساق فقط، لا إصلاح أمني.** لو كنت تبحث عن مكسب أمني،
فهو في القسم 6 لا هنا.

**2. توحيد نصّ الرسالة ليس تجميلياً — وله عميل حقيقي.**
`fetchWithCsrfRetry` تكتشف فشل CSRF بـ`data.message.startsWith('Invalid CSRF token')`
([csrf.js:121](public/js/core/csrf.js:121)). الصياغات الثلاث الحالية كلها تبدأ بهذه
البادئة، فالتوحيد آمن — لكنه يعني أن **آلية إعادة المحاولة مربوطة بنصّ رسالة**، وهو
اقتران هشّ يستحق قراراً مستقلاً (القسم 6).

---

## 1. الحالة المقيسة

| المسار | المواضع |
|---|---:|
| عبر `Controller::beginJsonPost()` | **18** |
| استدعاء `verifyCsrfToken()` مباشرةً | **34** |
| نقاط POST في الراوتر | 60 |

### توزيع الـ34 حسب رسالة الفشل الفعلية

| الرسالة | العدد |
|---|---:|
| `Invalid CSRF token, please refresh and try again.` ← الموحّدة | **23** |
| `Invalid CSRF token.` | 5 |
| `Invalid CSRF token, please try again.` | 1 |
| بلا `respond` مباشر (بنية مختلفة) | 5 |

**لماذا لم تُحوَّل في المرحلة 3:** سكربت التحويل كان يطابق **التسلسل الثلاثي الكامل**
(رأس JSON → فحص الطريقة → CSRF) كوحدة واحدة بالرسائل نفسها. أي غياب لأحد الثلاثة، أو أي
سطر يتخلّلها، يُسقط المطابقة. لم يكن قراراً بأن الكود يستحق البقاء.

---

## 2. التصنيف النهائي

### أ — تُحوَّل بلا أي تغيير سلوك (23 موضعاً)

رسالتها الموحّدة نفسها، وتفشل بـ`respond`/`jsonError`، ورأس JSON موجود.

| الملف | الدوال |
|---|---|
| `AdminAuthController` | `login` · `verify2FALogin` · `forgotPassword` |
| `AdminManageAdminsController` | `storeAdd` · `storeEdit` |
| `AdminMessagingController` | `notify` · `broadcast` |
| `AdminMyInfoController` | `updateProfile` · `confirm2FA` · `disable2FA` |
| `AdminProductsController` | `storeAdd` · `storeEdit` · `addCategory` · `deleteCategory` |
| `AdminSiteSettingsController` | `save` |
| `AdminSupportController` | `reply` · `delete` |
| `AuthController` | `login` · `register` · `forgot` |
| `MyInfoController` | `updateProfile` |
| `AdminBrandingController` | `save` ← **استُبعد**، انظر (ج) |

⚠️ **`AdminProductsController::storeAdd`/`storeEdit` تستعملان `jsonError` لا `respond`.**
النتيجة متطابقة (`jsonError` تفوّض لـ`respond(false, …)`)، لكن السكربت يجب أن يطابق
الشكلين.

### ب — تُحوَّل بعد توحيد نصّ الرسالة (6 مواضع)

| الرسالة الحالية | الدوال |
|---|---|
| `Invalid CSRF token.` | `AuthController::resetSubmit` · `CheckoutController::placeOrder` · `CheckoutController::cancelOrder` · `MyInfoController::addAddress` · `MyInfoController::deleteAddress` |
| `Invalid CSRF token, please try again.` | `AdminAuthController::reauth` |

**آمنة:** الصياغتان تبدآن بـ`Invalid CSRF token`، فشرط `startsWith` في `csrf.js` يظل
صادقاً قبل التوحيد وبعده. **يتغيّر النص المرئي للمستخدم فقط.**

### ج — تُستبعد بسبب بنيوي (5 مواضع)

| الموضع | السبب |
|---|---|
| `AdminBrandingController::save` | تفشل بـ`redirectWithError` لا JSON — صفحة لا نقطة API |
| `AdminAuthController::enterStoreMode` | تفشل بـ`header('Location: …')` |
| `ProductController::show` | لا تفشل أصلاً: تضع النص في `$reviewErr` وتُكمل عرض الصفحة. الدالة تخدم GET وPOST معاً |
| `ContactController::contact` | نفس نمط `show` |
| `AdminMyInfoController::generate2FASecret` | **قابلة للتحويل** بعد إضافة رأس JSON — قرار مستقل |

`ContactController::send` تحتاج قراءة يدوية قبل التصنيف (المصنِّف لم يحسم بنيتها).

---

## 3. المطلوب من `beginJsonPost()` — تعديل واحد

الحالية تفرض الثلاثة معاً. المواضع الـ8 بلا فحص `REQUEST_METHOD` ستكتسبه عند التحويل —
وهو **تشديد**، لا كسر، لأن الراوتر يمنع GET أصلاً فلا مسار حقيقي يتأثر.

لكن ليكون التحويل **صفر تغيير سلوك** بالمعنى الحرفي، أقترح وسيطاً ثانياً:

```php
protected function beginJsonPost(bool $requireCsrf = true, bool $requirePost = true): void
```

`requirePost: false` للمواضع التي لم يكن فيها الفحص، فيبقى السلوك مطابقاً بايت ببايت.

**توصيتي: لا تستعمله.** أضِف الفحص للجميع — الراوتر يحميهم أصلاً، والفحص المزدوج طبقة
دفاع رخيصة، ووسيط ثالث يجعل الدالة تشرح استثناءات لا قاعدة. الوسيط مذكور هنا كخيار لو
فضّلت التطابق الحرفي.

---

## 4. خطوات التنفيذ

| # | الخطوة | التحقق |
|---|---|---|
| 1 | `git checkout -b cleanup/csrf-preamble-unification` · `php scripts/smoke-test.php` باعتباره baseline | 44/44 أخضر |
| 2 | توسيع سكربت التحويل ليطابق: الشكلين `respond`/`jsonError` · الترتيبين · وجود أو غياب `REQUEST_METHOD` · وجود أو غياب `requirePermission` بينها | يطبع كل موضع حوّله |
| 3 | تطبيقه على الفئة (أ) — 22 موضعاً بعد استبعاد `AdminBranding::save` | `grep -c beginJsonPost` = 40 |
| 4 | commit مستقل | الدخان أخضر |
| 5 | توحيد نصّ الرسالة في الفئة (ب) — 6 مواضع — ثم تحويلها | `grep` = صياغة واحدة في المشروع |
| 6 | commit مستقل | الدخان أخضر |
| 7 | قراءة يدوية لـ`ContactController::send` و`generate2FASecret` وقرار لكل منهما | موثَّق بتعليق |
| 8 | تعليق في الفئة (ج) يشرح **لماذا** استُبعد كل موضع، كي لا يعيد أحد فتح السؤال | 4 تعليقات |

### التحقق الإلزامي بعد كل commit

```bash
php scripts/smoke-test.php && php scripts/audit-imports.php
```

**وسكربت الدخان لا يغطي POST.** لكل فئة، اختبار HTTP بجلسة مزوّرة على القرص (الطريقة في
`HANDOFF-PHASE-4-6.md` القسم 4.7) على **ثلاث نقاط على الأقل**:

- POST بلا CSRF → نفس الرسالة ونفس الشكل
- POST بـCSRF صحيح → يصل للمنطق (رسالة تحقق لاحقة)
- بلا جلسة → 302 لتسجيل الدخول

ونقطة من الفئة (ج) للتأكد أن سلوكها **لم** يتغيّر.

---

## 5. المخاطر

| الخطر | الاحتمال | الضمانة |
|---|---|---|
| السكربت يطابق أوسع مما ينبغي فيبتلع منطقاً بين الكتل | **متوسط** | يطبع كل موضع قبل الكتابة · commit مستقل لكل فئة · `git diff` يُراجَع قبل الدمج |
| `preg_replace` يُرجع `null` فيُفرَّغ ملف | منخفض بعد الحيطة | `if ($new === null) لا تكتب` — هذا العطل وقع فعلاً في سيشن سابقة |
| نهايات CRLF تُسقط المطابقة | **عالٍ** | تطبيع للقراءة وإعادة النهايات عند الكتابة |
| تغيير نص الرسالة يكسر شيئاً | **منخفض** | `startsWith` في `csrf.js` يغطي الصياغات الثلاث — تحققتُ منه |
| موضع في (ج) يُحوَّل سهواً فتصير صفحة JSON | متوسط | قائمة استبعاد صريحة في السكربت بالملف والدالة |

---

## 6. ما هذه الخطة **لا** تصلحه — وهو الأهم

توحيد الـ34 **لا يغلق أي ثغرة**. البندان التاليان يغلقان، وكلاهما مستقل عن هذه الخطة:

### 6.1 نقطتا تسجيل الخروج بلا تحقق CSRF

`AuthController::logout` و`AdminAuthController::logout` تستدعيان `session_destroy()`
مباشرةً بلا `verifyCsrfToken` — تحققتُ من الكود. أي `<img src="…/auth/logout">` في صفحة
يزورها مستخدمك يسجّل خروجه.

خطورة منخفضة (لا سرقة بيانات ولا تغيير حالة دائم)، لكنها **الثغرة الوحيدة الحقيقية** التي
خرجت من التتبّع. الإصلاح ~6 أسطر، ويستلزم أن يرسل الـJS التوكن — وهو يرسله فعلاً في
`admin-navbar.js` ولا يرسله في `features/auth.js`.

### 6.2 إعادة المحاولة مربوطة بنصّ رسالة

`csrf.js` يكتشف الفشل بـ`startsWith('Invalid CSRF token')`. أي تعديل على الصياغة مستقبلاً
يعطّل إعادة المحاولة **بصمت**. البديل: حقل صريح في الاستجابة (`error_code: 'csrf_invalid'`)
مع إبقاء النص للعرض فقط.

هذا يغيّر عقد الاستجابة، فهو قرارك.

### 6.3 `Database.php:35`

`die()` يطبع رسالة خطأ قاعدة البيانات الخام — آخر موضع نجا من تنظيف المرحلة 4.

---

## 7. ما أحتاجه منك

1. **موافقة** على الفئات (أ) و(ب) و(ج).
2. **وسيط `requirePost`** — أُضيفه للتطابق الحرفي، أم أشدّد الجميع؟ (توصيتي: شدّد)
3. **الرسالة الموحّدة** — أُبقي `Invalid CSRF token, please refresh and try again.`؟
4. **هل أضمّ البند 6.1** (خروج بلا CSRF) لهذه المرحلة أم أفرده؟

**تقديري:** الفئة (أ) نصف يوم · (ب) ساعتان · (ج) والتوثيق ساعة · الاختبار ساعتان.
