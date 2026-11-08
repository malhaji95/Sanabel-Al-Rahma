<?php

use Illuminate\Support\Facades\File;

/*
 | T-275 — found in the pre-launch security review of 8 November.
 |
 | The six artwork fields — a banner, a home card, a gift design, a news
 | picture, a campaign picture, a page section — carried Filament's
 | `->image()` and nothing else. `->image()` is `acceptedFileTypes(['image/*'])`,
 | read straight out of the package, and `image/*` includes `image/svg+xml`.
 |
 | An SVG is a document that may carry script; these land on the PUBLIC disk;
 | and the public disk is served from the site's own origin, where the
 | content policy allows inline script from 'self'. So an account that may
 | edit content could put a running script at an address on our own domain,
 | and send the link to anybody.
 |
 | This holds the fix where it would actually rot: a seventh upload field
 | added later with `->image()` alone would pass unnoticed otherwise.
 */
it('names the raster types it will take, and svg is not among them', function () {
    expect(config('sanabel.media.artwork_types'))
        ->toBe(['image/jpeg', 'image/png', 'image/webp'])
        ->and(config('sanabel.media.artwork_types'))->not->toContain('image/svg+xml');
});

it('leaves no artwork upload taking whatever image/* means', function () {
    /*
     | Read off the source rather than off a built form: a Filament form
     | needs a Livewire component around it, and what is being held here is
     | a rule about how these files are written, which the source is the
     | honest place to read.
     */
    $offenders = [];

    foreach (File::allFiles(app_path('Filament')) as $file) {
        $code = $file->getContents();

        if (! str_contains($code, '->image()')) {
            continue;
        }

        foreach (explode('->image()', $code) as $i => $segment) {
            if ($i === 0) {
                continue;
            }

            // The next call after ->image() must be the type list.
            if (! str_starts_with(ltrim($segment), '->acceptedFileTypes(')) {
                $offenders[] = $file->getRelativePathname();
            }
        }
    }

    expect($offenders)->toBe([], 'these uploads still accept image/*, svg included');
});

it('keeps the private disk list the longer one, because papers are not artwork', function () {
    $private = array_keys(config('sanabel.media.allowed'));

    // A family's papers include a PDF and a telephone photograph; a banner
    // does not.
    expect($private)->toContain('application/pdf')
        ->and($private)->toContain('image/heic')
        ->and($private)->not->toContain('image/svg+xml')
        ->and(count($private))->toBeGreaterThan(count(config('sanabel.media.artwork_types')));
});
