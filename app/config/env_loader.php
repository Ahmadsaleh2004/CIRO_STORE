<?php

/**
 * app/config/env_loader.php
 * قارئ .env بسيط — سطراً بسطر، بلا تفسير PHP لأي أقواس أو كلمات محجوزة.
 *
 * يُحمَّل من config.php قبل أي شيء آخر، فكل نقطة دخول (public/index.php
 * وكل سكربت في scripts/) تحصل على البيئة بلا أن تتذكّر استدعاءه.
 */

function loadEnv(string $path): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim(trim($value), "\"'");

        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

/**
 * يقرأ متغيّر بيئة مع قيمة افتراضية.
 *
 * ⚠️ **القيمة الفارغة تُعامَل كغائبة** فتُرجَع القيمة الافتراضية. هذا
 * مقصود لا سهو: مفتاح مكتوب بلا قيمة (`APP_ENV=`) هو في الواقع مفتاح
 * لم يُملأ بعد — نسخة من .env.example لم تكتمل. ولو أُرجعت "" لكان
 * `env('APP_ENV', 'production')` يعطي نصّاً فارغاً لا يساوي
 * 'production'، فيُفتح وضع التنقيح على خادم إنتاج **بصمت**. وهذا
 * بالضبط ما يجب ألّا يحدث.
 *
 * الاستثناء الوحيد اليوم DB_PASSWORD الفارغ (كلمة سر root على XAMPP)،
 * وقيمته الافتراضية '' أيضاً — فالنتيجة واحدة ولا شيء يتغيّر.
 *
 * وكانت النسخة السابقة تحمل عطلاً في أسبقية المعاملات:
 *     return $_ENV[$key] ?? getenv($key) ?: $default;
 * تُقرأ `$_ENV[$key] ?? (getenv($key) ?: $default)` — أي أن ?? تُرجع
 * "" الفارغة كقيمة صالحة وتتخطّى الافتراضي تماماً. لم يظهر العطل لأن
 * أحداً لم يكن يستدعي الدالة إطلاقاً (صفر مستدعٍ، مفحوص).
 *
 * ── لماذا @template لا mixed ─────────────────────────────────
 *
 * العائد ليس `mixed` بل **أحد شيئين بالضبط**: نصّ المتغيّر إن وُجد، أو
 * القيمة الافتراضية كما هي. و`mixed` تُضيّع نصف هذه المعلومة، فيصير
 * `env('DB_PORT', 3306)` من زاوية المحلّل «شيءٌ ما» بينما هو
 * `string|int` يقيناً.
 *
 * والقالب يحفظ نوع الافتراضي: من مرّر `null` يستقبل `string|null`،
 * ومن مرّر `'production'` يستقبل `string` — بلا فحص زائد عند المستدعي.
 *
 * @template TDefault
 * @param  TDefault $default
 * @return string|TDefault
 */
function env(string $key, $default = null)
{
    // ?? تتخطّى null أصلاً، وgetenv تُرجع string|false — فلا سبيل
    // لأن تكون $value هنا null. الفحص عنها كان شرطاً لا يتحقّق أبداً.
    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

/**
 * يقرأ متغيّر بيئة كقيمة منطقية.
 *
 * لماذا دالة مستقلة؟ لأن كل قيم .env نصوص، و`(bool) "false"` تساوي
 * **true** في PHP. فمفتاح APP_DEBUG=false كان سيفتح وضع التنقيح لا
 * يغلقه — وهو أخطر نوع من الأعطال: يعمل عكس ما يقرأه القارئ.
 */
function envBool(string $key, bool $default = false): bool
{
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}
