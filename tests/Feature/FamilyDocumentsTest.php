<?php

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 | The family's own papers on the family's own file. The store was built in
 | the first weeks; nothing attached anything to a household through it until
 | the association asked during the walkthrough.
 */

beforeEach(function () {
    seedCore();
    Storage::fake(config('sanabel.media_disk'));
    $this->region = regionWithRates();
});

it('attaches a paper to the family it belongs to', function () {
    $case = publishedCase($this->region);
    $officer = userWithRole('case_officer');

    $media = app(MediaService::class)->store(
        UploadedFile::fake()->create('lease.pdf', 40, 'application/pdf'),
        $case,
        'lease',
        $officer,
    );

    expect($case->documents()->count())->toBe(1)
        ->and($case->documents->first()->is($media))->toBeTrue()
        ->and($media->kind)->toBe('lease')
        ->and($media->uploaded_by)->toBe($officer->id)
        // Internal by default, and the key carries no name or national ID.
        ->and($media->visibility)->toBe('internal')
        ->and($media->storage_key)->not->toContain($case->file_number);
});

it('keeps a paper off the public disk and behind a link that expires', function () {
    $case = publishedCase($this->region);

    $media = app(MediaService::class)->store(
        UploadedFile::fake()->image('id.jpg'),
        $case,
        'identity',
        userWithRole('case_officer'),
    );

    // On the private disk, not the one the site serves from.
    Storage::disk(config('sanabel.media_disk'))->assertExists($media->storage_key);
    Storage::disk('public')->assertMissing($media->storage_key);

    // And nothing about it reaches a donor.
    $payload = (new App\Http\Resources\MaskedCaseResource($case))->toArray(request());

    expect(json_encode($payload))->not->toContain($media->storage_key)
        ->and(array_keys($payload))->not->toContain('documents');
});

it('archives a paper rather than erasing it', function () {
    $case = publishedCase($this->region);

    $media = app(MediaService::class)->store(
        UploadedFile::fake()->create('debt.pdf', 10, 'application/pdf'),
        $case,
        'debt',
        userWithRole('case_officer'),
    );

    app(MediaService::class)->softDelete($media);

    expect($case->documents()->count())->toBe(0)
        ->and(Media::withTrashed()->find($media->id))->not->toBeNull()
        // Rule 3 — the object stays too, so an archived paper can be produced.
        ->and(Storage::disk(config('sanabel.media_disk'))->exists($media->storage_key))->toBeTrue();
});

it('lets the roles that hold the file attach a paper, and no others', function () {
    foreach (['case_officer', 'delegate', 'area_supervisor', 'data_officer'] as $role) {
        expect(userWithRole($role)->can_('upload_media'))->toBeTrue("{$role} should attach papers");
    }

    foreach (['donor', 'service_provider', 'finance', 'treasurer', 'content_manager', 'council'] as $role) {
        expect(userWithRole($role)->can_('upload_media'))->toBeFalse("{$role} should not attach papers");
    }
});
