<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Services\NeedEngine;
use App\Services\RankingService;

/*
 | T-53 — approved 11 October: owning something must not lower entitlement by
 | itself. An asset needed for the home, for work or for a living is recorded
 | and lowers nothing. A surplus, investment or non-essential asset that could
 | be sold bears on eligibility or priority, under criteria the association
 | approves separately — and by a rule in the system, not a clerk's judgement.
 |
 | Those criteria are not approved yet, so the threshold is data with no
 | default: while it is unset the total is reported and changes nothing.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->family = familyOf($this->region, adults: 1, children: 1);
    $this->ranking = app(RankingService::class);
});

function owns($family, string $kind, int $value, ?string $necessity): Asset
{
    return Asset::create([
        'beneficiary_id' => $family->getKey(),
        'kind' => $kind,
        'necessity' => $necessity,
        'necessity_reason_ar' => $necessity === 'essential' ? 'يعمل عليه صاحبه' : null,
        'estimated_value' => $value,
        'currency' => config('sanabel.currency'),
    ]);
}

it('leaves a necessary asset out of the total, however much it is worth', function () {
    owns($this->family, 'tractor', 50_000_000, 'essential');

    expect($this->ranking->surplusAssetValue($this->family))->toBe(0);
});

it('leaves an asset nobody has answered the question on out of the total', function () {
    owns($this->family, 'land', 9_000_000, null);

    expect($this->ranking->surplusAssetValue($this->family))->toBe(0);
});

it('adds up the surplus ones only', function () {
    owns($this->family, 'tractor', 7_000_000, 'essential');
    owns($this->family, 'land', 4_000_000, 'surplus');
    owns($this->family, 'shop', 1_500_000, 'surplus');
    owns($this->family, 'car', 3_000_000, null);

    expect($this->ranking->surplusAssetValue($this->family))->toBe(5_500_000);
});

it('reports nothing about the threshold while the association has set none', function () {
    owns($this->family, 'land', 90_000_000, 'surplus');

    expect($this->ranking->surplusAssetsOverThreshold($this->family))->toBeNull();
});

it('answers against the number the association stored, once they store one', function () {
    Setting::updateOrCreate(['key' => 'surplus_asset_threshold'], ['value_json' => 5_000_000]);

    owns($this->family, 'land', 4_000_000, 'surplus');
    expect($this->ranking->surplusAssetsOverThreshold($this->family))->toBeFalse();

    owns($this->family, 'shop', 2_000_000, 'surplus');
    expect($this->ranking->surplusAssetsOverThreshold($this->family->fresh()))->toBeTrue();
});

it('keeps a necessary asset out even once a threshold is in force', function () {
    Setting::updateOrCreate(['key' => 'surplus_asset_threshold'], ['value_json' => 1_000_000]);
    owns($this->family, 'tractor', 50_000_000, 'essential');

    expect($this->ranking->surplusAssetsOverThreshold($this->family))->toBeFalse();
});

it('does not touch the need, the score or the ranking', function () {
    Setting::updateOrCreate(['key' => 'surplus_asset_threshold'], ['value_json' => 1_000_000]);
    $engine = app(NeedEngine::class);

    $needBefore = $engine->compute($this->family);
    $rankBefore = $this->ranking->rank($this->family);

    owns($this->family, 'land', 80_000_000, 'surplus');

    // The effect on eligibility or priority is the association's decision and
    // they have not made it. Until they do, the figure is reported and the
    // family's own numbers are exactly where they were.
    expect($engine->compute($this->family->fresh()))->toBe($needBefore)
        ->and($this->ranking->rank($this->family->fresh()))->toBe($rankBefore);
});
