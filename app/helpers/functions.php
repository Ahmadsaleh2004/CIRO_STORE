<?php

/**
 * إصلاح مسار الصور لتناسب مجلد images أو المسارات المطلوبة
 */
function fixImagePath(?string $path): string
{
    // 1. إذا كان المسار فارغاً
    if (empty(trim((string)$path))) {
        return URLROOT . '/img/no-image.png';
    }

    $path = trim($path);

    // 2. إذا كانت الصورة رابطاً خارجيين
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    // 3. تنظيف المسار من السلاش الشارد في البداية
    $cleanPath = ltrim($path, '/');

    // 4. إذا كانت القيمة اسم الملف فقط (مثل ps5.jpg) نقوم بإضافة مجلد images/ تلقائياً
    if (!str_contains($cleanPath, '/')) {
        $cleanPath = 'images/' . $cleanPath;
    }

    return URLROOT . '/' . $cleanPath;
}

/**
 * ترجع مسار نسخة WebP المقابلة لصورة معينة إذا كانت موجودة فعليًا على القرص، وإلا null.
 */
function getWebpPath(?string $path): ?string
{
    if (empty(trim((string)$path))) {
        return null;
    }
    $original = fixImagePath($path);
    $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $original);
    if ($webpPath === $original) {
        return null; // الامتداد مش jpg/png أصلاً
    }

    // تحويل الرابط الكامل لمسار فعلي على القرص للتأكد من وجود الملف
    $relative = str_replace(URLROOT, '', $webpPath);
    $diskPath = rtrim(ROOTPATH . '/public', '/') . $relative;
    return file_exists($diskPath) ? $webpPath : null;
}

/**
 * التحقق من تسجيل دخول المستخدم
 */
function isUserLoggedIn(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['user_id']);
}

/**
 * التحقق من قوة كلمة المرور:
 * 8 أحرف على الأقل، حرف كبير، حرف صغير، رقم، ورمز خاص.
 * نفس منطق النسخة القديمة.
 */
function isStrongPassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[\W_]/', $password);
}

/**
 * يرجّع عدد الطلبات الجديدة (is_notified=0) وعدد رسائل الدعم الجديدة (is_notified=0)
 * مع احترام صلاحيات الأدمن الحالي (can_manage_orders / can_manage_support).
 * منقول بنفس المنطق من admin_notif_helper.php بالمشروع القديم.
 *
 * تَعمل فقط في سياق جلسة الأدمن (admin_session) — hasPermission() تفترض ذلك.
 *
 * @return array{orders:int, messages:int}
 */
function getAdminUnreadCounters(): array
{
    $newOrders = $newMessages = 0;
    try {
        $db = \App\Core\Database::connect();
        if (hasPermission('can_manage_orders')) {
            $newOrders = (int)$db->query("SELECT COUNT(*) FROM orders WHERE is_notified=0")->fetchColumn();
        }
        if (hasPermission('can_manage_support')) {
            $newMessages = (int)$db->query("SELECT COUNT(*) FROM contact_messages WHERE is_notified=0")->fetchColumn();
        }
    } catch (\Exception $e) {
        error_log('getAdminUnreadCounters Error: ' . $e->getMessage());
    }
    return ['orders' => $newOrders, 'messages' => $newMessages];
}
/**
 * رمز تعبيري لكل تصنيف منتجات، وشعار احتياطي لأي تصنيف جديد.
 *
 * كانت الخريطة مكتوبة حرفياً في views/home.php و views/product/product.php.
 * الخريطة وحدها هي المكرَّر — الماركب حولها مختلف تماماً بين الصفحتين
 * (روابط <a class="btn"> في الرئيسية مقابل <option> داخل <select> في
 * صفحة المنتجات)، ولهذا هي دالة لا partial: partial مشترك كان سيجبر
 * ماركبين لا يشبه أحدهما الآخر على قالب واحد.
 */
function categoryEmoji(string $category): string
{
    return match ($category) {
        'phone'       => '📱',
        'computer'    => '💻',
        'accessories' => '🎧',
        'gaming'      => '🎮',
        default       => '🏷️',
    };
}
