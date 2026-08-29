<?php

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Mailer
{
    /** أقصى عدد محاولات لرسالة واحدة قبل اعتبارها فاشلة نهائياً. */
    public const MAX_ATTEMPTS = 3;

    /** سبب آخر فشل في send() — يخزّنه العامل في صفّ الطابور. */
    private static ?string $lastError = null;

    /**
     * يضع الرسالة في الطابور ويعود فوراً — الطريق الافتراضي للإرسال.
     *
     * ⚠️ لماذا لا send() مباشرةً؟ لأنها تفتح اتصال Gmail SMTP **داخل
     * الطلب**: تتصل وتصادق وترسل قبل أن يرى الزائر أي استجابة. أثر ذلك
     * ثلاثي — دخول الأدمن ينتظر Gmail في كل مرّة، وتباطؤ SMTP يعلّق
     * خيوط PHP لا يبطّئها فقط، وكل استدعاء لـ/auth/forgot يفتح اتصالاً
     * جديداً فيصير الإغراق استنزافاً للخادم لا للحصّة وحدها.
     *
     * ترجع true إن قُبلت للإرسال لا إن وصلت. هذا فرق حقيقي في العقد:
     * من يحتاج تأكيد الوصول يقرأ حالة الصفّ، ولا أحد في المشروع يحتاجه
     * — كل المستدعين يرسلون تنبيهات لا يتوقّف منطقهم عليها.
     *
     * وعند تعذّر الكتابة في الطابور تُرسَل الرسالة مباشرةً بدل أن
     * تضيع: رسالة إعادة تعيين كلمة المرور التي لا تصل تقفل الحساب على
     * صاحبه، وهو ضرر أكبر من انتظار ثوانٍ.
     */
    public static function queue(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        try {
            $stmt = Database::connect()->prepare(
                'INSERT INTO mail_queue (to_email, to_name, subject, body) VALUES (?, ?, ?, ?)'
            );
            return $stmt->execute([$toEmail, $toName, $subject, $htmlBody]);
        } catch (\Throwable $e) {
            error_log('Mailer::queue Error (يُرسَل مباشرةً بدلاً منه): ' . $e->getMessage());
            return self::send($toEmail, $toName, $subject, $htmlBody);
        }
    }

    /**
     * إرسال إيميل عبر Gmail SMTP باستخدام PHPMailer.
     *
     * ⚠️ تُستدعى من scripts/mail-worker.php وحده في الوضع الطبيعي —
     * وهو يعمل خارج مسار الطلب. استدعاؤها من كنترولر يعيد المشكلة التي
     * وُجد الطابور لحلّها. استعمل queue().
     *
     * يرجع true عند النجاح، false عند الفشل (ويسجل الخطأ بـ error_log).
     */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['MAIL_USERNAME'] ?? '';
            $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
            $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)($_ENV['MAIL_PORT'] ?? 587);
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom(
                $_ENV['MAIL_FROM_ADDRESS'] ?? $mail->Username,
                $_ENV['MAIL_FROM_NAME'] ?? 'Store'
            );
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            $mail->send();
            self::$lastError = null;
            return true;
        } catch (PHPMailerException $e) {
            self::$lastError = $mail->ErrorInfo;
            error_log("Mailer::send Error: " . $mail->ErrorInfo);
            return false;
        } catch (\Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("Mailer::send Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * سبب آخر فشل في send()، أو null إن نجحت.
     *
     * موجودة كي يخزّن العامل السبب في الصفّ. كان الخطأ يذهب إلى
     * error_log وحده — وهو موضع لا يربط الرسالة الفاشلة بسببها، فيبقى
     * السؤال «لماذا لم تصل رسالة فلان؟» بلا جواب.
     */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * يُفرِغ دفعةً من الطابور — قلب scripts/mail-worker.php.
     *
     * المنطق هنا لا في السكربت كي يكون قابلاً للاختبار: سكربت طرفية
     * لا يُستدعى من اختبار، ومنطق الإرسال والفشل وإعادة المحاولة هو
     * بالضبط ما يحتاج اختباراً.
     *
     * **الحجز متفائل** عبر عمود attempts نفسه: تُحدَّث الصفّ بشرط أن
     * تكون قيمة attempts هي التي قُرئت. عاملان يعملان معاً ينجح
     * أحدهما فقط، ويتخطّى الآخر الصفّ بدل أن يرسل الرسالة مرّتين —
     * وإرسال مكرّر لرابط إعادة تعيين كلمة المرور ليس إزعاجاً فقط، بل
     * توكنان صالحان حيث يجب أن يكون واحد.
     *
     * @param  callable|null $sender بديل لـsend() — للاختبار وحده.
     *         موجود لأن الاختبار بلا بديل يعني اتصالاً حقيقياً بـGmail
     *         بحساب المشروع: أوّل تشغيل لاختبار «الرسالة الفاشلة» سلّم
     *         رسالة فعلية إلى الخادم (قبلها Gmail ثم ارتدّت). حزمة
     *         اختبارات ترسل بريداً من حساب حقيقي عطلٌ لا أداة.
     *         التوقيع: fn(string $to, string $name, string $subject, string $body): bool
     * @return array{sent:int, failed:int, skipped:int}
     */
    public static function processQueue(int $limit = 25, ?callable $sender = null): array
    {
        $sender ??= static fn(string $to, string $name, string $subject, string $body): bool
            => self::send($to, $name, $subject, $body);

        $db     = Database::connect();
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        $stmt = $db->prepare(
            "SELECT id, to_email, to_name, subject, body, attempts
               FROM mail_queue
              WHERE status = 'pending' AND attempts < ?
           ORDER BY id ASC
              LIMIT " . max(1, $limit)
        );
        $stmt->execute([self::MAX_ATTEMPTS]);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $claim = $db->prepare(
                "UPDATE mail_queue SET attempts = attempts + 1
                  WHERE id = ? AND status = 'pending' AND attempts = ?"
            );
            $claim->execute([$row['id'], $row['attempts']]);

            if ($claim->rowCount() !== 1) {
                $result['skipped']++;
                continue;
            }

            $ok = $sender($row['to_email'], $row['to_name'], $row['subject'], $row['body']);

            if ($ok) {
                $db->prepare("UPDATE mail_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?")
                   ->execute([$row['id']]);
                $result['sent']++;
                continue;
            }

            // استُنفدت المحاولات؟ الحكم على القيمة بعد الزيادة.
            $exhausted = ((int) $row['attempts'] + 1) >= self::MAX_ATTEMPTS;

            $db->prepare(
                'UPDATE mail_queue SET status = ?, last_error = ? WHERE id = ?'
            )->execute([
                $exhausted ? 'failed' : 'pending',
                mb_substr((string) self::lastError(), 0, 255),
                $row['id'],
            ]);

            $result['failed']++;
        }

        return $result;
    }

    /**
     * قالب HTML موحّد لكل إيميلات الموقع.
     *
     * ⚠️ القيم المتغيّرة تُمرَّر في $vars كنائبات `{اسم}` — **لا تُحقَن
     * في النصّ**. الفرق ليس أسلوبياً:
     *
     * كان الجسم يُبنى بالحقن المباشر، ومن بين ما يُحقَن
     * `$_SERVER['HTTP_USER_AGENT']` في إيميل تنبيه الدخول — وهي ترويسة
     * يتحكّم بها المرسِل كلياً. أي أن مهاجماً يكتب HTML في اسم متصفّحه
     * فيصل إلى صندوق بريد الأدمن كما هو.
     *
     * النائبات تجعل ذلك مستحيلاً بالبناء لا بالانضباط: كل قيمة تمرّ من
     * htmlspecialchars قبل أن تُوضَع، ولا طريق آخر لإدخال قيمة. القالب
     * الثابت يبقى HTML لأنه يُكتب في الكود لا يأتي من الشبكة.
     *
     * @param string                $title    عنوان الرسالة (يُهرَّب)
     * @param string                $bodyHtml قالب ثابت، قد يحوي `{نائبات}`
     * @param array<string, string> $vars     القيم — كلها تُهرَّب
     */
    public static function template(string $title, string $bodyHtml, array $vars = []): string
    {
        $siteName = defined('SITENAME') ? SITENAME : 'Store';

        if ($vars !== []) {
            $replacements = [];
            foreach ($vars as $key => $value) {
                $replacements['{' . $key . '}'] = htmlspecialchars(
                    (string) $value,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
            }
            $bodyHtml = strtr($bodyHtml, $replacements);
        }

        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeName  = htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;direction:rtl;text-align:right'>
            <h2 style='color:#222'>{$safeName}</h2>
            <h3 style='color:#333'>{$safeTitle}</h3>
            <div style='color:#444;line-height:1.8'>{$bodyHtml}</div>
            <hr style='margin-top:30px;border:none;border-top:1px solid #eee'>
            <p style='color:#999;font-size:12px'>This is an automated message — please do not reply.</p>
        </div>";
    }
}
