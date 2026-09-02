<?php

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('keeps whichever media disk is in use private and outside the web root', function () {
    // The suite runs on media_test; production defaults to media_local. The
    // invariant is the same either way, so assert it on the disk in use.
    $disk = config('sanabel.media_disk');

    expect(config("filesystems.disks.{$disk}.visibility"))->toBe('private');

    $root = config("filesystems.disks.{$disk}.root");

    if ($root !== null) {
        expect($root)->not->toStartWith(public_path())
            // 'serve' is what registers the signed, expiring route.
            ->and(config("filesystems.disks.{$disk}.serve"))->toBeTrue();
    }
});

it('defines the local media disk the deployment defaults to', function () {
    expect(config('filesystems.disks.media_local'))->toMatchArray([
        'driver' => 'local',
        'visibility' => 'private',
        'serve' => true,
    ])->and(config('filesystems.disks.media_local.root'))
        ->toBe(storage_path('app/private/media'))
        ->and(config('filesystems.disks.media_local.root'))
        ->not->toStartWith(public_path());

    // The config default, independent of whatever the test env sets.
    $config = require config_path('sanabel.php');

    expect($config['media_disk'])->toBe(env('SANABEL_MEDIA_DISK', 'media_local'));
});

it('stores a file on the local disk and only hands back a signed, expiring URL', function () {
    Storage::fake('media_test');

    $case = publishedCase($this->region);
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);

    $media = app(MediaService::class)->store(
        UploadedFile::fake()->image('receipt.jpg'),
        $case,
        'receipt',
        $delegate,
    );

    $url = app(MediaService::class)->temporaryUrl($media);

    expect($media->visibility)->toBe('internal')
        ->and($url)->toMatch('/(signature|expiration|Expires)/i')
        // No permanent, guessable URL exists for it.
        ->and($url)->not->toBe(Storage::disk('media_test')->path($media->storage_key));
});

it('keeps the S3 bucket configured as the private alternative', function () {
    // Switching to a bucket is an env change, not a code change.
    expect(config('filesystems.disks.media.driver'))->toBe('s3')
        ->and(config('filesystems.disks.media.visibility'))->toBe('private');
});

it('backs media up alongside the database while it lives on the local disk', function () {
    // Media is part of the record: a case closes only against a delivery proof,
    // so losing the files would lose the evidence the closure rests on.
    $backup = file_get_contents(base_path('scripts/backup.sh'));

    expect($backup)->toContain('storage/app/private/media')
        ->and($backup)->toContain('media_local');
});
