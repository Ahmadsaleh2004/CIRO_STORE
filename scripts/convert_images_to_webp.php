<?php

/**
 * scripts/convert_images_to_webp.php
 * Converts the product images (jpg/jpeg/png) to WebP, shrinking anything over 1200px.
 * Run with: php scripts/convert_images_to_webp.php
 * Run overwriting existing files: php scripts/convert_images_to_webp.php --force
 *
 * Note: the originals are never touched — only new .webp copies are created.
 */

// ── Settings ───────────────────────────────────────────
$imagesDir  = __DIR__ . '/../public/images';
$maxSide    = 1200;   // The largest dimension in pixels (the longer side)
$quality    = 82;     // WebP quality (0-100)
$force      = in_array('--force', $argv ?? [], true);

// ── Check that GD supports WebP ─────────────────────
if (!function_exists('imagewebp')) {
    echo "[ERROR] GD extension does not support WebP. Please enable it in php.ini.\n";
    exit(1);
}

// ── Collect the eligible files ──────────────────────
$pattern = $imagesDir . '/*.{jpg,jpeg,png,JPG,JPEG,PNG}';
$files   = glob($pattern, GLOB_BRACE);

if (!$files) {
    echo "[INFO] No jpg/png images found in: $imagesDir\n";
    exit(0);
}

$converted = 0;
$skipped   = 0;
$errors    = 0;
$report    = [];

foreach ($files as $srcPath) {
    $ext      = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    $destPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $srcPath);

    // Skip if it already exists and --force was not passed
    if (!$force && file_exists($destPath)) {
        $skipped++;
        $report[] = "[SKIP]    " . basename($srcPath) . " (WebP already exists)";
        continue;
    }

    // Read the original image according to its type
    $img = null;
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $img = @imagecreatefromjpeg($srcPath);
    } elseif ($ext === 'png') {
        $img = @imagecreatefrompng($srcPath);
        if ($img) {
            // Preserve transparency through the conversion to WebP
            imagealphablending($img, true);
            imagesavealpha($img, true);
        }
    }

    if (!$img) {
        $errors++;
        $report[] = "[ERROR]   " . basename($srcPath) . " (failed to read image)";
        continue;
    }

    $origW = imagesx($img);
    $origH = imagesy($img);
    $origSize = @filesize($srcPath);

    // Shrink if the longer side is over 1200px
    if ($origW > $maxSide || $origH > $maxSide) {
        if ($origW >= $origH) {
            $newW = $maxSide;
            $newH = (int)round($origH * ($maxSide / $origW));
        } else {
            $newH = $maxSide;
            $newW = (int)round($origW * ($maxSide / $origH));
        }

        $resized = imagecreatetruecolor($newW, $newH);

        // Transparency support in the shrunk images
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);

        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($img);
        $img  = $resized;
        $resizeNote = " → resized {$origW}×{$origH} to {$newW}×{$newH}";
    } else {
        $resizeNote = " (no resize needed, {$origW}×{$origH})";
    }

    // Export the WebP
    $ok = imagewebp($img, $destPath, $quality);
    imagedestroy($img);

    if (!$ok) {
        $errors++;
        $report[] = "[ERROR]   " . basename($srcPath) . " (imagewebp() failed)";
        continue;
    }

    $newSize = @filesize($destPath);
    $saving  = $origSize > 0 ? round((1 - $newSize / $origSize) * 100) : 0;
    $converted++;
    $report[] = sprintf(
        "[OK]      %-45s  %s KB → %s KB (%s%% smaller)%s",
        basename($srcPath),
        number_format($origSize / 1024, 1),
        number_format($newSize  / 1024, 1),
        $saving,
        $resizeNote
    );
}

// ── Print the report ─────────────────────────────────
echo "\n======================================================\n";
echo "  WebP Conversion Report — Cairo Store\n";
echo "======================================================\n";
foreach ($report as $line) {
    echo $line . "\n";
}
echo "------------------------------------------------------\n";
echo "  Converted : $converted\n";
echo "  Skipped   : $skipped  (use --force to overwrite)\n";
echo "  Errors    : $errors\n";
echo "======================================================\n\n";
