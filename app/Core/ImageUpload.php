<?php

namespace App\Core;

/**
 * ImageUpload — accepting an uploaded image and saving it under a safe name.
 *
 * This class exists because the logic was written twice: in
 * AdminProductModel::uploadVariantImage and BrandingModel::uploadSliderImage. The
 * comment above the second said so outright — "an identical copy of
 * AdminProductModel's logic". Two identical copies mean any security tightening
 * gets applied to one while the other stays as it was, which is exactly what came
 * up when the size limit was added: it would have been written twice, or forgotten
 * in one of them.
 *
 * The only difference between the two copies was the file-name prefix, so that
 * became a parameter.
 */
final class ImageUpload
{
    /**
     * The largest accepted size for a single image, in bytes.
     *
     * ⚠️ There was no limit at all in the application code — the whole matter was
     * left to upload_max_filesize in php.ini, a server setting that differs between
     * environments and is invisible to whoever reads the code. Five megabytes is far
     * wider than any sensible product image, and narrow enough that repeated uploads
     * do not fill the disk.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * One map governing both acceptance and extension.
     *
     * ⚠️ The allow-list used to be separate from the branches that chose the
     * extension, with nothing tying them together: adding 'image/avif' to the list
     * without a matching branch saved the file under the default .jpg extension
     * **silently** — an avif image named jpg, which the browser refuses. The map
     * makes forgetting impossible: whatever is not a key in it is rejected before
     * anything asks about its extension.
     */
    private const EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * Validates the file and saves it, returning the relative path or null.
     *
     * The extension is derived from the file's **contents** through
     * mime_content_type rather than from its name: the name comes from the client,
     * the contents do not. And the stored name is entirely random, so the uploader
     * has no control over the path written to disk.
     *
     * @param  array<string, mixed> $fileEntry A single file entry from $_FILES
     * @param  string $uploadDir The absolute directory
     * @param  string $prefix    The file-name prefix (product_ / slider_)
     * @return string|null       The relative path (images/xxx.jpg), or null
     */
    public static function store(array $fileEntry, string $uploadDir, string $prefix): ?string
    {
        if (empty($fileEntry['tmp_name']) || ($fileEntry['error'] ?? null) !== UPLOAD_ERR_OK) {
            return null;
        }

        // The size is read from disk rather than from $_FILES['size']: that value is
        // sent by the client in the request and can lie, while filesize measures what
        // actually arrived.
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
