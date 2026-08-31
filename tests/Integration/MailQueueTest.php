<?php

namespace Tests\Integration;

use App\Core\Mailer;
use Tests\Support\DatabaseTestCase;

/**
 * The mail queue — the request writes a row and returns, and the worker sends outside it.
 *
 * Mailer::send used to open a Gmail SMTP connection **inside the request**: it connected,
 * authenticated and sent before the visitor saw any response. So the admin sign-in waited on
 * Gmail every time, a slow SMTP server hung PHP threads rather than merely slowing them, and
 * every call to /auth/forgot opened a new connection — turning a flood into an exhaustion of
 * the server rather than of the quota alone.
 *
 * ⚠️ No test here connects to SMTP. Every call to processQueue passes a substitute sender —
 * and that is not a stylistic preference: the development environment holds the project's
 * real Gmail credentials, and the first run without a substitute delivered an actual message
 * to the server. A test suite that sends email from a real account is a fault, not a tool.
 *
 * And what is tested is the queue's contract — writing, claiming, failing and retrying —
 * which is what breaks silently, unlike the sending, which reveals itself immediately.
 */
final class MailQueueTest extends DatabaseTestCase
{
    /** A substitute sender that always fails — to measure the failure path with no network. */
    private static function failingSender(): callable
    {
        return static fn(): bool => false;
    }

    /** A substitute sender that always succeeds — to measure the success path with no network. */
    private static function succeedingSender(): callable
    {
        return static fn(): bool => true;
    }

    public function testQueueingWritesAPendingRowAndDoesNotSend(): void
    {
        $ok = Mailer::queue('someone@example.test', 'Somebody', 'A subject', '<p>A body</p>');

        $this->assertTrue($ok);

        $row = $this->pdo->query('SELECT * FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('someone@example.test', $row['to_email']);
        $this->assertSame('A subject', $row['subject']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['sent_at']);
    }

    public function testTheQueuePreservesArabicAndMarkupExactly(): void
    {
        $body = '<p>مرحباً «فلان» — رابط: <a href="https://x.test/?a=1&b=2">اضغط</a></p>';
        Mailer::queue('a@b.test', 'اسم عربي', 'موضوع عربي', $body);

        $stored = $this->pdo->query('SELECT body, to_name FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame($body, $stored['body']);
        $this->assertSame('اسم عربي', $stored['to_name']);
    }

    public function testASuccessfulSendMarksTheRowSentAndStampsTheTime(): void
    {
        Mailer::queue('a@b.test', 'x', 's', 'b');

        $result = Mailer::processQueue(25, self::succeedingSender());

        $this->assertSame(1, $result['sent']);

        $row = $this->pdo->query('SELECT status, sent_at, last_error FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('sent', $row['status']);
        $this->assertNotNull($row['sent_at']);
        $this->assertNull($row['last_error']);
    }

    /**
     * A failing message stays pending until the attempts are exhausted, and is then marked
     * failed.
     */
    public function testAFailingMessageIsRetriedThenMarkedFailed(): void
    {
        Mailer::queue('nowhere@invalid.test', 'x', 'A subject', 'A body');

        for ($pass = 1; $pass < Mailer::MAX_ATTEMPTS; $pass++) {
            Mailer::processQueue(25, self::failingSender());

            $row = $this->pdo->query('SELECT status, attempts FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('pending', $row['status'], "After attempt {$pass} it must stay pending.");
            $this->assertSame($pass, (int) $row['attempts']);
        }

        // The final attempt exhausts the allowance.
        Mailer::processQueue(25, self::failingSender());

        $row = $this->pdo->query('SELECT status, attempts FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(Mailer::MAX_ATTEMPTS, (int) $row['attempts']);
    }

    /**
     * An exhausted message does not return to the cycle on its own.
     *
     * Without the `attempts < MAX_ATTEMPTS` condition, a message circulates forever trying to
     * reach a server that does not respond, slowing every batch after it.
     */
    public function testAnExhaustedMessageIsNotPickedUpAgain(): void
    {
        $this->pdo->exec(
            "INSERT INTO mail_queue (to_email, to_name, subject, body, status, attempts)
             VALUES ('x@y.test', 'x', 's', 'b', 'failed', " . Mailer::MAX_ATTEMPTS . ')'
        );

        $result = Mailer::processQueue(25, self::succeedingSender());

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['failed']);
    }

    /**
     * The optimistic claim prevents a double send.
     *
     * Two workers read the same row; claiming it on the condition of the attempts value they
     * read lets only one succeed. And that is not a cosmetic improvement: a duplicate send of
     * a password reset link means two valid tokens where there should be one.
     */
    public function testAClaimedRowCannotBeClaimedTwice(): void
    {
        Mailer::queue('a@b.test', 'x', 's', 'b');
        $id = (int) $this->pdo->lastInsertId();

        $claim = fn(int $attempts): int => (function () use ($id, $attempts) {
            $stmt = $this->pdo->prepare(
                "UPDATE mail_queue SET attempts = attempts + 1
                  WHERE id = ? AND status = 'pending' AND attempts = ?"
            );
            $stmt->execute([$id, $attempts]);
            return $stmt->rowCount();
        })();

        // The first worker claims it successfully; the second reads the same old value and
        // fails.
        $this->assertSame(1, $claim(0));
        $this->assertSame(0, $claim(0));
    }

    /**
     * The controllers do not call send() directly.
     *
     * The queue without this rule is half an answer: one new line calling send() is enough to
     * bring SMTP back into the request path at one endpoint — usually the new endpoint whose
     * load nobody has thought about.
     */
    public function testNoControllerCallsSendDirectly(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2) . '/app/Controllers/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            if (str_contains($src, 'Mailer::send(')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Controllers opening SMTP inside the request — use Mailer::queue():\n  "
            . implode("\n  ", $offenders)
        );
    }
}
