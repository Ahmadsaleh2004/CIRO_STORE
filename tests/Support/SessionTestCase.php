<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * أساس الاختبارات التي تلمس $_SESSION.
 *
 * الجلسة تُبدأ **مرّة واحدة** لكل تشغيل، ثم يُفرَّغ محتواها بين
 * الاختبارات. السبب: session_start() لا يمكن استدعاؤها مرّتين في
 * العملية نفسها، وsession_destroy() تجعل الاستدعاء التالي يفشل —
 * فالنمط الوحيد الصالح هو جلسة واحدة ومحتوى نظيف.
 *
 * ويهمّ هذا تحديداً لأن csrf_helper و auth_helper يستدعيان
 * session_start() بأنفسهما عند غياب الجلسة، فترك الحالة متسخة بين
 * اختبارين يجعل النتيجة تعتمد على الترتيب.
 */
abstract class SessionTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            // معالج ملفات في مجلد مؤقت — لا نلوّث جلسات الخادم الحقيقية.
            session_start();
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }
}
