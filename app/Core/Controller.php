<?php

namespace App\Core;

abstract class Controller
{
    /** الـlayouts المدعومة — أي قيمة أخرى خطأ برمجي لا خيار وقت تشغيل. */
    private const LAYOUTS = ['store', 'admin', 'bare'];

    /**
     * يعرض view داخل أحد ثلاثة layouts.
     *
     * ┌─────────┬──────────────────────────────────────────────────┐
     * │ store   │ inc/head + inc/navbar + view + inc/footer        │
     * │ admin   │ admin/inc/head + admin/inc/navbar + view + …     │
     * │ bare    │ الـview وحده — صفحة مستقلة تبني وسومها بنفسها     │
     * └─────────┴──────────────────────────────────────────────────┘
     *
     * صفحات `bare` (تسجيل دخول الأدمن، إعادة المصادقة، إعادة تعيين كلمة
     * المرور) تستعمل `inc/head-bare.php` و`inc/footer-bare.php` بدل أن
     * تكتب `<!DOCTYPE html>` بيدها.
     *
     * @param string $view   مسار الـview تحت app/views بلا امتداد،
     *                       مثل 'home' أو 'admin/orders/index'
     * @param array<string, mixed> $data متغيرات تُستخرج للـview ولملفات الـlayout
     * @param string $layout 'store' | 'admin' | 'bare'
     */
    protected function view(string $view, array $data = [], string $layout = 'store'): void
    {
        if (!in_array($layout, self::LAYOUTS, true)) {
            throw new \InvalidArgumentException(
                "Unknown layout [{$layout}]. Expected: " . implode(' | ', self::LAYOUTS)
            );
        }

        // المتغيرات المحلية مسبوقة بـ__ لأن extract($data) أدناه يكتب في
        // نفس النطاق — مفتاح اسمه view أو layout كان سيدهسها.
        $__layout   = $layout;
        $__viewFile = APPROOT . '/views/' . $view . '.php';

        // الفحص قبل أي إخراج. النسخة القديمة كانت تُخرج head و navbar ثم
        // تكتشف غياب الـview، فيستحيل عندها إرسال كود 404 (الترويسات
        // أُرسلت أصلاً) وتخرج نصف صفحة مكسورة.
        if (!is_file($__viewFile)) {
            ErrorPage::notFound('view مفقود: ' . $__viewFile);
        }

        // نبضة نشاط المستخدم — مخنوقة إلى مرة كل 15 دقيقة (راجع
        // touchUserActivity في auth_helper.php). محصورة في layout المتجر
        // عمداً: جلسة الأدمن منفصلة الاسم والمحتوى ونشاطها متتبَّع في
        // AdminModel. ولا تنقلها إلى الـbootstrap — هناك تبدأ جلسة
        // PHPSESSID قبل أن تضبط startAdminSession اسم جلسة الأدمن.
        if ($__layout === 'store') {
            touchUserActivity();
        }

        // استخراج المتغيرات من مصفوفة $data لتصبح جاهزة للطباعة
        extract($data);

        // require لا require_once: الأخيرة تمنع عرض نفس الملف مرتين في
        // الطلب الواحد، وهو عطل كامن يظهر لحظة أن يعرض view نفس الـpartial
        // مرتين. layout الأدمن يستعمل require منذ البداية بلا مشاكل.
        switch ($__layout) {
            case 'store':
                require APPROOT . '/views/inc/head.php';
                require APPROOT . '/views/inc/navbar.php';
                require $__viewFile;
                require APPROOT . '/views/inc/footer.php';
                break;

            case 'admin':
                require APPROOT . '/views/admin/inc/head.php';
                require APPROOT . '/views/admin/inc/navbar.php';
                require $__viewFile;
                require APPROOT . '/views/admin/inc/footer.php';
                break;

            case 'bare':
                require $__viewFile;
                break;
        }
    }

