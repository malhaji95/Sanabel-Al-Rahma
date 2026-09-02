#!/usr/bin/env php
<?php

/*
 | Derives the logo variants the UI needs from the one approved artwork in
 | resources/brand/logo-source.png.
 |
 | PROVISIONAL. The brand package described in the handover (SVG, name-only,
 | symbol, horizontal, white and mono versions) has not been supplied. Until it
 | is, these variants are produced here by mechanical operations only:
 |
 |   - a crop to the bands the artwork already separates
 |   - lifting the flat white backdrop to transparency, un-premultiplying the
 |     edge pixels so the colours stay exactly as drawn
 |
 | Nothing is redrawn, recoloured, reproportioned or restyled. When the real
 | package arrives, drop the files into public/brand/, point config/brand.php
 | at them and delete this script.
 |
 |     php scripts/brand-derive.php
 */

$source = __DIR__.'/../resources/brand/logo-source.png';
$outDir = __DIR__.'/../public/brand';

if (! is_file($source)) {
    fwrite(STDERR, "Missing {$source}\n");
    exit(1);
}

@mkdir($outDir, 0755, true);

$src = imagecreatefrompng($source);
$w = imagesx($src);
$h = imagesy($src);

/**
 * Lifts the flat white backdrop to transparency.
 *
 * A pixel drawn at opacity a over white reads back as C = a·F + (1−a)·255, so
 * solving for F recovers the original colour and antialiased edges stay clean
 * instead of turning into a white halo.
 *
 * The artwork's backdrop is not pure white and carries a little compression
 * noise, all of it above 238 in its darkest channel, while the ink never rises
 * above about 190. The ramp below therefore treats 238 as fully transparent
 * and scales the rest, which clears the noise without eating the edges.
 */
const BACKDROP_FLOOR = 238;

function liftBackdrop($src, int $x0, int $y0, int $w, int $h)
{
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($src, $x0 + $x, $y0 + $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;

            $darkest = min($r, $g, $b);

            if ($darkest >= BACKDROP_FLOOR) {
                continue; // backdrop
            }

            $alpha = (BACKDROP_FLOOR - $darkest) / BACKDROP_FLOOR;

            $un = fn (int $c) => (int) max(0, min(255, round(($c - (1 - $alpha) * 255) / $alpha)));

            // GD alpha runs 0 (opaque) to 127 (transparent).
            $gdAlpha = (int) round((1 - $alpha) * 127);

            imagesetpixel($out, $x, $y, imagecolorallocatealpha(
                $out, $un($r), $un($g), $un($b), $gdAlpha
            ));
        }
    }

    return $out;
}

function save($image, string $path): void
{
    imagesavealpha($image, true);
    imagepng($image, $path, 9);
    printf("  %-34s %4dx%-4d\n", basename($path), imagesx($image), imagesy($image));
}

/** Scales down with alpha preserved. */
function resize($image, int $size)
{
    $w = imagesx($image);
    $h = imagesy($image);
    $ratio = $size / max($w, $h);

    $out = imagecreatetruecolor((int) round($w * $ratio), (int) round($h * $ratio));
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    imagecopyresampled($out, $image, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    imagesavealpha($out, true);

    return $out;
}

/*
 | The artwork stacks four elements with clear blank bands between them.
 | These bounds were read off the source, with a little breathing room kept.
 */
$pad = 12;

$bands = [
    // The complete lockup: mark, name, rule and tagline.
    'logo-full' => [0, 150, $w, 1080],
    // The name on its own, for tight horizontal space.
    'logo-wordmark' => [80, 858, 990, 204],
    // The symbol on its own, for the favicon and app icons.
    'logo-symbol' => [276, 150, 584, 664],
];

echo "Deriving brand assets from the approved artwork\n";

$variants = [];

foreach ($bands as $name => [$x, $y, $bw, $bh]) {
    $x = max(0, $x - $pad);
    $y = max(0, $y - $pad);
    $bw = min($w - $x, $bw + $pad * 2);
    $bh = min($h - $y, $bh + $pad * 2);

    $image = liftBackdrop($src, $x, $y, $bw, $bh);
    save($image, "{$outDir}/{$name}.png");
    $variants[$name] = $image;
}

/*
 | Favicon and app icons — the symbol only, never the full lockup.
 | Square canvas, the symbol centred so its proportions are untouched.
 */
$symbol = $variants['logo-symbol'];

function squareIcon($symbol, int $size, ?array $background = null)
{
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);

    $fill = $background
        ? imagecolorallocatealpha($canvas, $background[0], $background[1], $background[2], 0)
        : imagecolorallocatealpha($canvas, 0, 0, 0, 127);

    imagefill($canvas, 0, 0, $fill);
    imagealphablending($canvas, true);

    // Safe space: the symbol never touches the edge of its icon.
    $inner = (int) round($size * 0.78);
    $scaled = resize($symbol, $inner);

    imagecopy(
        $canvas, $scaled,
        (int) round(($size - imagesx($scaled)) / 2),
        (int) round(($size - imagesy($scaled)) / 2),
        0, 0, imagesx($scaled), imagesy($scaled)
    );

    imagesavealpha($canvas, true);

    return $canvas;
}

foreach ([16, 32, 48, 180, 192, 512] as $size) {
    // Maskable Android icons need an opaque field; the identity's light background serves.
    $background = in_array($size, [192, 512], true) ? [0xF7, 0xF9, 0xF4] : null;
    save(squareIcon($symbol, $size, $background), "{$outDir}/icon-{$size}.png");
}

// A multi-resolution .ico for browsers that still ask for one.
$ico = squareIcon($symbol, 32);
save($ico, "{$outDir}/favicon.png");

echo "Done. These are provisional; replace them with the approved package files.\n";
