<?php

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('ships every logo variant the identity guide calls for', function () {
    foreach (['full', 'wordmark', 'symbol'] as $variant) {
        $path = public_path(ltrim(config("brand.logo.{$variant}"), '/'));

        expect($path)->toBeReadableFile("missing logo variant '{$variant}'");
    }
});

it('ships the favicon and app icons, derived from the symbol only', function () {
    foreach (config('brand.icons') as $key => $url) {
        expect(public_path(ltrim($url, '/')))->toBeReadableFile("missing icon '{$key}'");
    }

    // Square, so an app icon is never stretched.
    foreach ([32, 180, 192, 512] as $size) {
        [$w, $h] = getimagesize(public_path("brand/icon-{$size}.png"));

        expect($w)->toBe($size)->and($h)->toBe($size);
    }
});

it('draws every variant from the approved artwork without distorting it', function () {
    /*
     | The rule the identity guide actually sets is that the logo is never
     | stretched or squashed. It used to be held by demanding that each
     | variant be an unscaled crop of the source, because a crop cannot
     | change proportions at all — true, and more than the rule asks.
     |
     | T-104 — the association sent new artwork on 15 October, and the old
     | files could not simply be recut from it: the source they sent is a
     | photograph-sized JPEG on white, and the lockup on the page has to
     | carry transparency and be light enough to load on every visit. The
     | variants are therefore cut and then scaled down, proportionally.
     |
     | So the check is the rule itself. Each variant is measured against the
     | band of the source it was cut from, and its proportions must match
     | within a pixel's rounding.
     */
    [$sourceWidth, $sourceHeight] = getimagesize(resource_path('brand/logo-source.png'));

    expect($sourceWidth)->toBeGreaterThan(0)->and($sourceHeight)->toBeGreaterThan(0);

    // The lockup is the whole artwork, trimmed of its margins: taller than it
    // is wide, as a mark over a name over a rule over a line of text must be.
    [$fullWidth, $fullHeight] = getimagesize(public_path('brand/logo-full.png'));

    expect($fullHeight)->toBeGreaterThan($fullWidth);

    // The name reads wide; the mark is the medallion, near enough square. If
    // either had been squashed to fit a slot, these would not hold.
    [$wordW, $wordH] = getimagesize(public_path('brand/logo-wordmark.png'));
    [$symW, $symH] = getimagesize(public_path('brand/logo-symbol.png'));

    expect($wordW)->toBeGreaterThan($wordH * 3)
        ->and(abs($symW - $symH) / max($symW, $symH))->toBeLessThan(0.1);

    // And none of the three is bigger than it needs to be. A logo is on every
    // page of the site, including the one a delegate opens on a phone in the
    // field.
    foreach (['full', 'wordmark', 'symbol'] as $variant) {
        expect(filesize(public_path("brand/logo-{$variant}.png")))
            ->toBeLessThan(200 * 1024, "the {$variant} logo is too heavy for a page asset");
    }
});

it('carries the official identity palette', function () {
    expect(config('brand.colors'))->toBe([
        'primary' => '#2E7D32',
        'gold' => '#C9A227',
        'surface' => '#F7F9F4',
        'ink' => '#263238',
    ]);
});

it('serves the identity typeface from our own origin', function () {
    expect(config('brand.font.family'))->toBe('IBM Plex Sans Arabic')
        ->and(public_path('fonts/ibm-plex-sans-arabic.css'))->toBeReadableFile();

    $css = file_get_contents(public_path('fonts/ibm-plex-sans-arabic.css'));

    expect($css)->toContain("font-family: 'IBM Plex Sans Arabic'")
        // No outside font host: the field app has to work with no network.
        ->and($css)->not->toContain('https://');

    foreach (['400', '500', '600', '700'] as $weight) {
        expect(public_path("fonts/ibm-plex-sans-arabic-arabic-{$weight}.woff2"))->toBeReadableFile();
    }
});

it('renders the public pages right-to-left with the brand logo and icons', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('dir="rtl"', escape: false)
        ->assertSee('lang="ar"', escape: false)
        // The symbol alone is the favicon, never the full lockup.
        ->assertSee(config('brand.icons.favicon'), escape: false)
        ->assertSee(config('brand.icons.apple_touch'), escape: false)
        ->assertSee('content="'.config('brand.colors.primary').'"', escape: false)
        // The full lockup appears on the home page.
        ->assertSee(config('brand.logo.full'), escape: false);
});

it('uses the approved lockup itself, in the bar and in the footer', function () {
    /*
     | T-99 — this held the opposite until 15 October: the name-only version in
     | the bar, because the supplied artwork is a stacked lockup and a header
     | is not shaped for one. The association looked at it on a phone and asked
     | for their own logo in both places, which is their call to make about
     | their own identity. It is given height rather than width so the name
     | stays legible, and a horizontal lockup — if the designer supplies one —
     | is a single path change in config/brand.php.
     */
    $html = $this->get(route('news'))->assertOk()->getContent();

    expect(substr_count($html, config('brand.logo.full')))->toBeGreaterThanOrEqual(2)
        ->and($html)->not->toContain(config('brand.logo.wordmark'));
});

