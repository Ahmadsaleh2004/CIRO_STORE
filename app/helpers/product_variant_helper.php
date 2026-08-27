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
 * جنس الزائر الحالي من ملفه الشخصي، أو null للزائر غير المسجّل.
 *
 * $pdo اختياري: تُمرَّر عند وجود اتصال جاهز في السياق، وإلا تفتح الدالة
 * اتصالها بنفسها. جُعل اختيارياً كي لا تضطر الكنترولرز لاستيراد
 * Database لمجرّد تمريره.
 */
function getVisitorGender(?PDO $pdo = null): ?string
{
    // الحارس مقصود ويبقى: هذه الدالة قد تُستدعى من سكربتات CLI في
    // scripts/ التي تحمّل الهيلبرز بترتيبها الخاص، فلا نفترض أن
    // auth_helper.php محمَّل. باقي حُرّاس function_exists في المشروع
    // كانت تحرس دوالّ محمَّلة دائماً وأُزيلت.
    if (!function_exists('isUser') || !isUser()) {
        return null;
    }

    $pdo ??= \App\Core\Database::connect();
    $stmt = $pdo->prepare("SELECT gender FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([getCurrentUserId()]);
    $gender = $stmt->fetchColumn();
    return $gender ?: null;
}
