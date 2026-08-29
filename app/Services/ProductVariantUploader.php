<?php

namespace App\Services;

use App\Models\AdminProductModel;

/**
 * ProductVariantUploader — يحوّل مدخلات فورم المنتج الخام
 * (`$_POST['variants']` + `$_FILES['variants']`) إلى مصفوفة variants
 * جاهزة للحفظ، ويرفع صور كل variant في الطريق.
 *
 * لماذا خدمة مستقلة؟
 * كان هذا المنطق ثلاث دوال private داخل AdminProductsController، وهو
 * السبب الأكبر في طول storeAdd و storeEdit. لا علاقة له بدورة الطلب
 * ولا بالصلاحيات ولا بالاستجابة — هو تحويل مدخلات ورفع ملفات، فمكانه
 * خارج الكنترولر.
 *
 * ملاحظة على التصميم: الكلاس لا يقرأ من $_POST ولا $_FILES بنفسه —
 * كل ما يحتاجه يصله وسيطاً. النسخة القديمة كانت تقرأ
 * $_POST['default_variant'] من داخلها، وهي تبعية خفية تجعل اختبارها
 * أو إعادة استخدامها من سياق آخر مستحيلاً.
 *
 * كل الدوال static اتساقاً مع بقية طبقات المشروع.
 */
class ProductVariantUploader
{
    /**
     * يبني مصفوفة الـvariants الجاهزة للحفظ.
     *
     * يتجاهل أي صف بلا اسم لون أو بسعر ≤ 0 — هذه صفوف فارغة يتركها
     * الفورم عند إضافة حقول ثم عدم ملئها.
     *
     * @param  list<array<string, mixed>> $postVariants  $_POST['variants']
     * @param  array<string, mixed> $filesVariants $_FILES['variants']
     * @param  string $uploadDir     مجلد الوجهة على القرص
     * @param  int    $defaultIndex  ترتيب الـvariant الافتراضي كما أرسله الفورم
     * @return array<int,array<string,mixed>>
     */
    public static function parse(
        array $postVariants,
        array $filesVariants,
        string $uploadDir,
        int $defaultIndex = 0
    ): array {
        $result = [];

        foreach ($postVariants as $i => $v) {
            $colorName = trim($v['color_name'] ?? '');
            $price     = (float)($v['price'] ?? 0);

            if ($colorName === '' || $price <= 0) {
                continue;
            }

            // رفع الصورة الجديدة لهذا الـ variant (إن وجدت)
            $imagePath = null;
            $fileEntry = self::extractFileEntry($filesVariants, (int)$i);
            if ($fileEntry) {
                $imagePath = AdminProductModel::uploadVariantImage($fileEntry, $uploadDir);
            }

            // الاحتفاظ بالصورة القديمة إذا لم تُرفع صورة جديدة
            if ($imagePath === null && !empty($v['existing_image'])) {
                $imagePath = $v['existing_image'];
            }

            $result[] = [
                'id'         => isset($v['id']) && (int)$v['id'] > 0 ? (int)$v['id'] : null,
                'color_name' => $colorName,
                'color_hex'  => trim($v['color_hex'] ?? '') ?: null,
                'price'      => $price,
                'discount'   => (float)($v['discount'] ?? 0),
                'stock'      => (int)($v['stock'] ?? 0),
                'gender'     => in_array($v['gender'] ?? 'both', ['male', 'female', 'both'], true)
                                    ? $v['gender'] : 'both',
                'image_path' => $imagePath,
                'is_default' => (count($result) === $defaultIndex),
                'sort_order' => count($result),
            ];
        }

        return $result;
    }

    /**
     * هل أُرسلت صورة واحدة على الأقل ضمن الـvariants؟
     *
     * $_FILES لمدخل مصفوفي يأتي بشكلين حسب عمق التسمية في الفورم، لذا
     * الفحص يتعامل مع القيمة كنص أو كمصفوفة متداخلة.
     *
     * @param array<string, mixed> $filesVariants بنية $_FILES["variants"] المتشعّبة
     */
    public static function hasAnyImage(array $filesVariants): bool
    {
        if (empty($filesVariants['tmp_name'])) {
            return false;
        }

        foreach ((array)$filesVariants['tmp_name'] as $tmpName) {
            if (is_array($tmpName)) {
                foreach ($tmpName as $t) {
                    if (!empty($t)) {
                        return true;
                    }
                }
                continue;
            }
            if (!empty($tmpName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * يحذف من القرص الصور التي رُفعت للتوّ — تُستدعى عند فشل الحفظ كي
     * لا تتراكم ملفات يتيمة لا يشير إليها أي صف في قاعدة البيانات.
     *
     * @param list<array<string, mixed>> $parsedVariants
     */
    public static function cleanup(array $parsedVariants, string $uploadDir): void
    {
        foreach ($parsedVariants as $v) {
            if (empty($v['image_path'])) {
                continue;
            }
            // basename تجرّد أي مسار، فالنتيجة محصورة في $uploadDir
            // بالبناء لا بالثقة في مصدر القيمة.
            $disk = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($v['image_path']);
            if (file_exists($disk)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use,php.lang.security.injection.tainted-filename.tainted-filename
                @unlink($disk);
            }
        }
    }

    /**
     * يستخرج ملفاً واحداً من بنية $_FILES المصفوفية بشكل يفهمه
     * AdminProductModel::uploadVariantImage.
     *
     * ⚠️ بنية $_FILES هنا متداخلة، لا مسطّحة. اسم الحقل في الفورم هو
     * variants[i][image] (راجع js/admin/products.js)، فيقلبها PHP إلى:
     *
     *     $_FILES['variants']['tmp_name'][0]['image'] = '/tmp/phpXXXX'
     *
     * أي أن tmp_name[$idx] مصفوفة مفتاحها 'image' وليس نصاً. النسخة
     * السابقة من هذه الدالة كانت تفترضها نصاً، فتقارن
     * $error !== UPLOAD_ERR_OK بينما $error مصفوفة — والمقارنة صادقة
     * دائماً، فتُرجع null دائماً. النتيجة: صور الـvariants لم تكن تُرفع
     * إطلاقاً من فورمي الإضافة والتعديل.
     *
     * نتعامل هنا مع الشكلين معاً: المتداخل (الواقع الحالي) والمسطّح
     * (لو تغيّر اسم الحقل مستقبلاً إلى variants_image[]).
     *
     * @param array<string, mixed> $filesVariants بنية $_FILES["variants"] المتشعّبة
     * @return array<string, mixed>|null
     */
    private static function extractFileEntry(array $filesVariants, int $idx): ?array
    {
        $pick = static function (string $key, mixed $default) use ($filesVariants, $idx): mixed {
            $slot = $filesVariants[$key][$idx] ?? null;
            if (is_array($slot)) {
                // الشكل المتداخل: خُذ 'image' إن وُجد، وإلا أول قيمة
                return $slot['image'] ?? (reset($slot) ?: $default);
            }
            return $slot ?? $default;
        };

        $tmpName = $pick('tmp_name', '');
        $error   = $pick('error', UPLOAD_ERR_NO_FILE);

        if (empty($tmpName) || (int)$error !== UPLOAD_ERR_OK) {
            return null;
        }

        return [
            'tmp_name' => $tmpName,
            'name'     => $pick('name', ''),
            'size'     => $pick('size', 0),
            'type'     => $pick('type', ''),
            'error'    => (int)$error,
        ];
    }
}
