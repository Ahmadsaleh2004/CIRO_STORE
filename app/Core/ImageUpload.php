<?php

namespace App\Core;

/**
 * ImageUpload — استقبال صورة مرفوعة وحفظها باسم آمن.
 *
 * وُجد هذا الكلاس لأن المنطق كان مكتوباً مرّتين: في
 * AdminProductModel::uploadVariantImage و BrandingModel::uploadSliderImage.
 * والتعليق فوق الثانية كان يقول ذلك صراحةً — «نسخة مطابقة لمنطق
 * AdminProductModel». نسختان متطابقتان تعنيان أن أي تشديد أمني يُطبَّق
 * على واحدة وتبقى الأخرى كما كانت، وهو ما ظهر فعلاً عند إضافة حدّ
 * الحجم: كان سيُكتب مرّتين أو يُنسى في إحداهما.
 *
 * الفرق الوحيد بين النسختين كان بادئة اسم الملف، فصارت وسيطاً.
 */
final class ImageUpload
{
    /**
     * أقصى حجم مقبول لصورة واحدة، بالبايت.
     *
     * ⚠️ لم يكن هناك حدّ إطلاقاً في كود التطبيق — الأمر كلّه متروك
     * لـupload_max_filesize في php.ini، وهي إعداد خادم قد يختلف بين
     * بيئة وأخرى ولا يعرفه من يقرأ الكود. خمسة ميغابايت أوسع بكثير من
     * أي صورة منتج معقولة، وضيّق بما يكفي لئلّا يملأ رفعٌ متكرّر القرص.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * خريطة واحدة تحكم القبول والامتداد معاً.
     *
     * ⚠️ كانت قائمة المسموح منفصلة عن أذرع تحديد الامتداد، ولا شيء
     * يربطهما: إضافة 'image/avif' إلى القائمة بلا ذراع مقابل كانت تحفظ
     * الملف بامتداد .jpg الافتراضي **بصمت** — صورة avif باسم jpg يرفضها
     * المتصفح. الخريطة تجعل النسيان مستحيلاً: ما ليس فيها مفتاحاً
     * يُرفض قبل أن يُسأل عن امتداده.
     */
    private const EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * يتحقق من الملف ويحفظه، ويُرجع المسار النسبي أو null.
     *
     * الامتداد يُشتقّ من **محتوى** الملف عبر mime_content_type لا من
     * اسمه: الاسم يأتي من العميل، والمحتوى لا. والاسم المحفوظ عشوائي
     * كلياً، فلا يتحكّم الرافع بمسار ما يُكتب على القرص.
     *
     * @param  array<string, mixed> $fileEntry مصفوفة ملف واحدة من $_FILES
     * @param  string $uploadDir المجلد المطلق
     * @param  string $prefix    بادئة اسم الملف (product_ / slider_)
     * @return string|null       المسار النسبي (images/xxx.jpg) أو null
     */
    public static function store(array $fileEntry, string $uploadDir, string $prefix): ?string
    {
        if (empty($fileEntry['tmp_name']) || ($fileEntry['error'] ?? null) !== UPLOAD_ERR_OK) {
            return null;
        }

        // الحجم يُقرأ من القرص لا من $_FILES['size']: تلك قيمة يرسلها
        // العميل في الطلب ويمكن أن تكذب، وfilesize تقيس ما وصل فعلاً.
        $bytes = @filesize($fileEntry['tmp_name']);
        if ($bytes === false || $bytes > self::MAX_BYTES) {
            return null;
        }

        $mime = mime_content_type($fileEntry['tmp_name']);
        if (!isset(self::EXT_BY_MIME[$mime])) {
            return null;
        }

        $filename = $prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . self::EXT_BY_MIME[$mime];
        $dest     = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($fileEntry['tmp_name'], $dest)) {
            return null;
        }

        return 'images/' . $filename;
    }
}
