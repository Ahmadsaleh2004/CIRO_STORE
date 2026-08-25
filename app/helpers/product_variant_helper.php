<?php
/**
 * app/helpers/product_variant_helper.php
 * دوال مشتركة لاختيار "اللون المعروض افتراضيًا" لمنتج له عدة Variants.
 */

/**
 * أولوية الاختيار:
 *  1) لون يطابق جنس الزائر وله مخزون > 0
 *  2) لون "both" وله مخزون > 0
 *  3) أي لون له مخزون > 0
 *  4) is_default=1 أو أول لون بالقائمة
 */
function pickDisplayVariant(array $variants, ?string $visitorGender): ?array
{
    if (empty($variants)) return null;

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

function getVisitorGender(PDO $pdo): ?string
{
    if (!function_exists('isUser') || !isUser()) return null;
    $stmt = $pdo->prepare("SELECT gender FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([getCurrentUserId()]);
    $gender = $stmt->fetchColumn();
    return $gender ?: null;
}