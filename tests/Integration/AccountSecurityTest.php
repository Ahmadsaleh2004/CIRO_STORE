<?php

namespace Tests\Integration;

use App\Models\UserModel;
use Tests\Support\DatabaseTestCase;

/**
 * أمان الحساب: رموز الاستعادة وتفعيل البريد وعدّاد المحاولات.
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا هذا الملف
 * ══════════════════════════════════════════════════════════════
 *
 * UserModel يبلغ 716 سطراً ولم يكن يغطّيه اختبار واحد. وهو يحمل
 * الطبقة التي إن انكسرت لا يُسرَق منتج بل **حساب**: رابط استعادة
 * كلمة السر.
 *
 * وخصائص هذه الطبقة كلّها من النوع الذي يعمل ظاهرياً وهو مكسور:
 * رمزٌ لا ينتهي يبقى صالحاً إلى الأبد، ورمزٌ يُقبل مرّتين يعني أن من
 * قرأ بريداً قديماً يدخل اليوم، ورمز حسابٍ يفتح حساباً آخر. لا شيء
 * من ذلك يظهر في الاستعمال العادي — كلّه يظهر يوم يُستغَلّ.
 *
 * الاختبارات هنا تثبت الخصائص لا الأسطر: الانتهاء، والاستهلاك مرّة
 * واحدة، وارتباط الرمز بصاحبه، وأن الرمز الخام لا يُخزَّن.
 */
