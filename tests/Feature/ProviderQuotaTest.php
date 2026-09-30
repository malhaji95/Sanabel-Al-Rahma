<?php

use App\Models\Provider;
use App\Services\ReferralService;
use Illuminate\Support\Carbon;

/*
 | The association agreed a case ceiling per provider on 30 Sep 2026. It either
 | renews each Gregorian month or stands as a fixed balance, and passing it
 | warns rather than blocks.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->referrals = app(ReferralService::class);
});

function providerWith(array $attributes = []): Provider
{
    return Provider::factory()->create(array_merge([
        'region_id' => test()->region->id,
        'case_quota' => 3,
        'quota_period' => 'monthly',
        'case_value' => 25_000,
    ], $attributes));
}

it('draws a monthly quota down as cards are issued', function () {
    $provider = providerWith();
    $case = familyOf($this->region);

    expect($provider->quotaRemaining())->toBe(3);

    $this->referrals->issue($case, $provider);
    $this->referrals->issue($case, $provider);

    expect($provider->refresh()->quotaRemaining())->toBe(1)
        ->and($provider->quotaExceeded())->toBeFalse();
});

it('renews a monthly quota with the new month, without anything resetting it', function () {
    $provider = providerWith();
    $case = familyOf($this->region);

    Carbon::setTestNow(now()->startOfMonth()->addDays(3));
    $this->referrals->issue($case, $provider);
    $this->referrals->issue($case, $provider);
    $this->referrals->issue($case, $provider);

    expect($provider->refresh()->quotaRemaining())->toBe(0);

    // A new month counts its own cards, so the ceiling is whole again.
    Carbon::setTestNow(now()->addMonth()->startOfMonth()->addDay());

    expect($provider->refresh()->quotaRemaining())->toBe(3);
});

it('never renews a fixed balance', function () {
    $provider = providerWith(['quota_period' => 'fixed', 'case_quota' => 2]);
    $case = familyOf($this->region);

    $this->referrals->issue($case, $provider);
    $this->referrals->issue($case, $provider);

    expect($provider->refresh()->quotaRemaining())->toBe(0);

    Carbon::setTestNow(now()->addMonths(3));

    expect($provider->refresh()->quotaRemaining())->toBe(0);
});

it('still issues the card once the quota is passed, and says so', function () {
    $provider = providerWith(['case_quota' => 1]);
    $case = familyOf($this->region);

    $this->referrals->issue($case, $provider);
    $second = $this->referrals->issue($case, $provider);

    expect($second->status)->toBe('issued')
        ->and($provider->refresh()->quotaExceeded())->toBeTrue()
        ->and($provider->quotaRemaining())->toBe(-1);
});

it('leaves a provider with no agreed ceiling unlimited', function () {
    $provider = providerWith(['case_quota' => null]);
    $case = familyOf($this->region);

    foreach (range(1, 5) as $ignored) {
        $this->referrals->issue($case, $provider);
    }

    expect($provider->refresh()->quotaRemaining())->toBeNull()
        ->and($provider->quotaExceeded())->toBeFalse();
});

it('counts each provider quota separately', function () {
    $busy = providerWith(['case_quota' => 1]);
    $quiet = providerWith(['case_quota' => 1]);
    $case = familyOf($this->region);

    $this->referrals->issue($case, $busy);

    expect($busy->refresh()->quotaExceeded())->toBeTrue()
        ->and($quiet->refresh()->quotaExceeded())->toBeFalse();
});
