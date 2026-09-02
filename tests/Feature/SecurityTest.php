<?php

use App\Filament\Pages\TwoFactor;
use App\Filament\Resources\BeneficiaryResource;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\Media;
use App\Services\MediaService;
use App\Services\Totp;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('matches the RFC 6238 TOTP test vectors', function (int $timestamp, string $expected) {
    // RFC 6238 Appendix B, SHA-1, 8 digits, seed "12345678901234567890".
    $totp = new Totp(digits: 8);
    $secret = $totp->base32Encode('12345678901234567890');

    expect($totp->at($secret, $timestamp))->toBe($expected);
})->with([
    [59, '94287082'],
    [1111111109, '07081804'],
    [1111111111, '14050471'],
    [1234567890, '89005924'],
    [2000000000, '69279037'],
]);

it('accepts a code one step either side and refuses one further out', function () {
    $totp = new Totp;
    $secret = $totp->generateSecret();
    $now = 1_700_000_000;

    expect($totp->verify($secret, $totp->at($secret, $now), $now))->toBeTrue()
        ->and($totp->verify($secret, $totp->at($secret, $now - 30), $now))->toBeTrue()
        ->and($totp->verify($secret, $totp->at($secret, $now + 30), $now))->toBeTrue()
        ->and($totp->verify($secret, $totp->at($secret, $now + 120), $now))->toBeFalse()
        ->and($totp->verify($secret, '000000', $now))->toBeFalse()
        ->and($totp->verify($secret, 'abc', $now))->toBeFalse();
});

it('round-trips base32', function () {
    $totp = new Totp;

    foreach (['', 'a', 'hello world', random_bytes(20)] as $input) {
        expect($totp->base32Decode($totp->base32Encode($input)))->toBe($input);
    }
});

it('sends an admin to the two-factor screen before any panel page', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = userWithRole('admin');

    $this->actingAs($admin)
        ->get(BeneficiaryResource::getUrl('index'))
        ->assertRedirect(TwoFactor::getUrl(panel: 'admin'));
});

it('lets an admin enrol and then reach the panel', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = userWithRole('admin');

    $page = Livewire\Livewire::actingAs($admin)
        ->test(TwoFactor::class)
        ->assertSuccessful();

    $secret = $page->get('pendingSecret');
    expect($secret)->not->toBeNull();

    $page->fillForm(['code' => app(Totp::class)->at($secret)])->call('confirm');

    expect($admin->fresh()->hasConfirmedTwoFactor())->toBeTrue()
        ->and(session()->has(RequireTwoFactor::SESSION_KEY))->toBeTrue();

    $this->actingAs($admin)
        ->get(BeneficiaryResource::getUrl('index'))
        ->assertSuccessful();
});

it('refuses a wrong code and does not store the secret', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = userWithRole('admin');

    Livewire\Livewire::actingAs($admin)
        ->test(TwoFactor::class)
        ->fillForm(['code' => '000000'])
        ->call('confirm');

    expect($admin->fresh()->two_factor_secret)->toBeNull()
        ->and(session()->has(RequireTwoFactor::SESSION_KEY))->toBeFalse();
});

it('requires two factor for council as well, but not for a delegate', function () {
    expect(userWithRole('admin')->requiresTwoFactor())->toBeTrue()
        ->and(userWithRole('council')->requiresTwoFactor())->toBeTrue()
        ->and(userWithRole('delegate')->requiresTwoFactor())->toBeFalse()
        ->and(userWithRole('association')->requiresTwoFactor())->toBeFalse();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // A delegate is never diverted.
    $this->actingAs(userWithRole('delegate', ['region_id' => $this->region->id]))
        ->get(BeneficiaryResource::getUrl('index'))
        ->assertSuccessful();
});

it('rate limits the national ID lookup', function () {
    $association = userWithRole('association', ['region_id' => $this->region->id]);

    // The configured limit is ten per minute for the one route that takes an ID.
    foreach (range(1, 10) as $i) {
        $this->actingAs($association, 'sanctum')
            ->postJson(route('coordination.lookup'), ['national_id' => "1234567890{$i}"])
            ->assertOk();
    }

    $this->actingAs($association, 'sanctum')
        ->postJson(route('coordination.lookup'), ['national_id' => '99999999999'])
        ->assertStatus(429);
});

it('never exposes a secret or a hash through a model', function () {
    $case = publishedCase($this->region);
    $user = userWithRole('admin');

    $caseJson = $case->toJson();
    $userJson = $user->toJson();

    foreach (['national_id_encrypted', 'national_id_hash', 'phone_encrypted', 'wallet_encrypted'] as $key) {
        expect($caseJson)->not->toContain($key);
    }

    foreach (['password', 'two_factor_secret', 'phone_encrypted', 'remember_token'] as $key) {
        expect($userJson)->not->toContain($key);
    }
});

it('keeps media private with a signed, expiring URL', function () {
    Storage::fake('media_test');

    $case = publishedCase($this->region);
    $uploader = userWithRole('delegate', ['region_id' => $this->region->id]);

    $media = app(MediaService::class)->store(
        UploadedFile::fake()->image('receipt.jpg'),
        $case,
        'receipt',
        $uploader,
    );

    expect($media->visibility)->toBe('internal')
        // The stored key carries nothing about the family.
        ->and($media->storage_key)->not->toContain($case->file_number)
        ->and($media->storage_key)->not->toContain($case->family_name)
        ->and(Storage::disk('media_test')->exists($media->storage_key))->toBeTrue();

    $url = app(MediaService::class)->temporaryUrl($media);

    // Time-limited whichever driver is behind it: S3 signs, the local disk
    // stamps an expiry. What matters is that no permanent URL exists.
    expect($url)->toMatch('/(signature|expiration|Expires)/i')
        ->and(app(MediaService::class)->temporaryUrl($media, 60))
        ->not->toBe($url);

    // Rule 3 — a removed file is soft-deleted, never destroyed.
    app(MediaService::class)->softDelete($media);

    expect(Media::withTrashed()->find($media->id))->not->toBeNull()
        ->and(Storage::disk('media_test')->exists($media->storage_key))->toBeTrue();
});
