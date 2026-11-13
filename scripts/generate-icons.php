<?php

/**
 * One-off script to regenerate public/icons/*.png + public/favicon.ico
 * from the new Meet Meet logo (logo/oie_HYedYeYqRjtT-Photoroom.png -
 * the one with genuine alpha transparency; the other two either have
 * a baked-in light background or a blurred marketing mockup around
 * them, not a clean transparent icon source). Not wired into any
 * build step - run manually whenever the logo changes.
 */

$sourcePath = __DIR__ . '/../logo/oie_HYedYeYqRjtT-Photoroom.png';
$outputDir = __DIR__ . '/../public/icons';

$source = imagecreatefrompng($sourcePath);
imagealphablending($source, false);
imagesavealpha($source, true);

// Crop to just the icon square (the file also has the "Meet MEET"
// wordmark below it, separated by a real transparent gap) - found by
// scanning for the bounding box of non-transparent pixels in the top
// portion only, then padding out ~4% on each side for breathing room.
$bboxX1 = 266;
$bboxY1 = 51;
$bboxX2 = 1144;
$bboxY2 = 950;
$bboxW = $bboxX2 - $bboxX1;
$bboxH = $bboxY2 - $bboxY1;
$pad = (int) (max($bboxW, $bboxH) * 0.04);
$side = max($bboxW, $bboxH) + $pad * 2;
$cropX = $bboxX1 - $pad - (int) (($side - $bboxW) / 2 - $pad);
$cropY = $bboxY1 - $pad;

$icon = imagecreatetruecolor($side, $side);
imagealphablending($icon, false);
imagesavealpha($icon, true);
$transparent = imagecolorallocatealpha($icon, 0, 0, 0, 127);
imagefill($icon, 0, 0, $transparent);
imagecopy($icon, $source, 0, 0, $cropX, $cropY, $side, $side);

function resizedSquare($src, int $size, ?array $backgroundRgb = null)
{
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);

    if ($backgroundRgb === null) {
        $bg = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    } else {
        $bg = imagecolorallocate($canvas, ...$backgroundRgb);
    }
    imagefill($canvas, 0, 0, $bg);

    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $src, 0, 0, 0, 0, $size, $size, $srcW, $srcH);

    return $canvas;
}

// Standard "any"-purpose icons: transparent background, full-bleed.
foreach ([512 => 'icon-512.png', 192 => 'icon-192.png'] as $size => $filename) {
    $img = resizedSquare($icon, $size);
    imagepng($img, "$outputDir/$filename");
    imagedestroy($img);
}

// Apple touch icon - iOS doesn't composite transparency reliably, so
// flatten onto opaque white (matches the icon's own white interior).
$appleIcon = resizedSquare($icon, 180, [255, 255, 255]);
imagepng($appleIcon, "$outputDir/apple-touch-icon.png");
imagedestroy($appleIcon);

// Favicon PNG.
$favicon32 = resizedSquare($icon, 32, [255, 255, 255]);
imagepng($favicon32, "$outputDir/favicon-32.png");

// Maskable icon: the OS crops this into an arbitrary shape (circle,
// squircle, etc.), so per the maskable-icon spec, content must sit
// within the inner ~80% "safe zone" with the background extending to
// the canvas edges - otherwise a circular mask would clip the icon's
// own corners. Scaled to 72% and centered, opaque white background.
$maskableSize = 512;
$maskable = imagecreatetruecolor($maskableSize, $maskableSize);
$white = imagecolorallocate($maskable, 255, 255, 255);
imagefill($maskable, 0, 0, $white);
imagealphablending($maskable, true);
$innerSize = (int) ($maskableSize * 0.72);
$offset = (int) (($maskableSize - $innerSize) / 2);
imagecopyresampled($maskable, $icon, $offset, $offset, 0, 0, $innerSize, $innerSize, $side, $side);
imagepng($maskable, "$outputDir/icon-maskable-512.png");
imagedestroy($maskable);

// favicon.ico - a real multi-size ICO (16/32/48), each frame embedded
// as PNG data (the modern ICO format Windows/browsers have supported
// since Vista), since GD has no native ICO writer.
function pngData($im): string
{
    ob_start();
    imagepng($im);

    return ob_get_clean();
}

$icoSizes = [16, 32, 48];
$frames = [];
foreach ($icoSizes as $size) {
    $frames[$size] = pngData(resizedSquare($icon, $size, [255, 255, 255]));
}

$count = count($icoSizes);
$header = pack('vvv', 0, 1, $count);
$dirEntries = '';
$imageData = '';
$offset = 6 + 16 * $count;
foreach ($icoSizes as $size) {
    $data = $frames[$size];
    $dirEntries .= pack('CCCCvvVV', $size === 256 ? 0 : $size, $size === 256 ? 0 : $size, 0, 0, 1, 32, strlen($data), $offset);
    $imageData .= $data;
    $offset += strlen($data);
}
file_put_contents(__DIR__ . '/../public/favicon.ico', $header . $dirEntries . $imageData);

echo "Done. Generated: icon-512.png, icon-192.png, apple-touch-icon.png, favicon-32.png, icon-maskable-512.png, favicon.ico\n";
