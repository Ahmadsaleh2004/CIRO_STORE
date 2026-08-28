<?php

namespace App\Core;

class Totp
{
    private const SECRET_LENGTH = 20;

    public static function generateSecret(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32 alphabet
        $secret = '';
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    public static function getQrCodeUrl(string $secret, string $accountEmail, string $issuer = 'Store'): string
    {
        $label = rawurlencode("{$issuer}:{$accountEmail}");
        $otpauth = "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode($issuer);
        // نستخدم API عام مجاني لتوليد صورة QR (بدون أي تكلفة، لا يحتاج حساب)
        return 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($otpauth);
    }

    public static function verifyCode(string $secret, string $code): bool
    {
        return self::verifyAndGetSlice($secret, $code) !== null;
    }

    /**
     * يتحقق من الكود ويُرجع الشريحة الزمنية التي طابقته، أو null.
     *
     * وُجدت لأن bool وحدها لا تكفي لمنع إعادة الاستخدام: النافذة ±30
     * ثانية تجعل الكود الواحد صالحاً تسعين ثانية، فمن يلتقطه (فوق كتف،
     * أو من سجلّ، أو من شاشة مشارَكة) يعيد إرساله داخلها. المستدعي يخزّن
     * الشريحة المُعادة ويمرّرها في المرّة التالية كـ$lastSlice، فيصير كل
     * كود صالحاً مرّة واحدة فقط.
     *
     * المقارنة بـhash_equals لا بـ===. الفارق الزمني بين مقارنتَي نصّين
     * من ست خانات ضئيل ويصعب استغلاله عبر الشبكة، لكن الكلفة هنا صفر
     * والقاعدة واحدة: أي مقارنة تمسّ سرّاً تُجرى بزمن ثابت. (المشروع
     * يطبّقها أصلاً على state في OAuth، فالاستثناء كان هنا وحده.)
     *
     * @param  int|null $lastSlice آخر شريحة استُهلكت لهذا الحساب.
     * @return int|null الشريحة المطابِقة عند النجاح، أو null عند الفشل
     *                  أو عند كون الكود مستهلَكاً سابقاً.
     */
    public static function verifyAndGetSlice(string $secret, string $code, ?int $lastSlice = null): ?int
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        // نسمح بفارق دقيقة واحدة قبل/بعد (لفروقات الساعة البسيطة)
        for ($offset = -1; $offset <= 1; $offset++) {
            // (int) صريحة: floor تُرجع float، وgenerateCode تطلب int.
            // كان التحويل يحدث ضمنياً بحكم الوضع غير الصارم — يعمل
            // اليوم ويتوقّف عن العمل لحظة إضافة declare(strict_types=1).
            $timeSlice = (int) floor(time() / 30) + $offset;

            if (!hash_equals(self::generateCode($secret, $timeSlice), $code)) {
                continue;
            }

            // الكود صحيح — لكن هل استُهلك؟ الرفض يشمل الشرائح الأقدم من
            // آخر مستهلَكة أيضاً، لا المساواة وحدها: بدونه يبقى الكود
            // السابق في النافذة صالحاً بعد استعمال اللاحق.
            if ($lastSlice !== null && $timeSlice <= $lastSlice) {
                return null;
            }

            return $timeSlice;
        }

        return null;
    }

    private static function generateCode(string $secret, int $timeSlice): string
    {
        $secretKey = self::base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord($hash[19]) & 0xf;
        $value = (
            ((ord($hash[$offset + 0]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        );
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper($secret);
        $binaryString = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos($chars, $char);
            if ($pos === false) {
                continue;
            }
            $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($binaryString, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }
        return $bytes;
    }
}
