<?php
declare(strict_types=1);

require_once WB_ROOT . '/vendor/qrcode.php';

function wb_qr_modules(string $text): array {
    $qr = QRCode::getMinimumQRCode($text, QR_ERROR_CORRECT_LEVEL_M);
    $n = $qr->getModuleCount();
    $rows = [];
    for ($r = 0; $r < $n; $r++) {
        $row = [];
        for ($c = 0; $c < $n; $c++) $row[] = $qr->isDark($r, $c);
        $rows[] = $row;
    }
    return $rows;
}

function wb_qr_svg(string $text, int $quiet = 2): string {
    $m = wb_qr_modules($text);
    $n = count($m);
    $size = $n + 2 * $quiet;
    $path = '';
    foreach ($m as $r => $row) {
        foreach ($row as $c => $dark) {
            if ($dark) $path .= 'M' . ($c + $quiet) . ' ' . ($r + $quiet) . 'h1v1h-1z';
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges">'
        . '<rect width="100%" height="100%" fill="#ffffff"/>'
        . '<path d="' . $path . '" fill="#000000"/></svg>';
}

function wb_qr_png(string $text, int $pixelsPerModule = 12, int $quiet = 2): string {
    $m = wb_qr_modules($text);
    $n = count($m);
    $size = ($n + 2 * $quiet) * $pixelsPerModule;
    $img = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $white);
    foreach ($m as $r => $row) {
        foreach ($row as $c => $dark) {
            if (!$dark) continue;
            $x = ($c + $quiet) * $pixelsPerModule;
            $y = ($r + $quiet) * $pixelsPerModule;
            imagefilledrectangle($img, $x, $y, $x + $pixelsPerModule - 1, $y + $pixelsPerModule - 1, $black);
        }
    }
    ob_start();
    imagepng($img);
    imagedestroy($img);
    return (string)ob_get_clean();
}
