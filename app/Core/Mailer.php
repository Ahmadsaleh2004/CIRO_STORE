<?php

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Mailer
{
    /**
     * إرسال إيميل عبر Gmail SMTP باستخدام PHPMailer.
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
            return true;
        } catch (PHPMailerException $e) {
            error_log("Mailer::send Error: " . $mail->ErrorInfo);
            return false;
        } catch (\Exception $e) {
            error_log("Mailer::send Exception: " . $e->getMessage());
            return false;
        }
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
            <p style='color:#999;font-size:12px'>هذا إيميل تلقائي، لا ترد عليه مباشرة.</p>
        </div>";
    }
}
