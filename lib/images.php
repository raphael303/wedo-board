<?php
declare(strict_types=1);

const WB_IMAGE_MAX_BYTES = 10 * 1024 * 1024;
const WB_IMAGE_MAX_SIDE = 1600;
const WB_THUMB_WIDTH = 480;

/**
 * Prüft, verkleinert und speichert ein hochgeladenes Bild.
 * Gibt den Dateinamen zurück oder wirft eine Ausnahme mit einer verständlichen Meldung.
 */
function wb_store_image(array $file, string $bkey): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('upload_failed');
    }
    if (($file['size'] ?? 0) > WB_IMAGE_MAX_BYTES) {
        throw new RuntimeException('image_too_large');
    }
    $tmp = $file['tmp_name'];
    $info = @getimagesize($tmp);
    if (!$info) throw new RuntimeException('image_invalid');
    [$w, $h, $type] = $info;
    if ($w < 1 || $h < 1 || $w * $h > 40_000_000) throw new RuntimeException('image_invalid');

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG => @imagecreatefrompng($tmp),
        IMAGETYPE_GIF => @imagecreatefromgif($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        default => false,
    };
    if (!$src) throw new RuntimeException('image_invalid');

    // EXIF-Drehung bei JPEG berücksichtigen.
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $o = (int)($exif['Orientation'] ?? 1);
        $angle = match ($o) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($angle !== 0) {
            $rot = imagerotate($src, $angle, 0);
            if ($rot) { imagedestroy($src); $src = $rot; $w = imagesx($src); $h = imagesy($src); }
        }
    }

    $dir = WB_ROOT . '/uploads/' . $bkey;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('upload_failed');

    $hasAlpha = in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    $useWebp = function_exists('imagewebp');
    $ext = $useWebp ? 'webp' : ($hasAlpha ? 'png' : 'jpg');
    $name = bin2hex(random_bytes(8)) . '.' . $ext;

    wb_write_scaled($src, $w, $h, WB_IMAGE_MAX_SIDE, $dir . '/' . $name, $ext, $hasAlpha);
    wb_write_scaled($src, $w, $h, WB_THUMB_WIDTH, $dir . '/thumb_' . $name, $ext, $hasAlpha);
    imagedestroy($src);
    return $name;
}

function wb_write_scaled(GdImage $src, int $w, int $h, int $maxSide, string $path, string $ext, bool $alpha): void {
    $scale = min(1.0, $maxSide / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($alpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = match ($ext) {
        'webp' => imagewebp($dst, $path, 82),
        'png' => imagepng($dst, $path, 6),
        default => imagejpeg($dst, $path, 85),
    };
    imagedestroy($dst);
    if (!$ok) throw new RuntimeException('upload_failed');
}
