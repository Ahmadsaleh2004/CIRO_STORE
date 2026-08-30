<?php

namespace App\Core;

/**
 * Log — سطر JSON واحد لكل حدث تشغيلي.
 *
 * ══════════════════════════════════════════════════════════════
 * المشكلة التي يحلّها
 * ══════════════════════════════════════════════════════════════
 *
 * `storage/php-error.log` كان يخلط صنفين لا علاقة لأحدهما بالآخر:
 *
 *     [27-Aug-2026 13:02:35] PHP Fatal error: Cannot override final …
 *     [27-Aug-2026 13:03:40] [Cairo Store] 405: POST /about — allowed: GET
 *     [27-Aug-2026 16:24:07] [Cairo Store] 404: GET /definitely-not-a-page
 *
 * الأوّل عطلٌ يستحقّ الاستيقاظ له. والثاني والثالث **سلوك صحيح
 * للراوتر**: أحدهم طلب صفحة غير موجودة، وهذا ما يجب أن يحدث.
 *
 * وحين يتساوى الاثنان في الشكل، لا يمكن فرزهما إلا بالقراءة البشرية —
 * فلا يُقرأ الملف أصلاً. وهذا بالضبط ما حدث: صفحة الدفع كانت معطّلة
 * كلياً منذ كومِت الأساس، والسجلّ مفتوح أمام الجميع.
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا JSON على سطر واحد
 * ══════════════════════════════════════════════════════════════
 *
 * سطرٌ واحد كي يبقى `grep` و`tail` عاملَين كما هما، وJSON كي يصير
 * الفرز آلياً لا بصرياً:
 *
 *     grep '"level":"error"' storage/php-error.log
 *     grep '"event":"http_404"' storage/php-error.log | wc -l
 *
 * وأي مجمِّع سجلّات (أو Sentry) يقرأ هذا الشكل بلا محلّل مخصّص.
 *
 * ── لماذا error_log لا ملفّ خاصّ ──────────────────────────────
 *
 * لأن `error_log` هي المكان الذي يذهب إليه كل شيء آخر بالفعل: أخطاء
 * PHP القاتلة، وتحذيرات المحرّك، وكل `error_log` قائمة في المودلز.
 * وسجلّ ثانٍ يعني ملفّين يجب قراءتهما معاً لفهم دقيقة واحدة — وهو
 * ما يجعل السجلّات لا تُقرأ.
 *
 * الوجهة نفسها إذن، والشكل هو ما تغيّر.
 *
 * ══════════════════════════════════════════════════════════════
 * ما لا يدخل السياق
 * ══════════════════════════════════════════════════════════════
 *
 * ⚠️ لا تمرّر كلمات مرور ولا توكنات ولا أكواد 2FA في `$context`.
 * السجلّ يُقرأ ويُنسخ ويُرسَل في تذاكر الدعم — وهو أقلّ الأماكن حمايةً
 * في أي نظام. القاعدة هنا: يسجَّل **ما حدث**، لا **بماذا حدث**.
 */
final class Log
{
    /** حدث اعتيادي يستحقّ الأثر لا الانتباه. */
    public const INFO = 'info';

    /** شيء غير متوقَّع لكن التطبيق تصرّف تصرّفاً صحيحاً. */
    public const WARNING = 'warning';

    /** عطل — هذا وحده ما يستحقّ تنبيهاً. */
    public const ERROR = 'error';

    /**
     * يكتب سطر سجلّ واحد.
     *
     * @param string               $level   من الثوابت أعلاه
     * @param string               $event   معرّف قصير ثابت (`http_404`)
     *                                      — يُفرَز به آلياً، فلا تُصَغ
     *                                      كجملة تتغيّر صياغتها
     * @param array<string,scalar|null> $context حقائق قابلة للفرز
     */
    public static function write(string $level, string $event, array $context = []): void
    {
        $line = [
            'ts'    => date('c'),
            'level' => $level,
            'event' => $event,
        ];

        // المسار والطريقة يُضافان تلقائياً: كل حدث تقريباً يحتاجهما،
        // وتركهما للمستدعي يعني نسيانهما في نصف المواضع.
        if (PHP_SAPI !== 'cli') {
            $line['method'] = $_SERVER['REQUEST_METHOD'] ?? '?';
            $line['path']   = strtok($_SERVER['REQUEST_URI'] ?? '?', '?') ?: '?';
        } else {
            $line['sapi'] = 'cli';
        }

        $encoded = json_encode(
            $line + $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // فشل الترميز لا يجوز أن يبتلع الحدث: سجلٌّ ناقص أفضل من صمت.
        error_log($encoded !== false ? $encoded : $level . ' ' . $event);
    }

    /** @param array<string,scalar|null> $context */
    public static function info(string $event, array $context = []): void
    {
        self::write(self::INFO, $event, $context);
    }

    /** @param array<string,scalar|null> $context */
    public static function warning(string $event, array $context = []): void
    {
        self::write(self::WARNING, $event, $context);
    }

    /** @param array<string,scalar|null> $context */
    public static function error(string $event, array $context = []): void
    {
        self::write(self::ERROR, $event, $context);
    }
}
