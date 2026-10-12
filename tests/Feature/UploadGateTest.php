<?php

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 | T-58 — FILE-01 of the security roadmap.
 |
 | Before this, MediaService took the extension from the name the browser sent
 | and checked neither the type nor the size, so a file called paper.php was
 | stored as paper.php on a disk the association also backs up and restores.
 */

beforeEach(function () {
    seedCore();
    Storage::fake('media_test');
    $this->region = regionWithRates();
    $this->case = publishedCase($this->region);
    $this->delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $this->service = app(MediaService::class);
});

it('refuses a type that is not on the list', function () {
    $file = UploadedFile::fake()->createWithContent('paper.php', '<?php echo "x";');

    expect(fn () => $this->service->store($file, $this->case, 'identity', $this->delegate))
        ->toThrow(RuntimeException::class);

    expect(Media::count())->toBe(0);
})->group('FILE-01');

it('names the file by what it is, never by what it claims to be', function () {
    // A real JPEG called paper.php. The old code stored it with a .php
    // extension because it trusted the client's filename.
    $file = UploadedFile::fake()->image('paper.php.jpg');

    $media = $this->service->store($file, $this->case, 'identity', $this->delegate);

    expect($media->storage_key)->toEndWith('.jpg')
        ->and($media->storage_key)->not->toContain('.php')
        ->and($media->mime)->toBe('image/jpeg')
        // Nor by the family: no name and no national ID in the path.
        ->and($media->storage_key)->not->toContain($this->case->family_name);
})->group('FILE-01');

it('refuses a file over the size limit', function () {
    config(['sanabel.media.max_bytes' => 1024]);

    $file = UploadedFile::fake()->image('big.jpg')->size(64);

    expect(fn () => $this->service->store($file, $this->case, 'identity', $this->delegate))
        ->toThrow(RuntimeException::class);
})->group('FILE-01');

it('accepts the types a delegate actually photographs with', function () {
    foreach (['image/jpeg', 'image/png', 'application/pdf'] as $mime) {
        expect(config('sanabel.media.allowed'))->toHaveKey($mime);
    }

    // An iPhone produces HEIC.
    expect(config('sanabel.media.allowed'))->toHaveKey('image/heic');
})->group('FILE-01');

it('marks a file that passed its checks as clean and serves it', function () {
    $media = $this->service->store(
        UploadedFile::fake()->image('receipt.jpg'),
        $this->case,
        'identity',
        $this->delegate,
    );

    expect($media->scan_status)->toBe(Media::CLEAN)
        ->and($media->scanned_at)->not->toBeNull()
        ->and($this->service->temporaryUrl($media))->toMatch('/(signature|expiration|Expires)/i');
})->group('FILE-02');

it('holds a new upload and serves nothing when an outside scanner is required', function () {
    // FILE-02 needs a scanner the association provides. The gate is wired so
    // that the day they have one, nothing is served before it has looked.
    config(['sanabel.media.require_external_scan' => true]);

    $media = $this->service->store(
        UploadedFile::fake()->image('receipt.jpg'),
        $this->case,
        'identity',
        $this->delegate,
    );

    expect($media->scan_status)->toBe(Media::PENDING)
        ->and($media->isServable())->toBeFalse()
        ->and(fn () => $this->service->temporaryUrl($media))->toThrow(RuntimeException::class)
        ->and(fn () => $media->signedUrl())->toThrow(RuntimeException::class);
})->group('FILE-02');

it('never serves a rejected file, through either path', function () {
    $media = $this->service->store(
        UploadedFile::fake()->image('receipt.jpg'),
        $this->case,
        'identity',
        $this->delegate,
    );

    // Kept, not erased: rule 3 forbids erasing anything.
    $media->update(['scan_status' => Media::REJECTED, 'scan_note' => 'فشل الفحص']);

    expect(fn () => $this->service->temporaryUrl($media->fresh()))->toThrow(RuntimeException::class)
        ->and(fn () => $media->fresh()->signedUrl())->toThrow(RuntimeException::class)
        ->and(Media::withTrashed()->whereKey($media->getKey())->exists())->toBeTrue();
})->group('FILE-02');

it('leaves the papers already on live files openable', function () {
    // The gate arrived after the association had been working for weeks.
    // Withholding what they already accepted would take papers off live files.
    $migration = file_get_contents(base_path(
        'database/migrations/2026_10_11_140000_hold_an_upload_until_it_has_been_checked.php'
    ));

    expect($migration)->toContain("'scan_status' => 'clean'");
})->group('FILE-03');
