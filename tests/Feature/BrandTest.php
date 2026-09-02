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

it('derives every variant as an unscaled crop of the approved artwork', function () {
    // The variants are crops, never resizes: the guide forbids stretching or
    // squashing the logo, and a crop cannot change its proportions at all.
    [$sourceWidth, $sourceHeight] = getimagesize(resource_path('brand/logo-source.png'));

    // The full lockup spans the artwork's whole width, so a rescale would show
    // up immediately as a different pixel width.
    [$fullWidth, $fullHeight] = getimagesize(public_path('brand/logo-full.png'));

    expect($fullWidth)->toBe($sourceWidth)
        ->and($fullHeight)->toBeLessThanOrEqual($sourceHeight);

    // Each of the other variants is a strictly smaller region of the same image.
    foreach (['wordmark', 'symbol'] as $variant) {
        [$w, $h] = getimagesize(public_path("brand/logo-{$variant}.png"));

        expect($w)->toBeLessThanOrEqual($sourceWidth)
            ->and($h)->toBeLessThan($sourceHeight);
    }

    // The name reads wide; the mark reads tall. If a variant had been squashed
    // to fit a slot, one of these would flip.
    [$wordW, $wordH] = getimagesize(public_path('brand/logo-wordmark.png'));
    [$symW, $symH] = getimagesize(public_path('brand/logo-symbol.png'));

    expect($wordW)->toBeGreaterThan($wordH * 3)
        ->and($symH)->toBeGreaterThan($symW);
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

it('uses the name-only version in the header, where the full lockup does not fit', function () {
    $this->get(route('news'))
        ->assertOk()
        ->assertSee(config('brand.logo.wordmark'), escape: false);
});

it('never recolours the artwork onto a dark field while the white version is missing', function () {
    // Rule 6 needs an approved white logo. Until brand.logo.on_dark is supplied,
    // the logo keeps a light field rather than being recoloured to sit on green.
    expect(config('brand.logo.on_dark'))->toBeNull();

    $plate = file_get_contents(resource_path('views/components/brand/logo-plate.blade.php'));

    expect($plate)->toContain('dark:bg-[--logo-field]');
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
