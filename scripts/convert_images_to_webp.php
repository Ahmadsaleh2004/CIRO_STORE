<?php
/**
 * scripts/convert_images_to_webp.php
 * سكربت تحويل صور المنتجات (jpg/jpeg/png) إلى WebP مع تصغير أي صورة > 1200px
 * التشغيل: php scripts/convert_images_to_webp.php
 * التشغيل مع تجاوز الملفات الموجودة: php scripts/convert_images_to_webp.php --force
 *
 * ملاحظة: الصور الأصلية لا تُمس — فقط نسخ .webp جديدة تُنشأ.
 */

// ── إعدادات ────────────────────────────────────────────
$imagesDir  = __DIR__ . '/../public/images';
$maxSide    = 1200;   // أقصى بُعد بالبيكسل (أطول ضلع)
$quality    = 82;     // جودة WebP (0-100)
$force      = in_array('--force', $argv ?? [], true);

// ── تحقق من دعم GD لـ WebP ──────────────────────────
if (!function_exists('imagewebp')) {
    echo "[ERROR] GD extension does not support WebP. Please enable it in php.ini.\n";
    exit(1);
}

// ── جمع الملفات المؤهلة ─────────────────────────────
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

    // تجاوز إذا موجود مسبقاً ولم يُمرَّر --force
    if (!$force && file_exists($destPath)) {
        $skipped++;
        $report[] = "[SKIP]    " . basename($srcPath) . " (WebP already exists)";
        continue;
    }

    // قراءة الصورة الأصلية بحسب نوعها
    $img = null;
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $img = @imagecreatefromjpeg($srcPath);
    } elseif ($ext === 'png') {
        $img = @imagecreatefrompng($srcPath);
        if ($img) {
            // الحفاظ على الشفافية عند التحويل لـ WebP
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

    // تصغير إذا كان أطول ضلع > 1200px
    if ($origW > $maxSide || $origH > $maxSide) {
        if ($origW >= $origH) {
            $newW = $maxSide;
            $newH = (int)round($origH * ($maxSide / $origW));
        } else {
            $newH = $maxSide;
            $newW = (int)round($origW * ($maxSide / $origH));
        }

        $resized = imagecreatetruecolor($newW, $newH);

        // دعم الشفافية في الصور المصغّرة
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

    // تصدير WebP
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

// ── طباعة التقرير ────────────────────────────────────
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
