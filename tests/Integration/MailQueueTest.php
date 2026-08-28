<?php

namespace Tests\Integration;

use App\Core\Mailer;
use Tests\Support\DatabaseTestCase;

/**
 * طابور البريد — الطلب يكتب صفّاً ويعود، والعامل يرسل خارجه.
 *
 * كان Mailer::send يفتح اتصال Gmail SMTP **داخل الطلب**: يتصل ويصادق
 * ويرسل قبل أن يرى الزائر أي استجابة. فدخول الأدمن ينتظر Gmail في كل
 * مرّة، وتباطؤ SMTP يعلّق خيوط PHP لا يبطّئها فقط، وكل استدعاء
 * لـ/auth/forgot يفتح اتصالاً جديداً — فيصير الإغراق استنزافاً للخادم
 * لا للحصّة وحدها.
 *
 * ⚠️ لا اختبار هنا يتصل بـSMTP. كل استدعاء لـprocessQueue يمرّر
 * مُرسِلاً بديلاً — وهذا ليس تفضيلاً أسلوبياً: بيئة التطوير تحمل بيانات
 * Gmail الحقيقية للمشروع، وأوّل تشغيل بلا بديل سلّم رسالة فعلية إلى
 * الخادم. حزمة اختبارات ترسل بريداً من حساب حقيقي عطلٌ لا أداة.
 *
 * وما يُختبَر هو عقد الطابور — الكتابة والحجز والفشل وإعادة المحاولة —
 * وهو ما ينكسر بصمت، بخلاف الإرسال الذي ينكشف فوراً.
 */
final class MailQueueTest extends DatabaseTestCase
{
    /** مُرسِل بديل يفشل دائماً — لقياس مسار الفشل بلا شبكة. */
    private static function failingSender(): callable
    {
        return static fn(): bool => false;
    }

    /** مُرسِل بديل ينجح دائماً — لقياس مسار النجاح بلا شبكة. */
    private static function succeedingSender(): callable
    {
        return static fn(): bool => true;
    }

    public function testQueueingWritesAPendingRowAndDoesNotSend(): void
    {
        $ok = Mailer::queue('someone@example.test', 'فلان', 'موضوع', '<p>جسم</p>');

        $this->assertTrue($ok);

        $row = $this->pdo->query('SELECT * FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('someone@example.test', $row['to_email']);
        $this->assertSame('موضوع', $row['subject']);
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
        Mailer::queue('a@b.test', 'x', 'م', 'ج');

        $result = Mailer::processQueue(25, self::succeedingSender());

        $this->assertSame(1, $result['sent']);

        $row = $this->pdo->query('SELECT status, sent_at, last_error FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('sent', $row['status']);
        $this->assertNotNull($row['sent_at']);
        $this->assertNull($row['last_error']);
    }

    /**
     * الرسالة الفاشلة تبقى معلّقة حتى تُستنفد المحاولات، ثم تُعلَّم فاشلة.
     */
    public function testAFailingMessageIsRetriedThenMarkedFailed(): void
    {
        Mailer::queue('nowhere@invalid.test', 'x', 'موضوع', 'جسم');

        for ($pass = 1; $pass < Mailer::MAX_ATTEMPTS; $pass++) {
            Mailer::processQueue(25, self::failingSender());

            $row = $this->pdo->query('SELECT status, attempts FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('pending', $row['status'], "بعد المحاولة {$pass} يجب أن تبقى معلّقة.");
            $this->assertSame($pass, (int) $row['attempts']);
        }

        // المحاولة الأخيرة تستنفد الرصيد.
        Mailer::processQueue(25, self::failingSender());

        $row = $this->pdo->query('SELECT status, attempts FROM mail_queue')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(Mailer::MAX_ATTEMPTS, (int) $row['attempts']);
    }

    /**
     * الرسالة المستنفَدة لا تعود إلى الدورة من تلقاء نفسها.
     *
     * بدون شرط `attempts < MAX_ATTEMPTS` تدور رسالة إلى أبد الآبدين
     * تحاول الاتصال بخادم لا يستجيب، فتُبطئ كل دفعة بعدها.
     */
    public function testAnExhaustedMessageIsNotPickedUpAgain(): void
    {
        $this->pdo->exec(
            "INSERT INTO mail_queue (to_email, to_name, subject, body, status, attempts)
             VALUES ('x@y.test', 'x', 'م', 'ج', 'failed', " . Mailer::MAX_ATTEMPTS . ')'
        );

        $result = Mailer::processQueue(25, self::succeedingSender());

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['failed']);
    }

    /**
     * الحجز المتفائل يمنع الإرسال المزدوج.
     *
     * عاملان يقرآن الصفّ نفسه؛ الحجز بشرط قيمة attempts المقروءة يجعل
     * أحدهما فقط ينجح. وهذا ليس تحسيناً تجميلياً: إرسال مكرّر لرابط
     * إعادة تعيين كلمة المرور يعني توكنين صالحين حيث يجب أن يكون واحد.
     */
    public function testAClaimedRowCannotBeClaimedTwice(): void
    {
        Mailer::queue('a@b.test', 'x', 'م', 'ج');
        $id = (int) $this->pdo->lastInsertId();

        $claim = fn(int $attempts): int => (function () use ($id, $attempts) {
            $stmt = $this->pdo->prepare(
                "UPDATE mail_queue SET attempts = attempts + 1
                  WHERE id = ? AND status = 'pending' AND attempts = ?"
            );
            $stmt->execute([$id, $attempts]);
            return $stmt->rowCount();
        })();

        // العامل الأوّل يحجز بنجاح؛ الثاني يقرأ القيمة القديمة نفسها فيفشل.
        $this->assertSame(1, $claim(0));
        $this->assertSame(0, $claim(0));
    }

    /**
     * الكنترولرز لا تستدعي send() مباشرةً.
     *
     * الطابور بلا هذه القاعدة نصف حلّ: يكفي سطر جديد واحد يستدعي
     * send() ليعيد SMTP إلى مسار الطلب في نقطة واحدة — وهي غالباً
     * النقطة الجديدة التي لم يفكّر أحد في حِملها.
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
            "كنترولرز تفتح SMTP داخل الطلب — استعمل Mailer::queue():\n  "
            . implode("\n  ", $offenders)
        );
    }
}
