<?php

use App\Models\Donor;
use App\Services\DonationService;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 | Two changes of 30 Sep 2026: an association sees the families of its city,
 | and a donor can see the record of the transfer the association made onward.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('shows an association the families of its own city', function () {
    $association = userWithRole('association', ['region_id' => $this->region->id]);
    $caseInCity = familyOf($this->region);

    // Not referred by this association, but in the city it works in.
    expect($association->can('view', $caseInCity))->toBeTrue();
});

it('still keeps an association out of another city', function () {
    $association = userWithRole('association', ['region_id' => $this->region->id]);
    $elsewhere = familyOf(regionWithRates());

    expect($association->can('view', $elsewhere))->toBeFalse();
});

it('keeps a file the association referred even after it moved city', function () {
    $association = userWithRole('association', ['region_id' => $this->region->id]);
    $moved = familyOf(regionWithRates(), attributes: ['created_by' => $association->id]);

    expect($association->can('view', $moved))->toBeTrue();
});

it('lets finance attach a transfer record a donor can then open', function () {
    Storage::fake(config('sanabel.media_disk'));

    $donor = Donor::factory()->create();
    $admin = userWithRole('admin');

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => 20_000,
        'transaction_ref' => 'TRX-RECORD',
        'designation' => 'general',
    ]);
    app(DonationService::class)->verify($donation, $admin->id);

    $media = app(MediaService::class)->store(
        UploadedFile::fake()->image('transfer.jpg'),
        $donation,
        'transfer_record',
        $admin,
    );
    $donation->forceFill(['transfer_record_media_id' => $media->getKey()])->save();

    expect($donation->refresh()->transferRecord->getKey())->toBe($media->getKey())
        // Private disk: the donor gets a signed link, never a public path.
        ->and(app(MediaService::class)->temporaryUrl($media))->toBeString();
});

it('leaves the transfer record empty until the association uploads one', function () {
    $donor = Donor::factory()->create();

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => 5_000,
        'transaction_ref' => 'TRX-NO-RECORD',
        'designation' => 'general',
    ]);

    expect($donation->transferRecord)->toBeNull();
});

it('still freezes the money on a verified donation', function () {
    $donor = Donor::factory()->create();
    $admin = userWithRole('admin');

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => 10_000,
        'transaction_ref' => 'TRX-FROZEN',
        'designation' => 'general',
    ]);
    app(DonationService::class)->verify($donation, $admin->id);

    // Evidence is allowed; the amount and the reference are not.
    expect(fn () => $donation->refresh()->forceFill(['amount' => 99_000])->save())
        ->toThrow(RuntimeException::class);

    expect(fn () => $donation->refresh()->forceFill(['transaction_ref' => 'TRX-OTHER'])->save())
        ->toThrow(RuntimeException::class);

    // And evidence bundled with a money change is refused as a whole.
    expect(fn () => $donation->refresh()->forceFill([
        'transfer_record_media_id' => 1,
        'amount' => 55_000,
    ])->save())->toThrow(RuntimeException::class);
});
