<?php

/*
 | The visual identity of سنابل الرحمة, in one place.
 |
 | The colours below are the official identity palette supplied with the brand
 | guide. Note that the logo artwork itself is drawn in a deeper green
 | (#01674C) than the stated primary — the artwork is used exactly as supplied
 | and is never recoloured to match, so the two sit side by side deliberately.
 |
 | The logo files under public/brand/ are derived from the one approved artwork
 | by scripts/brand-derive.php. When the full package arrives (SVG, white and
 | mono versions, horizontal lockups), drop the files in and change the paths
 | here — nothing else in the application refers to a logo by filename.
 */

return [
    'colors' => [
        'primary' => '#2E7D32',   // الأخضر الأساسي
        'gold' => '#C9A227',      // الذهبي المساند
        'surface' => '#F7F9F4',   // خلفية فاتحة
        'ink' => '#263238',       // لون النصوص
    ],

    /*
     | Logo variants.
     |
     |   full     — the complete lockup: mark, name, rule and tagline.
     |              Home page, headers and anywhere with room for it.
     |   wordmark — the name alone, for tight horizontal space.
     |   symbol   — the mark alone. Favicon and app icons only.
     |
     | `on_dark` carries the variants for the dark scheme. The artwork's name
     | and tagline are drawn in a very deep green that reads at 1.7:1 against
     | that scheme's page — invisible — so until 18 October the logo kept a
     | light plate behind it there. The association asked for the plate to go.
     |
     | These files are the stand-in for the white version of the lockup that
     | the brand package was meant to contain and does not: the medallion
     | exactly as supplied, and the text beneath it lifted to the identity's
     | own light surface. Produced by scripts/brand-derive.php, which explains
     | what it does and what it leaves alone. When the association supplies
     | their own white version, the files change and nothing else does.
     |
     | A variant with no entry here keeps the light file on both schemes.
     */
    'logo' => [
        'full' => '/brand/logo-full.png',
        'wordmark' => '/brand/logo-wordmark.png',
        'symbol' => '/brand/logo-symbol.png',
        'on_dark' => [
            'full' => '/brand/logo-full-on-dark.png',
            'wordmark' => '/brand/logo-wordmark-on-dark.png',
        ],
    ],

    'icons' => [
        'favicon' => '/brand/icon-32.png',
        'apple_touch' => '/brand/icon-180.png',
        'maskable_192' => '/brand/icon-192.png',
        'maskable_512' => '/brand/icon-512.png',
    ],

    /*
     | The safe space the guide requires: no element sits closer to the logo
     | than this, expressed as a fraction of the logo's rendered height.
     */
    'clear_space_ratio' => 0.25,

    'font' => [
        'family' => 'IBM Plex Sans Arabic',
        // The fallback named in the identity guide, then the system stack.
        'stack' => "'IBM Plex Sans Arabic', 'Noto Sans Arabic', 'Segoe UI', system-ui, sans-serif",
    ],

    'tagline_ar' => 'عطاء ينمو... وأثر يبقى',
];
