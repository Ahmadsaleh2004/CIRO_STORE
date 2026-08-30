<?php

/**
 * app/helpers/product_variant_helper.php
 * Shared functions for choosing the default displayed colour of a product with several
 * variants.
 */

/**
 * The order of preference:
 *  1) a colour matching the visitor's gender with stock > 0
 *  2) a "both" colour with stock > 0
 *  3) any colour with stock > 0
 *  4) is_default=1, or the first colour in the list
 *
 * @param list<array<string, mixed>> $variants
 * @return array<string, mixed>|null
 */
function pickDisplayVariant(array $variants, ?string $visitorGender): ?array
{
    if (empty($variants)) {
        return null;
    }

    if ($visitorGender === 'male' || $visitorGender === 'female') {
        foreach ($variants as $v) {
            if (($v['gender_category'] ?? null) === $visitorGender && (int)($v['stock_quantity'] ?? 0) > 0) {
                return $v;
            }
        }
    }

    foreach ($variants as $v) {
        if (($v['gender_category'] ?? null) === 'both' && (int)($v['stock_quantity'] ?? 0) > 0) {
            return $v;
        }
    }

    foreach ($variants as $v) {
        if ((int)($v['stock_quantity'] ?? 0) > 0) {
            return $v;
        }
    }

    foreach ($variants as $v) {
        if ((int)($v['is_default'] ?? 0) === 1) {
            return $v;
        }
    }

    return $variants[0];
}

/**
 * The current visitor's gender from their profile, or null for a signed-out visitor.
 *
 * $pdo is optional: pass it when a connection is already available in context;
 * otherwise the function opens its own. It was made optional so controllers need not
 * import Database merely to pass it along.
 */
function getVisitorGender(?PDO $pdo = null): ?string
{
    // The guard is deliberate and stays: this function may be called from the CLI
    // scripts under scripts/, which load the helpers in their own order, so
    // auth_helper.php cannot be assumed loaded. The project's other function_exists
    // guards protected functions that were always loaded, and were removed.
    if (!function_exists('isUser') || !isUser()) {
        return null;
    }

    $pdo ??= \App\Core\Database::connect();
    $stmt = $pdo->prepare("SELECT gender FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([getCurrentUserId()]);
    $gender = $stmt->fetchColumn();
    return $gender ?: null;
}
