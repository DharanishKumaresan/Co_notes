<?php
declare(strict_types=1);

/**
 * Draw the app icons.
 *
 * Run once, and again only if the mark changes:
 *
 *     php tools/make-icons.php
 *
 * The output is committed - a shared host has no build step, and nothing
 * should have to run at deploy time to make the app installable.
 *
 * The mark is two overlapping cards - the app is a *shared* board, so one card
 * was never the whole story - on the Lime theme's flat accent. Flat, because
 * the UI dropped gradients; an icon that still carried one would be the only
 * gradient left in the product.
 *
 * The favicon is deliberately a different, simpler drawing: at 32px the second
 * card and its keyline collapse into noise, so that size gets a single card
 * with two bold bars. Same colours, same silhouette family, one less idea.
 *
 * GD draws everything at 4x and scales down, which is the cheapest antialiasing
 * there is; drawing at final size gives visibly ragged corners.
 */

const OUT = __DIR__ . '/../public_html/icons';

// The Lime pair from :root in app.css, and the dark ink that sits on it.
const ACC   = [0xC8, 0xF0, 0x4A];
const ACC_2 = [0x5F, 0xE3, 0xA1];
const INK   = [0x15, 0x19, 0x0B];

/**
 * The icon, drawn at $size with the mark occupying $glyph of the canvas.
 *
 * `maskable` icons are cropped to a circle by some launchers, so their mark
 * has to sit inside the middle ~80%; a plain icon can use more of the square.
 *
 * $stacked draws the two-card mark. False draws the single card the favicon
 * uses, where the second card would only muddy 32 pixels.
 */
function draw(int $size, float $glyph, bool $stacked): \GdImage
{
    $im = imagecreatetruecolor($size, $size);
    imagealphablending($im, true);

    // Flat, not the 135deg pair: --acc-grad is `none` in the stylesheet now.
    $bg  = imagecolorallocate($im, ACC[0], ACC[1], ACC[2]);
    $ink = imagecolorallocate($im, INK[0], INK[1], INK[2]);
    imagefilledrectangle($im, 0, 0, $size - 1, $size - 1, $bg);

    // Bounding box for the whole mark.
    $H  = (int) round($size * $glyph);
    $W  = (int) round($H * 0.92);
    $ox = (int) round(($size - $W) / 2);
    $oy = (int) round(($size - $H) / 2);

    // One card, sized so that two of them offset diagonally fill that box.
    $cw = (int) round($W * ($stacked ? 0.80 : 0.84));
    $ch = (int) round($H * ($stacked ? 0.84 : 0.94));
    $r  = (int) round($cw * 0.17);

    if ($stacked) {
        // The card behind, up and to the right.
        roundedRect($im, $ox + ($W - $cw), $oy, $cw, $ch, $r, $ink);

        // A keyline in the ground colour, so the front card reads as a
        // separate object rather than merging into an L-shaped blob.
        $k = max(2, (int) round($size * 0.021));
        roundedRect(
            $im,
            $ox - $k, $oy + ($H - $ch) - $k,
            $cw + 2 * $k, $ch + 2 * $k,
            $r + $k, $bg
        );
    }

    $fx = $stacked ? $ox : $ox + (int) round(($W - $cw) / 2);
    $fy = $stacked ? $oy + ($H - $ch) : $oy + (int) round(($H - $ch) / 2);
    roundedRect($im, $fx, $fy, $cw, $ch, $r, $ink);

    // Two bars, not three lines. Thick enough to survive the scale down.
    $pad = (int) round($cw * 0.19);
    $bh  = max(1, (int) round($ch * 0.12));
    $gap = (int) round($ch * 0.215);
    $top = $fy + (int) round($ch * 0.29);
    foreach ([1.0, 0.60] as $i => $frac) {
        $bw = (int) round(($cw - 2 * $pad) * $frac);
        roundedRect($im, $fx + $pad, $top + $i * $gap, $bw, $bh, (int) round($bh / 2), $bg);
    }

    return $im;
}

function roundedRect(\GdImage $im, int $x, int $y, int $w, int $h, int $r, int $color): void
{
    $r = max(0, min($r, (int) floor(min($w, $h) / 2)));
    if ($r === 0) {
        imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, $color);
        return;
    }
    imagefilledrectangle($im, $x + $r, $y, $x + $w - 1 - $r, $y + $h - 1, $color);
    imagefilledrectangle($im, $x, $y + $r, $x + $w - 1, $y + $h - 1 - $r, $color);
    $d = $r * 2;
    imagefilledellipse($im, $x + $r, $y + $r, $d, $d, $color);
    imagefilledellipse($im, $x + $w - 1 - $r, $y + $r, $d, $d, $color);
    imagefilledellipse($im, $x + $r, $y + $h - 1 - $r, $d, $d, $color);
    imagefilledellipse($im, $x + $w - 1 - $r, $y + $h - 1 - $r, $d, $d, $color);
}

function write(string $name, int $size, float $glyph, bool $stacked = true): void
{
    $big = draw($size * 4, $glyph, $stacked);
    $out = imagescale($big, $size, $size, IMG_BICUBIC);
    imagedestroy($big);
    if ($out === false) {
        throw new \RuntimeException('imagescale failed for ' . $name);
    }
    imagepng($out, OUT . '/' . $name, 9);
    imagedestroy($out);
    echo "  {$name}  {$size}x{$size}\n";
}

if (!is_dir(OUT) && !mkdir(OUT, 0755, true) && !is_dir(OUT)) {
    fwrite(STDERR, "Cannot create " . OUT . "\n");
    exit(1);
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "The gd extension is required.\n");
    exit(1);
}

echo "Writing icons to public_html/icons\n";
write('icon-192.png',          192, 0.66);
write('icon-512.png',          512, 0.66);
// Launchers crop this one; the mark stays inside the safe circle.
write('icon-maskable-512.png', 512, 0.50);
write('apple-touch-icon.png',  180, 0.66);
// One card, drawn bigger: two would be mush at this size.
write('favicon-32.png',         32, 0.80, false);
echo "Done.\n";