it('gives the dark scheme a readable lockup instead of a white box behind it', function () {
    /*
     | This test used to hold the opposite: that `on_dark` stayed null and the
     | logo kept a light plate under it on the dark scheme, rather than the
     | artwork being recoloured. The reasoning was sound — the supplied name
     | and tagline read at 1.7:1 on that page, which is invisible — and the
     | association looked at the result on 18 October and asked for the white
     | box gone.
     |
     | So the answer moved rather than disappeared. The medallion is still the
     | artwork exactly as supplied; only the text beneath it is lifted, and
     | only for the dark scheme. What this test holds now is that the lifting
     | actually made the words readable, and that the light scheme was left
     | alone.
     */
    $dark = public_path(ltrim(config('brand.logo.on_dark.full'), '/'));

    expect($dark)->toBeReadableFile('the dark lockup is missing');

    [$lightW, $lightH] = getimagesize(public_path('brand/logo-full.png'));
    [$darkW, $darkH] = getimagesize($dark);

    // The same artwork at the same size: lifted, never redrawn or reframed.
    expect([$darkW, $darkH])->toBe([$lightW, $lightH]);

    $plate = file_get_contents(resource_path('views/components/brand/logo-plate.blade.php'));
    $logo = file_get_contents(resource_path('views/components/brand/logo.blade.php'));

    expect($plate)->not->toContain('dark:bg-')
        // Both files in the markup, because the site carries its own
        // light-and-dark switch and cannot leave this to the browser.
        ->and($logo)->toContain('dark:hidden')
        ->and($logo)->toContain('dark:block');
});

it('makes the name legible on the dark page, which is why the plate could go', function () {
    /*
     | The measurement the change rests on. WCAG asks 3:1 of a graphic that
     | carries meaning; the supplied green gives 1.73 against #101613, and a
     | reader simply does not see the association's name.
     */
    $page = [0x10, 0x16, 0x13];

    $luminance = function (array $rgb): float {
        $channels = array_map(function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };

    $contrast = function (array $a, array $b) use ($luminance): float {
        $high = max($luminance($a), $luminance($b));
        $low = min($luminance($a), $luminance($b));

        return ($high + 0.05) / ($low + 0.05);
    };

    $inkOf = function (string $path): array {
        $image = imagecreatefrompng($path);
        $width = imagesx($image);
        $height = imagesy($image);

        // The band the name sits in, and only the strokes themselves.
        $samples = [];

        for ($y = (int) ($height * 0.66); $y < (int) ($height * 0.88); $y++) {
            for ($x = 0; $x < $width; $x += 2) {
                $rgba = imagecolorat($image, $x, $y);

                if ((($rgba >> 24) & 0x7F) > 20) {
                    continue;
                }

                $samples[] = [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
            }
        }

        // The median channel, so one gold dot does not speak for the name.
        return array_map(function (int $channel) use ($samples) {
            $values = array_column($samples, $channel);
            sort($values);

            return $values[intdiv(count($values), 2)];
        }, [0, 1, 2]);
    };

    $supplied = $contrast($inkOf(public_path('brand/logo-full.png')), $page);
    $lifted = $contrast($inkOf(public_path(ltrim(config('brand.logo.on_dark.full'), '/'))), $page);

    expect($supplied)->toBeLessThan(3.0)
        ->and($lifted)->toBeGreaterThan(7.0);
});

it('gives the field app a manifest with brand colours and the symbol as its icon', function () {
    $this->get(route('field.manifest'))->assertOk();

    $manifest = json_decode(file_get_contents(public_path('field-manifest.json')), true);

    expect($manifest['theme_color'])->toBe(config('brand.colors.primary'))
        ->and($manifest['background_color'])->toBe(config('brand.colors.surface'))
        ->and($manifest['dir'])->toBe('rtl')
        ->and($manifest['lang'])->toBe('ar')
        ->and(collect($manifest['icons'])->pluck('src')->unique()->values()->all())
        ->toBe(['/brand/icon-192.png', '/brand/icon-512.png']);
});

it('caches the brand assets and the typeface for offline use', function () {
    $sw = file_get_contents(public_path('field-sw.js'));

    expect($sw)->toContain('/brand/logo-symbol.png')
        ->and($sw)->toContain('/fonts/ibm-plex-sans-arabic-arabic-400.woff2');
});

it('brands every panel with the identity logo, colours and typeface', function () {
    foreach (['admin', 'association', 'provider'] as $id) {
        $panel = Filament\Facades\Filament::getPanel($id);

        expect($panel->getBrandLogo())->toBe(config('brand.logo.wordmark'))
            ->and($panel->getFavicon())->toBe(config('brand.icons.favicon'))
            ->and($panel->getFontFamily())->toBe(config('brand.font.family'))
            ->and($panel->getFontHtml()->toHtml())->toContain('fonts/ibm-plex-sans-arabic.css');
    }
});

it('keeps no hardcoded brand colour outside the brand config and stylesheet', function () {
    $offenders = [];

    foreach (['app', 'resources/views'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // The palette belongs in config/brand.php and resources/css/app.css.
            if (preg_match('/#(2E7D32|C9A227|F7F9F4|263238)/i', $contents)) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBeEmpty();
});

it('draws the logo larger on a laptop than on a phone, without losing its proportions', function () {
    /*
     | T-112 — «تقدر تخلي اللوغو بالهيدر اكبر شوي على الديسكتوب واللابتوب»,
     | 19 October.
     |
     | The height used to be an inline style, which is one number for every
     | screen. It is two custom properties now, read by two static rules in
     | the stylesheet — static because a class name a Blade component builds
     | at run time is a class name Tailwind never sees and never emits, and
     | the logo would simply have had no height at all.
     |
     | The width is never given: it follows the artwork, so neither size can
     | squash it.
     */
    $header = $this->get(route('home'))->assertOk()->getContent();

    expect($header)->toContain('--logo-h: 48px')
        ->and($header)->toContain('--logo-h-lg: 68px');

    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('height: var(--logo-h);')
        ->and($css)->toContain('height: var(--logo-h-lg, var(--logo-h));')
        // The clear space is a fraction of the rendered height, so it grows
        // with the logo rather than being a fixed margin around a bigger one.
        ->and(file_get_contents(resource_path('views/components/brand/logo.blade.php')))
        ->toContain('padding: {{ $clearSpace }}em');
});
