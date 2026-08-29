<?php

/**
 * scripts/mail-worker.php
 * يُفرِغ طابور البريد — يُشغَّل خارج مسار الطلب.
 *
 * الاستخدام:
 *     php scripts/mail-worker.php                 دفعة واحدة (25 رسالة)
 *     php scripts/mail-worker.php --limit=100     دفعة أكبر
 *     php scripts/mail-worker.php --status        حالة الطابور بلا إرسال
 *     php scripts/mail-worker.php --retry-failed  يعيد الفاشلة إلى المعلّقة
 *
 * الجدولة على ويندوز (Task Scheduler) كل دقيقة:
 *     schtasks /create /tn "CairoStoreMail" /tr ^
 *       "C:\xampp\php\php.exe C:\xampp\htdocs\STORE\scripts\mail-worker.php" ^
 *       /sc minute /mo 1
 *
 * وعلى لينكس (cron):
 *     * * * * * php /var/www/STORE/scripts/mail-worker.php >/dev/null 2>&1
 *
 * لماذا دفعة واحدة تنتهي بدل حلقة دائمة؟ لأن العملية الدائمة تحتاج
 * إشرافاً (إعادة تشغيل عند السقوط، حدّ ذاكرة، إغلاق نظيف) — وهو ما لا
 * يوفّره XAMPP. دفعة قصيرة تنتهي بنفسها يجدولها النظام: إن سقطت مرّة
 * عملت في الدقيقة التالية، ولا حالة تتسرّب بين التشغيلات.
 *
 * ⚠️ إن لم يُجدوَل هذا السكربت فالرسائل تتراكم في mail_queue بلا إرسال.
 * `--status` هو ما يكشف ذلك بسرعة.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

use App\Core\Database;
use App\Core\Mailer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$argvList = $argv ?? [];

/** يقرأ قيمة خيار على شكل --key=value. */
/**
 * @param list<string> $args
 */
function optionValue(array $args, string $name, ?string $default = null): ?string
{
    foreach ($args as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return $default;
}

/** ملخّص الطابور حسب الحالة. */
/**
 * @return array<string, int>
 */
function queueSummary(): array
{
    $rows = Database::connect()
        ->query('SELECT status, COUNT(*) AS n FROM mail_queue GROUP BY status')
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'pending' => (int) ($rows['pending'] ?? 0),
        'sent'    => (int) ($rows['sent']    ?? 0),
        'failed'  => (int) ($rows['failed']  ?? 0),
    ];
}

echo PHP_EOL . '  طابور البريد — ' . DB_NAME . PHP_EOL . PHP_EOL;

// ── الحالة فقط ────────────────────────────────────────────────
if (in_array('--status', $argvList, true)) {
    $s = queueSummary();
    echo '  معلّقة: ' . $s['pending'] . '   مُرسَلة: ' . $s['sent'] . '   فاشلة: ' . $s['failed'] . PHP_EOL;

    if ($s['failed'] > 0) {
        echo PHP_EOL . '  آخر الأخطاء:' . PHP_EOL;
        $failed = Database::connect()->query(
            "SELECT id, to_email, last_error FROM mail_queue
              WHERE status = 'failed' ORDER BY id DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($failed as $row) {
            echo '    #' . $row['id'] . '  ' . $row['to_email'] . '  — ' . ($row['last_error'] ?? '؟') . PHP_EOL;
        }
    }

    echo PHP_EOL;
    exit(0);
}

// ── إعادة الفاشلة إلى الطابور ─────────────────────────────────
if (in_array('--retry-failed', $argvList, true)) {
    // attempts تُصفَّر أيضاً وإلا رفضها processQueue فوراً بشرط
    // `attempts < MAX_ATTEMPTS` — فتبدو معلّقة ولا تُرسَل أبداً.
    $n = Database::connect()->exec(
        "UPDATE mail_queue SET status = 'pending', attempts = 0, last_error = NULL WHERE status = 'failed'"
    );
    echo '  ✓ أُعيدت ' . (int) $n . ' رسالة إلى الطابور.' . PHP_EOL . PHP_EOL;
    exit(0);
}

// ── الإفراغ ───────────────────────────────────────────────────
$limit  = max(1, (int) optionValue($argvList, 'limit', '25'));
$result = Mailer::processQueue($limit);
$after  = queueSummary();

if ($result['sent'] === 0 && $result['failed'] === 0) {
    echo '  · لا رسائل معلّقة.' . PHP_EOL . PHP_EOL;
    exit(0);
}

echo '  ✓ أُرسلت: ' . $result['sent'] . PHP_EOL;

if ($result['failed'] > 0) {
    echo '  ✗ فشلت:  ' . $result['failed'] . PHP_EOL;
}
if ($result['skipped'] > 0) {
    echo '  · تخطّى:  ' . $result['skipped'] . ' (عامل آخر حجزها)' . PHP_EOL;
}

echo '  المتبقّي معلّقاً: ' . $after['pending'] . PHP_EOL . PHP_EOL;

// كود خروج غير صفري عند وجود فشل نهائي — كي تلتقطه المراقبة.
exit($after['failed'] > 0 ? 1 : 0);
