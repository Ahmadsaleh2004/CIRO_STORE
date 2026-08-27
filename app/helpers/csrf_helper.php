<?php

/**
 * app/helpers/csrf_helper.php
 * إدارة CSRF Token — توليد + تحقق.
 *
 * ملاحظة: لا يستدعي هذا الملف session_start() على مستوى الملف.
 * يُفترض أن الجلسة المناسبة بدأت مسبقاً (PHPSESSID أو admin_session)
 * قبل استدعاء generateCsrfToken() أو verifyCsrfToken().
 */

function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
