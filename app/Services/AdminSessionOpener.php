<?php

namespace App\Services;

use App\Core\Mailer;
use App\Core\Throttle;
use App\Models\AdminModel;

/**
 * AdminSessionOpener — كل ما يحدث لحظة اكتمال دخول الأدمن.
 *
 * وُجدت لأن هذه الأسطر كانت مكتوبة **مرّتين حرفياً**: في
 * AdminAuthController::login (للحساب بلا مصادقة ثنائية) وفي
 * verify2FALogin (بعد الكود الصحيح). والتعليق فوق الثانية كان يقول
 * ذلك صراحةً: «نفس الكود المستخدم بعد نجاح كلمة المرور».
 *
 * الازدواج هنا أخطر منه في أي موضع آخر، لأن ما يُنسى في إحدى النسختين
 * لا يظهر كخطأ بل كفجوة: مسار دخول لا يُدوّر معرّف الجلسة، أو لا يحمّل
 * الصلاحيات، أو لا يكتب في سجلّ التدقيق. ثلاثتها صامتة.
 *
 * وقد كاد ذلك يحدث فعلاً في هذه الجولة: مسح عدّاد الخنق أُضيف إلى
 * المسارين يدوياً، وكان يكفي أن يُنسى أحدهما ليدفع من دخل عبر المصادقة
 * الثنائية ثمن محاولاته في المرّة التالية.
 *
 * ⚠️ الخدمة لا تعرف شيئاً عن HTTP: لا ترد ولا تُنهي الطلب ولا تلمس
 * توكن CSRF. الكنترولر يبقى صاحب الاستجابة، وهذه تفتح الجلسة وحدها.
 */
final class AdminSessionOpener
{
    /**
     * يفتح جلسة أدمن كاملة، ويسجّل، ويُشعر صاحبها.
     *
     * ترتيب الخطوات مقصود:
     *   1. تدوير معرّف الجلسة **قبل** كتابة أي شيء فيها — وإلا كُتبت
     *      الهوية في المعرّف القديم الذي قد يعرفه مهاجم (تثبيت جلسة).
     *   2. الصلاحيات بعد الهوية، لأنها تُقرأ بالمعرّف.
     *   3. البريد أخيراً وعبر الطابور — لا ينتظره الداخل.
     *
     * @param array $admin صفّ الأدمن كما تُرجعه AdminModel
     */
    public static function open(array $admin): void
    {
        $adminId = (int) $admin['id'];

        session_regenerate_id(true);

        // التوكن يتبع المعرّف. regenerate_id تُبقي محتوى الجلسة — ومنه
        // csrf_token — فتوكنُ صفحة دخول الأدمن (وهي صفحة عامّة يصلها
        // أي أحد) كان يبقى صالحاً بحرفه داخل جلسة أدمن كاملة الصلاحية.
        // هنا أخطر موضع لهذا التوريث في المشروع كلّه.
        rotateCsrfToken();

        $_SESSION['admin_id']    = $adminId;
        $_SESSION['admin_name']  = $admin['full_name'] ?? $admin['name'] ?? 'Admin';
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_role']  = $admin['role'] ?? 'B';
        $_SESSION['last_active'] = time();

        loadAdminPermissions($adminId);
        AdminModel::updateActivity($adminId);
        AdminModel::logAction($adminId, 'login');

        self::clearThrottleBuckets();
        self::sendLoginAlert($admin);
    }

    /**
     * يمسح عدّادات الخنق التي عبَرها هذا الدخول.
     *
     * الدخول اكتمل، فلا معنى لأن يدفع صاحبه ثمن محاولاته الفاشلة في
     * المرّة القادمة — ومن نحرس منه لا يصل إلى هنا أصلاً.
     *
     * الدلوان معاً دائماً: من دخل بلا مصادقة ثنائية لم يلمس دلو 2FA،
     * ومسحه لا يضرّ؛ أمّا نسيان أحدهما فيترك نصف الأثر.
     */
    private static function clearThrottleBuckets(): void
    {
        $ip = Throttle::clientIp();
        Throttle::clear('admin-login', $ip);
        Throttle::clear('admin-2fa', $ip);
    }

    /**
     * إيميل تنبيه بدخول جديد.
     *
     * القيم نائبات لا نصّ محقون: HTTP_USER_AGENT ترويسة يتحكّم بها
     * المرسِل كلياً، وحقنها المباشر كان يوصل HTML يكتبه المهاجم إلى
     * صندوق بريد الأدمن. Mailer::template تهرّب كل نائبة.
     */
    private static function sendLoginAlert(array $admin): void
    {
        Mailer::queue(
            $admin['email'],
            $admin['full_name'] ?? 'Admin',
            'تسجيل دخول جديد لحسابك',
            Mailer::template(
                'تسجيل دخول جديد',
                'تم تسجيل دخول جديد لحساب الأدمن الخاص بك.<br><br>'
                . '<b>الوقت:</b> {time}<br>'
                . '<b>عنوان IP:</b> {ip}<br>'
                . '<b>الجهاز/المتصفح:</b> {ua}<br><br>'
                . 'إذا لم تكن أنت، غيّر كلمة المرور فورًا وتواصل مع الدعم.',
                self::requestFingerprint()
            )
        );
    }

    /**
     * بصمة الطلب: الوقت وعنوان IP والمتصفح.
     *
     * @return array<string, string>
     */
    private static function requestFingerprint(): array
    {
        return [
            'time' => date('Y-m-d H:i:s'),
            'ip'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ];
    }
}
