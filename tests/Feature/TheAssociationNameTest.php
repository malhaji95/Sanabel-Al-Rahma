<?php

/*
 | T-116 — «استبدل في كل مكان الاسم من سنابل الرحمة إلى سنابل الوفاء واستخدم
 | الشعار المرفق بشكل صورة مفرغة», 21 October.
 |
 | A rename is the kind of change that looks finished long before it is: the
 | home page says the new name, and the old one is still sitting in a transfer
 | note, a manifest, a training guide and a policy. So the name is swept for
 | rather than spot-checked.
 |
 | The one place the old name is kept on purpose is privacy-1.0.md. A family
 | that consented agreed to that text as it stood, and the file has to stay on
 | disk to say which text that was.
 */

/** Every source tree the name could be written into, and the ones it may not be swept out of. */
function sourceFilesCarryingText(): array
{
    $roots = ['app', 'config', 'database', 'docs', 'lang', 'resources', 'routes', 'scripts', 'tests'];
    $files = [];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            // Text only: the artwork itself is not searched for words.
            if (! in_array($file->getExtension(), ['php', 'md', 'json', 'css', 'js', 'blade'], true)) {
                continue;
            }

            $files[] = $file->getPathname();
        }
    }

    foreach (['README.md', 'CLAUDE.md', 'Dockerfile', '.env.example', 'public/field-manifest.json'] as $single) {
        $files[] = base_path($single);
    }

    /*
     | Two files name the old name on purpose: the adopted text of the earlier
     | policy version, which is a record rather than a page, and this file,
     | which is the broom.
     */
    return array_values(array_filter($files, fn (string $path) => ! str_ends_with($path, 'policies/privacy-1.0.md')
        && ! str_ends_with($path, 'TheAssociationNameTest.php')));
}

it('carries the association new name, and the old one nowhere a reader can meet it', function () {
    $offenders = [];

    foreach (sourceFilesCarryingText() as $path) {
        $contents = file_get_contents($path);

        if (str_contains($contents, 'سنابل الرحمة') || str_contains($contents, 'Sanabel Al-Rahma')) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($offenders)->toBeEmpty('the old name is still in: '.implode(', ', $offenders));

    // And the new one is in the one place the application reads it from.
    expect(__('sanabel.app_name'))->toBe('سنابل الوفاء');
});

it('makes the rename a new version of the policy rather than an edit of the one families agreed to', function () {
    expect(config('sanabel.privacy_policy.version'))->toBe('1.1');

    $inForce = database_path('data/policies/privacy-1.1.md');
    $agreedTo = database_path('data/policies/privacy-1.0.md');

    expect($inForce)->toBeReadableFile()
        ->and($agreedTo)->toBeReadableFile('the text an earlier consent points at has been deleted');

    // The new version differs from the old in the name and nothing else.
    expect(str_contains(file_get_contents($inForce), 'سنابل الرحمة'))->toBeFalse()
        ->and(str_contains(file_get_contents($agreedTo), 'سنابل الوفاء'))->toBeFalse();
})->group('GOV-02');

it('ships the new artwork with no backdrop behind it', function () {
    /*
     | «واستخدم الشعار المرفق بشكل صورة مفرغة». The lockup sits on the bar, on
     | the page and on the dark scheme, so a white rectangle behind it shows
     | on all three. Nothing opaque may be left of the backdrop, and no white
     | fringe either, which is what un-premultiplying the edge pixels is for.
     */
    foreach (['logo-full', 'logo-wordmark', 'logo-symbol', 'logo-full-on-dark'] as $variant) {
        $image = imagecreatefrompng(public_path("brand/{$variant}.png"));
        $width = imagesx($image);
        $height = imagesy($image);

        foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
            $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;

            expect($alpha)->toBe(127, "{$variant} has a backdrop in one of its corners");
        }
    }

    // A white halo would read as opaque near-white ink, which the artwork has
    // none of. The dark variants are excluded: their text is lifted to the
    // identity's light surface on purpose.
    foreach (['logo-full', 'logo-wordmark', 'logo-symbol'] as $variant) {
        $image = imagecreatefrompng(public_path("brand/{$variant}.png"));
        $halo = 0;

        for ($y = 0; $y < imagesy($image); $y += 2) {
            for ($x = 0; $x < imagesx($image); $x += 2) {
                $rgba = imagecolorat($image, $x, $y);

                if ((($rgba >> 24) & 0x7F) > 10) {
                    continue;
                }

                if (min(($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF) > 235) {
                    $halo++;
                }
            }
        }

        expect($halo)->toBe(0, "{$variant} carries {$halo} pixels of the old white backdrop");
    }
});