    // ملاحظة: صفحة الـ404 نفسها في App\Core\ErrorPage — لا هنا. كانت
    // نسخة محلية في هذا الكلاس، لكن Router يحتاجها أيضاً وهو لا يرث
    // Controller (ولا يجب أن يرثه)، فكانت ستُنسخ مرتين.

    // ═══════════════════════════════════════════════════════════
    // استجابات JSON — مشتركة بين كل الكنترولرز
    // ═══════════════════════════════════════════════════════════
    //
    // كانت respond() منسوخة حرفياً في 16 كنترولر (نسختان تختلفان في
    // المسافات فقط). نُقلت هنا مرة واحدة: كنترولرز المتجر ترثها مباشرة،
    // وكنترولرز الأدمن عبر AdminController الذي يرث هذا الكلاس.

    /**
     * يطبع استجابة JSON موحّدة الشكل ويوقف التنفيذ.
     *
     * الشكل ثابت: {success, message, ...$extra}. الـfrontend يعتمد عليه
     * في js/core/utils.js وبقية ملفات features، فلا تُغيَّر أسماء المفاتيح.
     *
     * ملاحظة: لا يضبط رأس Content-Type — بعض النقاط تضبطه بنفسها قبل
     * الاستدعاء، وبعضها يستدعي respond() بعد إخراج بدأ فعلاً.
     *
     * @param array<string, mixed> $extra
     */
    protected function respond(bool $success, string $message, array $extra = []): never
    {
        echo json_encode(
            array_merge(['success' => $success, 'message' => $message], $extra),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * اختصار لاستجابة فشل بلا بيانات إضافية.
     */
    protected function jsonError(string $message): never
    {
        $this->respond(false, $message);
    }

    /**
     * مقدّمة أي نقطة JSON تستقبل POST: تضبط رأس الاستجابة، وترفض غير
     * POST، وتتحقق من توكن CSRF — بالرسائل نفسها التي كانت مكتوبة يدوياً
     * في عشرات المواضع.
     *
     * كانت هذه الأسطر الثلاثة تُكرَّر بنفس الصياغة حرفياً: 61 مرة لضبط
     * الرأس، و40 مرة لفحص الطريقة، و24 مرة لفحص CSRF بنفس الرسالة.
     *
     * ترتيب الفحوص مقصود: الرأس أولاً كي تكون رسالة الرفض نفسها JSON،
     * ثم الطريقة، ثم CSRF — لأن التحقق من التوكن بلا جلسة POST بلا معنى.
     *
     * ملاحظة: هذه للنقاط التي تُرجع JSON فقط. الصفحات التي تحوّل
     * بـredirect عند الفشل (مثل AdminBrandingController::save) لها
     * معالجتها الخاصة ولا تستعمل هذه.
     *
     * فشل CSRF يحمل error_code صريحاً (ERR_CSRF_INVALID). js/core/csrf.js
     * يكتشفه به ليجلب توكناً جديداً ويُعيد المحاولة مرة واحدة — والرسالة
     * صارت للعرض وحدها.
     *
     * @param bool $requireCsrf مرّر false للنقاط العامة التي لا تملك
     *                          توكناً بعد (مثل جلب التوكن نفسه).
     */
    protected function beginJsonPost(bool $requireCsrf = true): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        if ($requireCsrf && !verifyCsrfToken($this->requestData()['csrf_token'] ?? '')) {
            $this->respondCsrfFailure();
        }
    }

    /**
     * رمز فشل CSRF — عقد بين الخادم و js/core/csrf.js.
     *
     * لماذا رمز لا نصّ؟ كان الغلاف يكتشف الفشل بـ
     *     message.startsWith('Invalid CSRF token')
     * فكانت أي نقطة تصوغ رسالتها بشكل آخر تفقد إعادة المحاولة **بصمت**.
     * حدث ذلك فعلاً ثلاث مرات: WishlistController::notify
     * ('Invalid session…') و ContactController::send ('Invalid request…')
     * وست نقاط نجت بالصدفة لأن صياغتها بدأت بالبادئة نفسها.
     *
     * الرمز يفصل ما تقرأه الآلة عمّا يقرأه المستخدم: النصّ حرّ يتغيّر
     * بحرية، والرمز ثابت.
     */
    public const ERR_CSRF_INVALID = 'csrf_invalid';

    /**
     * استجابة فشل CSRF الموحّدة. تُستدعى من beginJsonPost ومن النقاط
     * القليلة التي لا تمرّ بها لكنها تُرجع JSON.
     *
     * @param array<string, mixed> $extra بيانات إضافية — reauth مثلاً تُعيد توكناً جديداً
     */
    protected function respondCsrfFailure(array $extra = []): never
    {
        $this->respond(
            false,
            'Invalid CSRF token, please refresh and try again.',
            array_merge(['error_code' => self::ERR_CSRF_INVALID], $extra)
        );
    }

    /**
     * يفحص مدخلات الطلب ويُرجع القيم المطبَّعة — أو يردّ بأول خطأ ويخرج.
     *
     * سطران يربطان Validator بالطلب:
     *
     *     $input = $this->validate([
     *         'full_address' => 'required|string|min:5|max:255',
     *         'label'        => 'string|default:Home',
     *         'city'         => 'nullable|string',
     *     ]);
     *
     * ── لماذا يردّ ويخرج بدل أن يُرجع نتيجة ─────────────────
     *
     * لأن هذا **هو السلوك القائم حرفياً**. كل فعل اليوم يكتب:
     *
     *     if (!$full) { $this->respond(false, 'Full address is required.'); }
     *
     * و respond تُنهي الطلب. فالتحويل لا يغيّر شيئاً في التدفّق — يوحّد
     * صياغته فقط. ولو أُرجعت نتيجة لوجب على كل مستدعٍ أن يتذكّر فحصها،
     * وهو بالضبط ما يُنسى.
     *
     * ⚠️ يجب أن تُستدعى **بعد** beginJsonPost: تلك تضبط رأس JSON، وبلا
     * الرأس تصل رسالة الخطأ نصّاً خاماً لا يقرأه العميل.
     *
     * ومنطق التحقّق نفسه في App\Core\Validator وهو نقيّ تماماً — لا
     * يقرأ الطلب ولا يطبع — فيُختبَر بلا خادم ولا جلسة.
     *
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    protected function validate(array $rules): array
    {
        $validator = (new Validator($this->requestData()))->check($rules);

        if ($validator->fails()) {
            $this->respond(false, (string) $validator->firstError());
        }

        return $validator->validated();
    }

    /**
     * مدخلات الطلب موحّدة: $_POST مدموجاً بجسم JSON إن وُجد.
     *
     * لماذا؟ جزء من نقاط المشروع يرسل FormData وجزء يرسل JSON
     * (cart.js و account.js مثلاً يرسلان JSON عبر fetchWithCsrfRetry).
     * النقاط التي تستقبل JSON كانت تبني `array_merge($_POST, $body)`
     * بنفسها ثم تقرأ التوكن منه — تسع نقاط تكرّر السطرين نفسيهما.
     *
     * وهذا ما كان يمنع توحيدها: beginJsonPost كانت تقرأ من $_POST وحده،
     * فتحويلها كان سيكسر كل نقطة يصل توكنها في جسم JSON.
     *
     * النتيجة تُحسب مرة واحدة لكل طلب: php://input تيّار يُقرأ مرة، وقد
     * تُستدعى هذه من beginJsonPost ثم من جسم الدالة.
     *
     * @return array<string,mixed>
     */
    protected function requestData(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $body = json_decode(file_get_contents('php://input') ?: '', true);
        return $cache = array_merge($_POST, is_array($body) ? $body : []);
    }
}
