<?php
/**
 * Generates a simple fallback PWA icon on the fly: a dark blue square
 * with white "#1" text, used until a custom icon is provided.
 *
 * Usage: pwa-icon.php?size=192  (or 512)
 */

$size = isset($_GET['size']) ? (int)$_GET['size'] : 192;
if ($size !== 512) {
    $size = 192;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');

if (function_exists('imagecreatetruecolor')) {
    $image = imagecreatetruecolor($size, $size);

    // Background: #002147
    $bg = imagecolorallocate($image, 0x00, 0x21, 0x47);
    imagefilledrectangle($image, 0, 0, $size, $size, $bg);

    // Text: "#1" in white, roughly centered
    $white = imagecolorallocate($image, 255, 255, 255);
    $text = '#1';
    $fontSize = (int)($size * 0.45);

    $fontFile = null;
    // Try a couple of common font locations; fall back to built-in GD font.
    $candidates = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $fontFile = $candidate;
            break;
        }
    }

    if ($fontFile && function_exists('imagettftext')) {
        $bbox = imagettfbbox($fontSize, 0, $fontFile, $text);
        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];
        $x = (int)(($size - $textWidth) / 2);
        $y = (int)(($size + $textHeight) / 2);
        imagettftext($image, $fontSize, 0, $x, $y, $white, $fontFile, $text);
    } else {
        // Fallback to built-in bitmap font, scaled by drawing larger glyphs.
        $gdFont = 5; // largest built-in font
        $textWidth = imagefontwidth($gdFont) * strlen($text);
        $textHeight = imagefontheight($gdFont);
        $scale = max(1, (int)($size / 64));
        $x = (int)(($size - $textWidth * $scale) / 2);
        $y = (int)(($size - $textHeight * $scale) / 2);

        // Draw the string onto a small canvas, then scale it up.
        $small = imagecreatetruecolor($textWidth, $textHeight);
        $smallBg = imagecolorallocate($small, 0x00, 0x21, 0x47);
        $smallWhite = imagecolorallocate($small, 255, 255, 255);
        imagefilledrectangle($small, 0, 0, $textWidth, $textHeight, $smallBg);
        imagestring($small, $gdFont, 0, 0, $text, $smallWhite);
        imagecopyresampled(
            $image,
            $small,
            $x,
            $y,
            0,
            0,
            $textWidth * $scale,
            $textHeight * $scale,
            $textWidth,
            $textHeight
        );
        imagedestroy($small);
    }

    imagepng($image);
    imagedestroy($image);
    exit;
}

// GD extension unavailable: serve a minimal inline SVG-derived PNG placeholder
// is not possible without GD, so fall back to a tiny 1x1 PNG as a last resort.
header('Content-Type: image/png');
echo base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUAAQ' .
    'lqQxIAAAAASUVORK5CYII='
);
