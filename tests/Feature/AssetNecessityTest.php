<?php

use App\Models\Asset;
use App\Services\NeedEngine;
use App\Services\RankingService;

/*
 | T-53 — approved 11 October: owning something must not lower entitlement by
 | itself. An asset needed for the home, for work or for a living is recorded
 | and lowers nothing. A surplus, investment or non-essential asset that could
 | be sold bears on eligibility or priority, under criteria the association
 | approves separately — and by a rule in the system, not a clerk's judgement.
 |
 | T-64, 12 October: asked for the value above which that happens, they
 | answered there is no such value — «يجب تقييمها يدوي». The committee judges
 | each case, so nothing here computes a verdict; what the system owes them is
 | the researcher's description, and the surplus total is reported so they read
 | one number instead of adding up a list.
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

it('compares the surplus total to nothing at all', function () {
    owns($this->family, 'land', 90_000_000, 'surplus');

    // There is no threshold to compare it to and no method that would: the
    // committee judges by hand (12 October). The figure is reported, full stop.
    expect($this->ranking->surplusAssetValue($this->family))->toBe(90_000_000)
        ->and(method_exists($this->ranking, 'surplusAssetsOverThreshold'))->toBeFalse()
        ->and(config('sanabel.setting_defaults'))->not->toHaveKey('surplus_asset_threshold');
});

it('records whether an asset is worked, and whether the family controls it', function () {
    // The second question is the one a value could never have answered: a
    // quarter of an inherited field, unsellable without five brothers, is
    // worth a great deal and feeds nobody.
    $field = owns($this->family, 'land', 40_000_000, 'surplus');
    $field->update([
        'is_exploited' => false,
        'has_control' => false,
        'details_ar' => 'ربع أرض موروثة مع خمسة أشقاء، غير مزروعة ولا تُباع بلا موافقتهم',
    ]);

    $field->refresh();

    expect($field->is_exploited)->toBeFalse()
        ->and($field->has_control)->toBeFalse()
        ->and($field->details_ar)->toContain('أشقاء')
        // Still in the total, because the committee is the one that weighs it.
        ->and($this->ranking->surplusAssetValue($this->family))->toBe(40_000_000);
});

it('reads an asset as assessed only once all four answers are there', function () {
    $asset = owns($this->family, 'shop', 5_000_000, 'surplus');

    expect($asset->isAssessed())->toBeFalse();

    $asset->update(['is_exploited' => true, 'has_control' => true, 'details_ar' => 'محل مؤجر للغير']);

    expect($asset->fresh()->isAssessed())->toBeTrue();
});

it('leaves an imported asset readable as unassessed rather than refusing it', function () {
    // The old system never asked these questions, so a migrated row arrives
    // without them. It must still import.
    $imported = owns($this->family, 'car', 3_000_000, null);

    expect($imported->isAssessed())->toBeFalse()
        ->and($imported->exists)->toBeTrue();
});

it('does not touch the need, the score or the ranking', function () {
    $engine = app(NeedEngine::class);

    $needBefore = $engine->compute($this->family);
    $rankBefore = $this->ranking->rank($this->family);

    owns($this->family, 'land', 80_000_000, 'surplus');

    // The committee's judgement is what moves a family, not this figure. So
    // the family's own numbers are exactly where they were.
    //
    // The figures, not the whole array: the snapshot carries the second it was
    // computed in, and under a full suite run the two calls straddle one.
    $figures = fn (array $need) => array_intersect_key(
        $need,
        array_flip(['monthly_need', 'stable_income', 'received', 'gap', 'f']),
    );

    expect($figures($engine->compute($this->family->fresh())))->toBe($figures($needBefore))
        ->and($this->ranking->rank($this->family->fresh()))->toBe($rankBefore);
});
