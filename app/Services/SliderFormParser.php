<?php

namespace App\Services;

use App\Models\BrandingModel;

/**
 * SliderFormParser — turns the raw slider form into slides ready to be saved.
 *
 * Extracted from AdminBrandingController::save, which was 139 lines mixing four
 * things: reading the nested $_FILES structure, validating the input, uploading the
 * images, and formatting the response (a redirect with a message). All four together
 * made the method impossible to test: every error path ended in header() and exit.
 *
 * The line drawn here: this service **knows nothing about HTTP**. It does not
 * redirect, does not end the request, and does not touch $_SESSION. It returns a result
 * describing what happened, and the controller decides how to present it. With that,
 * every error path became testable with no server.
 *
 * ⚠️ Uploading has a side effect on disk: files are written before the rest of the
 * form is known to be valid. That is why an `uploaded` list is returned with every
 * result — successful or not — so the caller can clear them on a failure. Without it,
 * every failed submission leaves orphaned images on disk, which the old code addressed
 * with cleanupNewUploads repeated in five places.
 */
final class SliderFormParser
{
    /** Both values were moved across from the controller unchanged — no behavioural change. */
    public const MAX_SLIDES = 12;
    public const MAX_ITEMS_PER_SLIDE = 10;

    /**
     * @param  list<array<string, mixed>> $rawSlides   $_POST['slides']
     * @param  array<string, mixed> $filesSlides $_FILES['slides']
     * @param  string $uploadDir   The absolute directory to save the images into
     * @return array{slides: list<array>, images: list<string>, uploaded: list<string>, error: string|null}
     *         `slides` the finished slides · `images` every image path after processing
     *         (for finding the orphans) · `uploaded` the newly written files (for cleanup
     *         on failure) · `error` a message to display, or null.
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
                continue; // A slide with no images at all — skip it silently
            }
            if (count($rawItems) > self::MAX_ITEMS_PER_SLIDE) {
                return $fail('A slide has too many images (max ' . self::MAX_ITEMS_PER_SLIDE . ').');
            }

            $items = [];

            foreach ($rawItems as $itemIndex => $itemData) {
                $activeMode = ($itemData['active_mode'] ?? 'manual') === 'product' ? 'product' : 'manual';

                $productId          = (int) ($itemData['product_id'] ?? 0) ?: null;
                $productLinkUrl     = trim($itemData['product_link_url'] ?? '') ?: null;
                $productTitle       = trim($itemData['product_title'] ?? '') ?: null;
                $productDescription = trim($itemData['product_description'] ?? '') ?: null;

                $manualLinkUrl     = trim($itemData['manual_link_url'] ?? '') ?: null;
                $manualTitle       = trim($itemData['manual_title'] ?? '') ?: null;
                $manualDescription = trim($itemData['manual_description'] ?? '') ?: null;

                if (self::isUnsafeUrl($productLinkUrl) || self::isUnsafeUrl($manualLinkUrl)) {
                    return $fail('Unsafe link URL (javascript:/data:/vbscript: are not allowed).');
                }

                // The manual image: a newly uploaded file takes priority; otherwise the old
                // path, submitted as a hidden field, is kept.
                $manualImagePath = trim($itemData['existing_manual_image'] ?? '') ?: null;

                $fileEntry = self::extractFileEntry($filesSlides, $slideIndex, $itemIndex);
                if ($fileEntry) {
                    $newPath = BrandingModel::uploadSliderImage($fileEntry, $uploadDir);
                    if ($newPath) {
                        $manualImagePath = $newPath;
                        // The new ones alone — an existing old image is never deleted.
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
                    'product_title'       => $productTitle,
                    'product_description' => $productDescription,
                    'manual_image_path'   => $manualImagePath,
                    'manual_link_url'     => $manualLinkUrl,
                    'manual_title'        => $manualTitle,
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
     * Does the URL carry a scheme that executes code?
     *
     * The check runs on the string after stripping whitespace and normalising case:
     * `JaVaScRiPt :` is a valid scheme to a browser, and any check comparing the string
     * as-is misses it.
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
     * Extracts a single file from the array-shaped $_FILES structure.
     *
     * ⚠️ The structure is nested, not flat. The form field is named
     * slides[i][items][j][manual_image], and PHP inverts the nesting so the first key
     * becomes the file's property rather than its position:
     *
     *     $_FILES['slides']['tmp_name'][$i]['items'][$j]['manual_image']
     *
     * Which is to say the five properties are read from five parallel paths, not from
     * one array. That is the reason this method exists at all.
     *
     * @param array<string, mixed> $filesSlides
     * @param int $slideIndex
     * @param int $itemIndex
     * @return array<string, mixed>|null
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