final class AccountSecurityTest extends DatabaseTestCase
{
    private function makeUser(string $email = ''): array
    {
        $email = $email !== '' ? $email : 'user' . uniqid() . '@example.com';

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)'
        );
        $stmt->execute(['Test User', $email, password_hash('secret123', PASSWORD_BCRYPT)]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'email' => $email];
    }

    /** يُقدّم انتهاء رمز الاستعادة إلى الماضي — محاكاة مرور الوقت. */
    private function expirePasswordReset(string $email): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE email = ?'
        );
        $stmt->execute([$email]);
    }

    // ════════════════════════════════════════════════════════
    // رمز استعادة كلمة السر
    // ════════════════════════════════════════════════════════

    public function testAFreshResetTokenValidatesOnce(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createPasswordReset($user['email']);

        $this->assertNotNull($token);
        $this->assertTrue(UserModel::validatePasswordResetToken($user['email'], $token));
    }

    /**
     * الخاصية الأهمّ في هذا الملف: **الرمز الخام لا يُخزَّن**.
     *
     * تسريب قاعدة البيانات — نسخة احتياطية ضائعة، أو حقن SQL في مسار
     * آخر — يجب ألّا يسلّم المهاجم روابط استعادة صالحة لكل مستخدم طلب
     * واحداً. الجدول يحمل sha256 وحده، وهو لا يُعكَس.
     */
    public function testTheRawTokenIsNeverStored(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createPasswordReset($user['email']);

        $stmt = $this->pdo->prepare('SELECT token_hash FROM password_resets WHERE email = ?');
        $stmt->execute([$user['email']]);
        $stored = (string) $stmt->fetchColumn();

        $this->assertNotSame($token, $stored, 'الرمز الخام مخزَّن في القاعدة.');
        $this->assertSame(hash('sha256', $token), $stored);
    }

    public function testAConsumedTokenCannotBeUsedAgain(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createPasswordReset($user['email']);

        $this->assertTrue(UserModel::validatePasswordResetToken($user['email'], $token));

        UserModel::consumePasswordResetToken($user['email'], $token);

        // رابطٌ يُقبل مرّتين يعني أن من قرأ بريداً قديماً — على جهاز
        // مشترك، أو في صندوق مخترَق لاحقاً — يدخل اليوم.
        $this->assertFalse(UserModel::validatePasswordResetToken($user['email'], $token));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createPasswordReset($user['email']);

        $this->expirePasswordReset($user['email']);

        $this->assertFalse(UserModel::validatePasswordResetToken($user['email'], $token));
    }

    public function testATokenDoesNotOpenAnotherAccount(): void
    {
        $victim  = $this->makeUser('victim@example.com');
        $attacker = $this->makeUser('attacker@example.com');

        $attackerToken = UserModel::createPasswordReset($attacker['email']);

        // الرمز مرتبط بالبريد في نفس عبارة SELECT. بلا ذلك يصير أي رمز
        // صالح مفتاحاً لأي حساب — وهو أسوأ ما يمكن أن يحدث هنا.
        $this->assertFalse(UserModel::validatePasswordResetToken($victim['email'], $attackerToken));
    }

    public function testAUserTokenIsNotValidAsAnAdminToken(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createPasswordReset($user['email'], 'user');

        // جدول واحد يخدم المستخدمين والأدمنية معاً، وعمود user_type هو
        // كل ما يفصلهما. لو أُهمل في التحقّق، صار رمزُ زبونٍ يعيد ضبط
        // كلمة سرّ أدمن يحمل البريد نفسه.
        $this->assertFalse(UserModel::validatePasswordResetToken($user['email'], $token, 'admin'));
    }

    public function testAWrongTokenIsRejected(): void
    {
        $user = $this->makeUser();
        UserModel::createPasswordReset($user['email']);

        $this->assertFalse(
            UserModel::validatePasswordResetToken($user['email'], str_repeat('a', 64))
        );
    }

    // ════════════════════════════════════════════════════════
    // تفعيل البريد
    // ════════════════════════════════════════════════════════

    public function testVerifyingAnEmailMarksTheUserAndBurnsTheToken(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createEmailVerification($user['id']);

        $this->assertNotNull($token);
        $this->assertFalse(UserModel::isEmailVerified($user['id']));

        $this->assertTrue(UserModel::verifyEmailToken($token));
        $this->assertTrue(UserModel::isEmailVerified($user['id']));

        // الرمز يُحذف بعد استعماله، فإعادة فتح الرابط لا تفعل شيئاً.
        $this->assertSame(0, $this->countRows('email_verifications'));
        $this->assertFalse(UserModel::verifyEmailToken($token));
    }

    public function testAnExpiredVerificationTokenIsRejected(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createEmailVerification($user['id']);

        $stmt = $this->pdo->prepare(
            'UPDATE email_verifications SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id = ?'
        );
        $stmt->execute([$user['id']]);

        $this->assertFalse(UserModel::verifyEmailToken($token));
        $this->assertFalse(UserModel::isEmailVerified($user['id']));
    }

    // ════════════════════════════════════════════════════════
    // عدّاد محاولات الدخول
    // ════════════════════════════════════════════════════════

    public function testRateLimitingKicksInAfterTheThreshold(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 4; $i++) {
            UserModel::logLoginAttempt($user['email'], false);
        }
        $this->assertFalse(UserModel::isRateLimited($user['email']));

        UserModel::logLoginAttempt($user['email'], false);
        $this->assertTrue(UserModel::isRateLimited($user['email']));
    }

    public function testRateLimitingIsPerAccountNotGlobal(): void
    {
        $target    = $this->makeUser('target@example.com');
        $bystander = $this->makeUser('bystander@example.com');

        for ($i = 0; $i < 5; $i++) {
            UserModel::logLoginAttempt($target['email'], false);
        }

        $this->assertTrue(UserModel::isRateLimited($target['email']));

        // عدّادٌ عامّ كان سيجعل مهاجماً واحداً يقفل المتجر على كل
        // زبائنه بخمس محاولات — حرمانُ خدمةٍ بتكلفة لا شيء.
        $this->assertFalse(UserModel::isRateLimited($bystander['email']));
    }

    public function testASuccessfulLoginIsNotCountedAgainstTheUser(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 10; $i++) {
            UserModel::logLoginAttempt($user['email'], true);
        }

        $this->assertFalse(UserModel::isRateLimited($user['email']));
        $this->assertSame(0, UserModel::getFailedAttemptsCount($user['email']));
    }

    public function testOldFailuresFallOutOfTheWindow(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            UserModel::logLoginAttempt($user['email'], false);
        }
        $this->assertTrue(UserModel::isRateLimited($user['email']));

        // النافذة متحرّكة لا قفلٌ دائم: من نسي كلمة سرّه ثم عاد بعد
        // ساعة يجب أن يدخل، لا أن يجد حسابه مقفلاً إلى الأبد.
        $stmt = $this->pdo->prepare(
            'UPDATE login_attempts SET attempted_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE email = ?'
        );
        $stmt->execute([$user['email']]);

        $this->assertFalse(UserModel::isRateLimited($user['email']));
    }
}
