<?php

namespace App\Services;

use App\Models\BrandingModel;

/**
 * SliderFormParser — يحوّل نموذج السلايدر الخام إلى شرائح جاهزة للحفظ.
 *
 * استُخرج من AdminBrandingController::save، وكانت 139 سطراً تخلط أربعة
 * أشياء: قراءة بنية $_FILES المتداخلة، والتحقق من المدخلات، ورفع الصور،
 * وتنسيق الاستجابة (تحويل مع رسالة). الأربعة معاً تجعل الدالة مستحيلة
 * الاختبار: كل مسار خطأ ينتهي بـheader() وexit.
 *
 * الحدّ المرسوم هنا: هذه الخدمة **لا تعرف HTTP**. لا تحوّل ولا تُنهي
 * الطلب ولا تلمس $_SESSION. ترجع نتيجة تصف ما حدث، والكنترولر يقرّر
 * كيف يعرضها. وبهذا صار كل مسار خطأ قابلاً للاختبار بلا خادم.
 *
 * ⚠️ الرفع له أثر جانبي على القرص: ملفات تُكتب قبل أن تكتمل صحّة بقيّة
 * النموذج. ولهذا تُرجَع قائمة `uploaded` مع كل نتيجة — نجحت أو فشلت —
 * كي يمسحها المستدعي عند الفشل. بلا ذلك يترك كل إرسال فاشل صوراً
 * يتيمة على القرص، وهو ما كان الكود القديم يعالجه بـcleanupNewUploads
 * مكرَّرة في خمسة مواضع.
 */
final class SliderFormParser
{
    /** القيمتان منقولتان كما هما من الكنترولر — لا تغيير في السلوك. */
    public const MAX_SLIDES = 12;
    public const MAX_ITEMS_PER_SLIDE = 10;

    /**
     * @param  array  $rawSlides   $_POST['slides']
     * @param  array  $filesSlides $_FILES['slides']
     * @param  string $uploadDir   المجلد المطلق لحفظ الصور
     * @return array{slides: list<array>, images: list<string>, uploaded: list<string>, error: string|null}
     *         `slides` الشرائح الجاهزة · `images` كل مسارات الصور بعد
     *         المعالجة (لمقارنة اليتيمة) · `uploaded` الجديدة على القرص
     *         (للتنظيف عند الفشل) · `error` رسالة العرض أو null.
     */
    public static function parse(array $rawSlides, array $filesSlides, string $uploadDir): array
    {
        $uploaded = [];
        $images   = [];
        $slides   = [];

        $fail = static fn(string $message): array => [
            'slides'   => [],
            'images'   => [],
            'uploaded' => $uploaded,
            'error'    => $message,
        ];

        if (empty($rawSlides)) {
            return $fail('Please add at least one slide before saving.');
        }
        if (count($rawSlides) > self::MAX_SLIDES) {
            return $fail('Too many slides (max ' . self::MAX_SLIDES . ').');
        }

        foreach ($rawSlides as $slideIndex => $slideData) {
            $rawItems = $slideData['items'] ?? [];
            if (empty($rawItems)) {
                continue; // شريحة بلا صور أصلاً = تجاهلها بصمت
            }
            if (count($rawItems) > self::MAX_ITEMS_PER_SLIDE) {
                return $fail('A slide has too many images (max ' . self::MAX_ITEMS_PER_SLIDE . ').');
            }

            $items = [];

            foreach ($rawItems as $itemIndex => $itemData) {
                $activeMode = ($itemData['active_mode'] ?? 'manual') === 'product' ? 'product' : 'manual';

                $productId          = (int) ($itemData['product_id'] ?? 0) ?: null;
                $productLinkUrl     = trim($itemData['product_link_url'] ?? '') ?: null;
                $productDescription = trim($itemData['product_description'] ?? '') ?: null;

                $manualLinkUrl     = trim($itemData['manual_link_url'] ?? '') ?: null;
                $manualDescription = trim($itemData['manual_description'] ?? '') ?: null;

                if (self::isUnsafeUrl($productLinkUrl) || self::isUnsafeUrl($manualLinkUrl)) {
                    return $fail('Unsafe link URL (javascript:/data:/vbscript: are not allowed).');
                }

                // الصورة اليدوية: ملف جديد له أولوية، وإلا نحتفظ بالمسار
                // القديم المُرسَل مخفياً.
                $manualImagePath = trim($itemData['existing_manual_image'] ?? '') ?: null;

                $fileEntry = self::extractFileEntry($filesSlides, $slideIndex, $itemIndex);
                if ($fileEntry) {
                    $newPath = BrandingModel::uploadSliderImage($fileEntry, $uploadDir);
                    if ($newPath) {
                        $manualImagePath = $newPath;
                        // الجديدة وحدها — لا تُحذف أبداً صورة قديمة موجودة.
                        $uploaded[] = $newPath;
                    }
                }

                if ($activeMode === 'product' && !$productId) {
                    return $fail('Each slide image must have a product selected or an uploaded image.');
                }
                if ($activeMode === 'manual' && !$manualImagePath) {
                    return $fail('Each slide image must have a product selected or an uploaded image.');
                }

                if ($manualImagePath) {
                    $images[] = $manualImagePath;
                }

                $items[] = [
                    'active_mode'         => $activeMode,
                    'product_id'          => $productId,
                    'product_link_url'    => $productLinkUrl,
                    'product_description' => $productDescription,
                    'manual_image_path'   => $manualImagePath,
                    'manual_link_url'     => $manualLinkUrl,
                    'manual_description'  => $manualDescription,
                ];
            }

            if (!empty($items)) {
                $slides[] = ['items' => $items];
            }
        }

        if (empty($slides)) {
            return $fail('Please add at least one valid slide with at least one image.');
        }

        return ['slides' => $slides, 'images' => $images, 'uploaded' => $uploaded, 'error' => null];
    }

