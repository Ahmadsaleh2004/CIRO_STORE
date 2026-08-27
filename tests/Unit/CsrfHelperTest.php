<?php

namespace Tests\Unit;

use Tests\Support\SessionTestCase;

/**
 * csrf_helper — الحارس الوحيد بين كل نقطة POST في المشروع وبين
 * الطلبات العابرة للمواقع. 45 نقطة JSON تعتمد عليه.
 */
final class CsrfHelperTest extends SessionTestCase
{
    public function testGenerateProducesA64CharHexToken(): void
    {
        $token = generateCsrfToken();

        // 32 بايتاً عشوائياً بترميز hex = 64 محرفاً. الطول ليس تفصيلاً
        // تجميلياً: تقصيره يقلّل فضاء التخمين أُسّياً.
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateIsStableWithinTheSameSession(): void
    {
        // التوكن يجب أن يثبت داخل الجلسة، وإلا فسد كل نموذج مفتوح في
        // تبويب آخر عند كل طلب جديد.
        $this->assertSame(generateCsrfToken(), generateCsrfToken());
    }

    public function testVerifyAcceptsTheGeneratedToken(): void
    {
        $this->assertTrue(verifyCsrfToken(generateCsrfToken()));
    }

    public function testVerifyRejectsAWrongToken(): void
    {
        generateCsrfToken();
        $this->assertFalse(verifyCsrfToken(str_repeat('a', 64)));
    }

    /**
     * أخطر حالة في الملف كلّه: جلسة بلا توكن.
     *
     * لو أرجعت verifyCsrfToken(true) هنا، لصارت **كل** نقطة POST
     * مفتوحة لأي زائر بلا جلسة — وهو بالضبط ما يفعله المهاجم. الفحص
     * `!empty($_SESSION['csrf_token'])` هو ما يمنع ذلك، وهذا الاختبار
     * يحرسه.
     */
    public function testVerifyRejectsEverythingWhenSessionHasNoToken(): void
    {
        $_SESSION = [];

        $this->assertFalse(verifyCsrfToken(''));
        $this->assertFalse(verifyCsrfToken('anything'));
        $this->assertFalse(verifyCsrfToken(str_repeat('0', 64)));
    }

    public function testVerifyRejectsAnEmptyTokenEvenWhenSessionHasOne(): void
    {
        generateCsrfToken();
        $this->assertFalse(verifyCsrfToken(''));
    }

    /**
     * المقارنة يجب أن تكون بالقيمة كاملةً لا ببادئتها.
     *
     * مقارنة ساذجة بـstr_starts_with أو substr كانت ستقبل توكناً
     * مقتطعاً — وهو هجوم عملي: يخمّن المهاجم محرفاً محرفاً.
     */
    public function testVerifyRejectsATruncatedPrefixOfTheRealToken(): void
    {
        $token = generateCsrfToken();

        $this->assertFalse(verifyCsrfToken(substr($token, 0, 63)));
        $this->assertFalse(verifyCsrfToken(substr($token, 0, 32)));
        $this->assertFalse(verifyCsrfToken($token . 'x'));
    }

    public function testTwoSeparateSessionsDoNotShareAToken(): void
    {
        $first = generateCsrfToken();

        // محاكاة جلسة ثانية: نفس ما يحدث حين يفتح مستخدم آخر الموقع.
        $_SESSION = [];
        $second = generateCsrfToken();

        $this->assertNotSame($first, $second);
        $this->assertFalse(verifyCsrfToken($first));
        $this->assertTrue(verifyCsrfToken($second));
    }
}
