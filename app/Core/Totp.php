<?php

namespace App\Core;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

// ⚠️ لا `use OTPHP\TOTP;` هنا. أسماء الأصناف في PHP غير حسّاسة لحالة
// الأحرف، فذلك الاستيراد يُدخل الاسم `TOTP` إلى هذا النطاق — وهو نفسه
// `Totp` الذي يُعلَن أدناه. النتيجة خطأ قاتل: «Cannot declare class
// App\Core\Totp because the name is already in use». الاسم المؤهَّل
// بالكامل في موضع الاستعمال يتجنّبه ويوضّح المصدر معاً.

/**
 * Totp — المصادقة الثنائية لحسابات الأدمن.
 *
 * ⚠️ كان هذا الملف يكتب TOTP وbase32 بيده. عمل بشكل صحيح (متجهات
 * RFC 6238 تشهد بذلك، وهي مُختبَرة)، لكن الكتابة اليدوية للتعمية تعني
 * أن أي خطأ دقيق فيها لا يُكتشف بمراجعة بشرية. استُبدل جوهرها بـ
 * spomky-labs/otphp، وبقيت **السياسة** هنا صريحة:
 *
 *   · نافذة التسامح ±30 ثانية — قرار أمني لا تفصيل تنفيذ، فيبقى
 *     مقروءاً في هذا الملف لا مدفوناً في وسيط مكتبة.
 *   · منع إعادة استخدام الكود عبر الشريحة المستهلَكة.
 *
 * ولذلك لا تُستعمل TOTP::verify() من المكتبة: هي تأخذ leeway بالثواني
 * ولا تُرجع الشريحة المطابِقة، والشريحة هي ما يمنع إعادة الاستخدام.
 * الحلقة أدناه تسأل المكتبة عن رمز كل شريحة وتقارن بزمن ثابت — فالتعمية
 * كلها منها، والقرار كلّه هنا.
 *
 * ⚠️ والأهمّ: صورة الـQR تُولَّد **محلياً**. كانت تُبنى برابط إلى
 * api.qrserver.com يحمل السرّ في الـquery string — أي أن سرّ المصادقة
 * الثنائية لكل أدمن كان يُرسَل إلى طرف ثالث، ويمرّ في سجلّاته وسجلّات
 * أي وسيط على الطريق. السرّ الآن لا يغادر الخادم إطلاقاً.
 */
class Totp
{
    private const SECRET_LENGTH = 20;

    /** طول الشريحة الزمنية بالثواني — معيار TOTP. */
    private const PERIOD = 30;

    /** كم شريحة تسامح قبل الحالية وبعدها (1 = ±30 ثانية). */
    private const WINDOW = 1;

    public static function generateSecret(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32 alphabet
        $secret = '';
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    /**
     * صورة QR جاهزة للعرض، مولَّدة على الخادم كـdata: URI.
     *
     * SVG لا PNG: باكند الـSVG في bacon-qr-code لا يحتاج imagick ولا gd،
     * فلا يضيف امتداد PHP إلى متطلّبات النشر. وهو أصلاً أوضح عند التكبير.
     *
     * القيمة data: URI لا مسار ملف: الصورة تحمل السرّ، وكتابتها على
     * القرص تعني ملفاً يحمل سرّاً حيّاً تحت public/ — وهو ما نتجنّبه
     * بالضبط. تعيش في الاستجابة وحدها ثم تزول.
     */
    public static function getQrCodeUrl(string $secret, string $accountEmail, string $issuer = 'Store'): string
    {
        $renderer = new ImageRenderer(new RendererStyle(250), new SvgImageBackEnd());
        $svg      = (new Writer($renderer))->writeString(self::provisioningUri($secret, $accountEmail, $issuer));

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * رابط otpauth:// كما تفهمه تطبيقات المصادقة.
     *
     * يُعرض للأدمن نصّاً أيضاً حين يتعذّر مسح الصورة — ولذلك هو دالة
     * مستقلة لا سطر داخل مولّد الصورة.
     */
    public static function provisioningUri(string $secret, string $accountEmail, string $issuer = 'Store'): string
    {
        $totp = self::totp($secret);
        $totp->setLabel($accountEmail);
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    public static function verifyCode(string $secret, string $code): bool
    {
        return self::verifyAndGetSlice($secret, $code) !== null;
    }

    /**
     * يتحقق من الكود ويُرجع الشريحة الزمنية التي طابقته، أو null.
     *
     * bool وحدها لا تكفي لمنع إعادة الاستخدام: النافذة ±30 ثانية تجعل
     * الكود الواحد صالحاً تسعين ثانية، فمن يلتقطه (فوق كتف، أو من سجلّ،
     * أو من شاشة مشارَكة) يعيد إرساله داخلها. المستدعي يخزّن الشريحة
     * المُعادة ويمرّرها في المرّة التالية كـ$lastSlice.
     *
     * المقارنة بـhash_equals لا بـ===: الفارق الزمني بين مقارنتَي نصّين
     * من ست خانات ضئيل ويصعب استغلاله عبر الشبكة، لكن الكلفة صفر
     * والقاعدة واحدة — أي مقارنة تمسّ سرّاً تُجرى بزمن ثابت.
     *
     * @param  int|null $lastSlice آخر شريحة استُهلكت لهذا الحساب.
     * @return int|null الشريحة المطابِقة، أو null عند الفشل أو عند كون
     *                  الكود مستهلَكاً سابقاً.
     */
    public static function verifyAndGetSlice(string $secret, string $code, ?int $lastSlice = null): ?int
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $now = (int) floor(time() / self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $timeSlice = $now + $offset;

            if (!hash_equals(self::generateCode($secret, $timeSlice), $code)) {
                continue;
            }

            // الكود صحيح — لكن هل استُهلك؟ الرفض يشمل الشرائح الأقدم من
            // آخر مستهلَكة أيضاً، لا المساواة وحدها: بدونه يبقى كود
            // الشريحة السابقة صالحاً بعد استعمال اللاحقة، أي ثغرة بحجم
            // ثلاثين ثانية تُفتح في اللحظة التي يُفترض أن تُغلق فيها.
            if ($lastSlice !== null && $timeSlice <= $lastSlice) {
                return null;
            }

            return $timeSlice;
        }

        return null;
    }

    /**
     * رمز الشريحة المعطاة.
     *
     * تبقى private وبالتوقيع نفسه عمداً: اختبارات متجهات RFC 6238 تصل
     * إليها بـReflection، وهي ما يثبت أن التطبيق يعمل مع Google
     * Authenticator فعلاً لا مع نفسه فقط. بعد الانتقال إلى المكتبة صارت
     * تلك الاختبارات تحرس **التكامل معها** — وهو ما نريد حراسته الآن.
     */
    private static function generateCode(string $secret, int $timeSlice): string
    {
        return self::totp($secret)->at($timeSlice * self::PERIOD);
    }

    /** كائن TOTP بإعدادات المشروع: SHA-1 · ست خانات · ثلاثون ثانية. */
    private static function totp(string $secret): \OTPHP\TOTP
    {
        return \OTPHP\TOTP::createFromSecret(strtoupper(trim($secret)));
    }
}