    /**
     * هل الرابط يحمل مخطّطاً ينفّذ كوداً؟
     *
     * الفحص على النصّ بعد تجريد المسافات وتوحيد الحالة: `JaVaScRiPt :`
     * مخطّط صالح للمتصفح، وأي فحص يقارن النصّ كما هو يفوته.
     */
    public static function isUnsafeUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $normalized = strtolower(preg_replace('/\s+/', '', $url) ?? '');

        foreach (['javascript:', 'data:', 'vbscript:'] as $scheme) {
            if (str_starts_with($normalized, $scheme)) {
                return true;
            }
        }

        return false;
    }

    /**
     * يستخرج ملفاً واحداً من بنية $_FILES المصفوفية.
     *
     * ⚠️ البنية متداخلة لا مسطّحة. اسم الحقل في الفورم هو
     * slides[i][items][j][manual_image]، وPHP يقلب التداخل فيصير المفتاح
     * الأوّل هو خاصّية الملف لا موضعه:
     *
     *     $_FILES['slides']['tmp_name'][$i]['items'][$j]['manual_image']
     *
     * أي أن الخصائص الخمس تُقرأ من خمسة مسارات متوازية، لا من مصفوفة
     * واحدة. هذا هو سبب وجود هذه الدالة أصلاً.
     */
    public static function extractFileEntry(array $filesSlides, $slideIndex, $itemIndex): ?array
    {
        $tmpName = $filesSlides['tmp_name'][$slideIndex]['items'][$itemIndex]['manual_image'] ?? null;

        if (!$tmpName) {
            return null;
        }

        return [
            'name'     => $filesSlides['name'][$slideIndex]['items'][$itemIndex]['manual_image']     ?? '',
            'type'     => $filesSlides['type'][$slideIndex]['items'][$itemIndex]['manual_image']     ?? '',
            'tmp_name' => $tmpName,
            'error'    => $filesSlides['error'][$slideIndex]['items'][$itemIndex]['manual_image']    ?? UPLOAD_ERR_NO_FILE,
            'size'     => $filesSlides['size'][$slideIndex]['items'][$itemIndex]['manual_image']     ?? 0,
        ];
    }
}
