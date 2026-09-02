<?php

/**
 * Normalise an image path so it fits the images directory, or the required form.
 */
function fixImagePath(?string $path): string
{
    // 1. An empty path
    if (empty(trim((string)$path))) {
        return URLROOT . '/img/no-image.png';
    }

    $path = trim($path);

    // 2. The image is an external URL
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    // 3. Strip a stray leading slash
    $cleanPath = ltrim($path, '/');

    // 4. When the value is only a file name (ps5.jpg, say), prefix images/ automatically
    if (!str_contains($cleanPath, '/')) {
        $cleanPath = 'images/' . $cleanPath;
    }

    // ⚠️ Encoding the path is **required**, not cosmetic.
    //
    // The image file names in this project contain spaces: "apple watch.webp",
    // "ps4 controller.jpg" and "nintendo switch lite.jpg". And a space in a URL inside
    // a srcset is **a separator between candidates**, not an ordinary character:
    //
    //     <source srcset="…/images/apple watch.webp">
    //
    // the browser reads it as two candidates, "…/images/apple" and "watch.webp",
    // rejects both, and drops the image. The browser said so verbatim:
    //     Dropped srcset candidate "…/images/apple"
    // twelve times in a single load of the home page.
    //
    // Which means the WebP versions — the entire point of <picture> — worked for no
    // image whose name contained a space. And the page looks fine because the fallback
    // <img> works, so the fault passes silently and a heavier jpg is served in place of
    // the webp.
    //
    // rawurlencode per segment: encoding the whole path would have turned the slashes
    // themselves into %2F and broken it.
    $encoded = implode('/', array_map('rawurlencode', explode('/', $cleanPath)));

    return URLROOT . '/' . $encoded;
}

/**
 * Returns the path of an image's WebP counterpart if it actually exists on disk, and
 * null otherwise.
 */
function getWebpPath(?string $path): ?string
{
    if (empty(trim((string)$path))) {
        return null;
    }
    $original = fixImagePath($path);
    $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $original);
    if ($webpPath === $original) {
        return null; // The extension is not jpg or png in the first place
    }

    // Turn the full URL into a real path on disk, to confirm the file exists.
    //
    // ⚠️ rawurldecode is required: fixImagePath now encodes the path (spaces in image
    // names break srcset), and "apple%20watch.webp" does not exist on disk. Without the
    // decode, file_exists fails for every image whose name contains a space, null is
    // returned and the WebP version disappears — the same fault, through the other door.
    $relative = rawurldecode(str_replace(URLROOT, '', $webpPath));
    $diskPath = rtrim(ROOTPATH . '/public', '/') . $relative;

    return file_exists($diskPath) ? $webpPath : null;
}

/**
 * Check whether the user is signed in.
 */
function isUserLoggedIn(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['user_id']);
}

/**
 * Check password strength:
 * at least 8 characters, with an upper-case letter, a lower-case letter, a digit and a
 * symbol. The same logic as the old version.
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
 * Returns the count of new orders (is_notified=0) and new support messages
 * (is_notified=0), respecting the current admin's permissions (can_manage_orders /
 * can_manage_support).
 * Moved across with the same logic from admin_notif_helper.php in the old project.
 *
 * It only works inside the admin session context (admin_session) — hasPermission()
 * assumes that.
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
 * An emoji per product category, with a fallback for any new category.
 *
 * The map used to be written out in views/home.php and views/product/product.php. The
 * map alone is what was duplicated — the markup around it differs entirely between the
 * two pages (<a class="btn"> links on the home page against <option> inside a <select>
 * on the products page), which is why this is a function rather than a partial: a shared
 * partial would have forced two markups with nothing in common into one template.
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

/**
 * Whether a URL's host is this machine rather than somewhere on the network.
 *
 * It answers one question — "is this deployment a developer's own copy?" — and two
 * separate defects turned on it, which is why it is here rather than private to either
 * caller:
 *
 *   · GOOGLE_REDIRECT_URI carried the development address to the live server, so Google
 *     was told to return every visitor to the developer's machine
 *     (AuthController::resolveGoogleRedirectUri).
 *
 *   · hCaptcha refuses to issue a token on localhost — the widget itself prints
 *     "Warning: localhost detected. Please use a valid host." — so a captcha required
 *     there can never be satisfied (AdminAuthController::verifyCaptcha).
 *
 * ⚠️ Pass APP_URL (or URLROOT), never $_SERVER['HTTP_HOST']. The Host header is sent by
 * the client and can say anything it likes, so a security decision resting on it can be
 * turned off by whoever it is protecting against. APP_URL is server configuration.
 */
function isLocalUrl(string $url): bool
{
    $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

    return $host === 'localhost'
        || $host === '127.0.0.1'
        || $host === '::1'
        || str_ends_with($host, '.localhost');
}
