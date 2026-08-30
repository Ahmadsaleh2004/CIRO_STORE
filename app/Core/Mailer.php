<?php

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Mailer
{
    /** The most attempts one message gets before it counts as permanently failed. */
    public const MAX_ATTEMPTS = 3;

    /** The reason for the last send() failure — the worker stores it on the queue row. */
    private static ?string $lastError = null;

    /**
     * Puts the message on the queue and returns immediately — the default way to send.
     *
     * ⚠️ Why not send() directly? Because it opens a Gmail SMTP connection **inside
     * the request**: it connects, authenticates and sends before the visitor sees any
     * response at all. The effect is threefold — the admin sign-in waits on Gmail
     * every time, a slow SMTP server hangs PHP threads rather than merely slowing
     * them, and every call to /auth/forgot opens a fresh connection, so flooding it
     * drains the server and not just the quota.
     *
     * It returns true if the message was accepted for sending, not if it arrived.
     * That is a genuine difference in the contract: anyone needing delivery
     * confirmation reads the queue row's status, and nobody in the project needs it —
     * every caller is sending a notification their logic does not depend on.
     *
     * And when writing to the queue fails, the message is sent directly rather than
     * lost: a password reset email that never arrives locks the owner out of their own
     * account, which is worse harm than a few seconds of waiting.
     */
    public static function queue(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        try {
            $stmt = Database::connect()->prepare(
                'INSERT INTO mail_queue (to_email, to_name, subject, body) VALUES (?, ?, ?, ?)'
            );
            return $stmt->execute([$toEmail, $toName, $subject, $htmlBody]);
        } catch (\Throwable $e) {
            error_log('Mailer::queue Error (sending directly instead): ' . $e->getMessage());
            reportException($e);
            return self::send($toEmail, $toName, $subject, $htmlBody);
        }
    }

    /**
     * Send an email over Gmail SMTP using PHPMailer.
     *
     * ⚠️ Normally called from scripts/mail-worker.php alone, which runs outside the
     * request path. Calling it from a controller reinstates the very problem the queue
     * exists to solve. Use queue().
     *
     * Returns true on success and false on failure (logging the error with error_log).
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
            reportException($e);
            return false;
        }
    }

    /**
     * The reason for the last send() failure, or null if it succeeded.
     *
     * It exists so the worker can store the reason on the queue row. The error used
     * to go to error_log alone — a place that does not connect a failed message to its
     * cause, leaving the question "why did so-and-so's email never arrive?"
     * unanswerable.
     */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Drains one batch from the queue — the heart of scripts/mail-worker.php.
     *
     * The logic lives here rather than in the script so it can be tested: a terminal
     * script is not called from a test, and the sending, failure and retry logic is
     * exactly what needs testing.
     *
     * **The claim is optimistic**, through the attempts column itself: the row is
     * updated on the condition that attempts still holds the value that was read. Two
     * workers running together means only one succeeds, and the other skips the row
     * rather than sending the message twice — and a duplicated password reset link is
     * not merely an annoyance but two valid tokens where there should be one.
     *
     * @param  callable|null $sender A stand-in for send() — for tests only.
     *         It exists because testing without one means a real Gmail connection on
     *         the project's account: the first run of the "failed message" test
     *         delivered an actual email to the server (Gmail accepted it, then it
     *         bounced). A test suite that sends mail from a real account is a fault,
     *         not a tool.
     *         Signature: fn(string $to, string $name, string $subject, string $body): bool
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

            // Attempts exhausted? Judged on the value after the increment.
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
     * A single HTML template for every email the site sends.
     *
     * ⚠️ Variable values are passed in $vars as `{name}` placeholders — **not
     * interpolated into the string**. The difference is not stylistic:
     *
     * The body used to be built by direct interpolation, and among the things
     * interpolated was `$_SERVER['HTTP_USER_AGENT']` in the sign-in alert email — a
     * header entirely under the sender's control. Which means an attacker writes HTML
     * into their browser name and it lands in the admin's inbox verbatim.
     *
     * Placeholders make that impossible by construction rather than by discipline:
     * every value passes through htmlspecialchars before it is placed, and there is no
     * other way to introduce one. The fixed template stays HTML because it is written
     * in the code and does not arrive over the network.
     *
     * @param string                $title    The message title (escaped)
     * @param string                $bodyHtml A fixed template, possibly containing `{placeholders}`
     * @param array<string, string> $vars     The values — all of them escaped
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
