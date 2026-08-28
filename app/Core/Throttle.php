<?php

namespace App\Core;

use PDO;

/**
 * Throttle — عدّاد محاولات عامّ لنقاط الدخول الحسّاسة.
 *
 * الفرق بينه وبين isRateLimited في UserModel/AdminModel ليس في الآلية بل
 * في السؤال. تلك تسأل: «كم مرّة فشل الدخول إلى هذا **الحساب**؟» — وهو
 * سؤال عن حساب بعينه، وجوابه يُعرَض للأدمن كـfailed_attempts. وهذه تسأل:
 * «كم طلباً أرسل هذا **المصدر** إلى هذه النقطة؟».
 *
 * السؤال الثاني لم يكن أحد يسأله، وغيابه هو ما جعل خطوة الـ2FA قابلة
 * للكسر بالتخمين: الكود ست خانات، والنافذة ±30 ثانية تجعل ثلاثة أكواد
 * صالحة من مليون في أي لحظة — ولا شيء كان يمنع المليون محاولة.
 *
 * المعرِّف اليوم هو عنوان IP. هذا مقصود ومحدود: من يملك عناوين كثيرة
 * يتجاوزه. لكنه يرفع كلفة الهجوم من «حلقة while» إلى «شبكة وكلاء»،
 * ويبقى الخنق المرتبط بالحساب قائماً فوقه — الطبقتان تحرسان شيئين
 * مختلفين ولا تغني إحداهما عن الأخرى.
 */
class Throttle
{
    /** كم يوماً يبقى الأثر قبل أن يُكنَس. */
    private const RETENTION_DAYS = 1;

    /**
     * هل تجاوز هذا المصدر الحدَّ المسموح على هذا الدلو خلال النافذة؟
     *
     * ⚠️ ترجع false عند فشل الاتصال بالقاعدة — أي تفتح الباب لا تغلقه.
     * هذا مقصود ويطابق سلوك isRateLimited القائم: كل نقطة محروسة هنا
     * تحتاج القاعدة لتعمل أصلاً (لا دخول بلا جدول admins)، فإغلاق الباب
     * عند عطل القاعدة يمنع ما هو ممنوع أصلاً، ويحوّل عطلاً عابراً في
     * القاعدة إلى قفل كامل للموقع.
     */
    public static function tooMany(string $bucket, string $identifier, int $max, int $windowMinutes): bool
    {
        try {
            $stmt = Database::connect()->prepare(
                "SELECT COUNT(*) FROM throttle_attempts
                 WHERE bucket = ?
                   AND identifier = ?
                   AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
            );
            $stmt->bindValue(1, $bucket, PDO::PARAM_STR);
            $stmt->bindValue(2, $identifier, PDO::PARAM_STR);
            $stmt->bindValue(3, $windowMinutes, PDO::PARAM_INT);
            $stmt->execute();

            return (int)$stmt->fetchColumn() >= $max;
        } catch (\Exception $e) {
            error_log('Throttle::tooMany Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * يسجّل محاولة، ويكنس أثر هذا المصدر القديم في الحركة نفسها.
     *
     * الكنس هنا لا في مهمّة مجدولة: الحذف محصور بـ(bucket, identifier)
     * فيمشي على الفهرس نفسه ولا يمسّ صفوف غيره، وكلفته لا تُذكر مقابل
     * ضمان أن الجدول لا ينمو بلا حدّ في مشروع بلا cron مضمون.
     */
    public static function record(string $bucket, string $identifier): void
    {
        try {
            $db = Database::connect();

            $db->prepare(
                "INSERT INTO throttle_attempts (bucket, identifier, attempted_at)
                 VALUES (?, ?, NOW())"
            )->execute([$bucket, $identifier]);

            $stmt = $db->prepare(
                "DELETE FROM throttle_attempts
                 WHERE bucket = ?
                   AND identifier = ?
                   AND attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->bindValue(1, $bucket, PDO::PARAM_STR);
            $stmt->bindValue(2, $identifier, PDO::PARAM_STR);
            $stmt->bindValue(3, self::RETENTION_DAYS, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\Exception $e) {
            error_log('Throttle::record Error: ' . $e->getMessage());
        }
    }

    /**
     * يمسح أثر هذا المصدر على هذا الدلو — يُستدعى بعد نجاح حقيقي.
     *
     * بدونه يدفع المستخدم الذي نسي كلمته مرّتين ثم تذكّرها ثمنَ
     * محاولتيه في المرّة التالية، وهو ليس من نحرس منه.
     */
    public static function clear(string $bucket, string $identifier): void
    {
        try {
            Database::connect()
                ->prepare("DELETE FROM throttle_attempts WHERE bucket = ? AND identifier = ?")
                ->execute([$bucket, $identifier]);
        } catch (\Exception $e) {
            error_log('Throttle::clear Error: ' . $e->getMessage());
        }
    }

    /**
     * عنوان المصدر كما يراه الخادم.
     *
     * REMOTE_ADDR وحده عمداً: X-Forwarded-For ترويسة يرسلها العميل، فمن
     * يقرأها بلا بروكسي موثوق أمامه يمنح المهاجم مفتاح تجاوز الخنق —
     * يكفي أن يغيّرها في كل طلب. حين يوضع المشروع خلف بروكسي حقيقي،
     * هذا هو الموضع الوحيد الذي يتغيّر.
     */
    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return $ip !== '' ? substr($ip, 0, 45) : 'unknown';
    }
}
