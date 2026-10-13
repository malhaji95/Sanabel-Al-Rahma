<?php

use App\Exceptions\ReservationUnavailable;
use App\Http\Resources\MaskedCaseResource;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Models\Region;
use App\Models\Setting;
use App\Services\BasketService;
use App\Services\CoverageService;
use App\Services\DonationService;
use App\Services\RankingService;

/*
 | T-78 — the funding target, decided by the association on 13 October.
 |
 | «ممكن 30% ممكن 50% ممكن 70% ممكن 100%»: the study, the engine and the real
 | monthly need stay exactly as they are, and the donor is shown a share of
 | that need so a family can be funded in stages.
 |
 | The rule everything here protects: the target is a display layer. The need,
 | the priority badge, the disbursement ceiling and the association's own
 | reports go on reading the real need, because a family at 100% of a 30%
 | target is still short seven tenths of what it needs.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->coverage = app(CoverageService::class);
    $this->admin = userWithRole('admin');
});

/** Money that actually arrived against this month, verified. */
function fundThisMonth($case, int $amount, $admin): void
{
    static $ref = 0;

    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => $amount,
        'transaction_ref' => 'TRX-TARGET-'.(++$ref),
    ]);

    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $case->id,
        'amount' => $amount,
        'currency' => config('sanabel.currency'),
        'coverage_month' => now()->startOfMonth(),
    ]);

    app(DonationService::class)->verify($donation, $admin->id);
}

it('asks for the whole need until the association lowers the share', function () {
    $case = publishedCase($this->region);

    expect(config('sanabel.setting_defaults.funding_target_percent'))->toBe(100)
        ->and($this->coverage->targetPercent($case))->toBe(100)
        ->and($this->coverage->targetAmount($case))->toBe($this->coverage->needAmount($case));
});

it('takes the share from the settings, so it moves with no deploy', function () {
    $case = publishedCase($this->region);
    $need = $this->coverage->needAmount($case);

    Setting::put('funding_target_percent', 30);
    Setting::forgetLoaded();

    expect($this->coverage->targetPercent($case->fresh()))->toBe(30)
        ->and($this->coverage->targetAmount($case->fresh()))->toBe(intdiv($need * 30, 100));

    // «نقدر كيف نعدلها»: raised later, and it shows at once.
    Setting::put('funding_target_percent', 50);
    Setting::forgetLoaded();

    expect($this->coverage->targetAmount($case->fresh()))->toBe(intdiv($need * 50, 100));
});

it('lets a region hold its own share, and reads it up the tree', function () {
    Setting::put('funding_target_percent', 30);
    Setting::forgetLoaded();

    $area = Region::factory()->create(['parent_id' => $this->region->id, 'funding_target_percent' => 60]);
    $village = Region::factory()->village()->create(['parent_id' => $area->id]);
    $case = publishedCase($this->region, attributes: ['region_id' => $village->id]);

    // The village says nothing, so it follows its area rather than the setting.
    expect($this->coverage->targetPercent($case))->toBe(60);

    $village->update(['funding_target_percent' => 80]);

    expect($this->coverage->targetPercent($case->fresh()))->toBe(80);
});

it('never asks for more than the month can still take', function () {
    $case = publishedCase($this->region);
    $need = $this->coverage->needAmount($case);

    Setting::put('funding_target_percent', 100);
    Setting::forgetLoaded();

    // Half the month is already funded, so the target has half of itself left.
    fundThisMonth($case, intdiv($need, 2), $this->admin);

    $funded = $this->coverage->fundedForMonth($case->fresh());

    expect($this->coverage->targetRemaining($case->fresh()))
        ->toBe($this->coverage->remainingNeed($case->fresh(), $funded));
});

it('clamps a mistyped share, so it can never ask for more than the need', function () {
    $case = publishedCase($this->region);

    foreach ([[-20, 0], [0, 0], [250, 100]] as [$typed, $expected]) {
        Setting::put('funding_target_percent', $typed);
        Setting::forgetLoaded();

        expect($this->coverage->targetPercent($case->fresh()))->toBe($expected);
    }
});

it('calls the stage complete without calling the family covered', function () {
    $case = publishedCase($this->region);
    $need = $this->coverage->needAmount($case);

    Setting::put('funding_target_percent', 30);
    Setting::forgetLoaded();

    $target = intdiv($need * 30, 100);

    fundThisMonth($case, $target, $this->admin);

    $case = $case->fresh();

    expect($this->coverage->targetIsComplete($case))->toBeTrue()
        ->and($this->coverage->targetRemaining($case))->toBe(0)
        // And the family is still short seven tenths of its month.
        ->and($this->coverage->coveragePercent($case))->toBeLessThan(100)
        ->and($this->coverage->remainingNeed($case))->toBeGreaterThan(0);
});

it('keeps the priority badge on the real need, not on the stage', function () {
    $case = publishedCase($this->region);
    $need = $this->coverage->needAmount($case);
    $ranking = app(RankingService::class);

    Setting::put('funding_target_percent', 30);
    Setting::forgetLoaded();

    $target = intdiv($need * 30, 100);
    fundThisMonth($case, $target, $this->admin);

    // A badge read off the stage would say «complete»; read off the need it
    // still says this family is among the least covered.
    expect($this->coverage->priorityBand($case->fresh()))->not->toBe('complete');
});

it('leaves the engine, the ceiling and the need untouched', function () {
    $case = publishedCase($this->region);
    $before = $this->coverage->needAmount($case);

    Setting::put('funding_target_percent', 30);
    Setting::forgetLoaded();

    expect($this->coverage->needAmount($case->fresh()))->toBe($before)
        // What a reservation may still claim is the real need, never the stage.
        ->and($this->coverage->remainingNeed($case->fresh()))->toBe($before);
});

it('still lets a donor fund the whole month, past the stage', function () {
    $case = publishedCase($this->region);
    $need = $this->coverage->needAmount($case);

    Setting::put('funding_target_percent', 30);
    Setting::forgetLoaded();

    $donor = Donor::factory()->create();
    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($donor);

    // The whole month, which is more than the stage and within the need.
    $baskets->addItem($basket, $case->fresh(), $need, $this->coverage->currentMonth());

    expect($basket->fresh()->total())->toBe($need);
});

it('refuses more than the real remaining need, whatever the stage says', function () {
    $case = publishedCase($this->region);
    $need = $this->coverage->needAmount($case);

    $donor = Donor::factory()->create();
    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($donor);

    // The ceiling is the real remaining need, and it bites when the basket is
    // reserved — where it has always been. The stage changes nothing about it.
    $baskets->addItem($basket, $case, $need + 1000, $this->coverage->currentMonth());

    expect(fn () => $baskets->reserve($basket->fresh()))
        ->toThrow(ReservationUnavailable::class);
})->group('PAY-03');

it('shows the donor the stage and the real need side by side', function () {
    $case = publishedCase($this->region);

    Setting::put('funding_target_percent', 30);
    Setting::forgetLoaded();

    $payload = (new MaskedCaseResource($case->fresh()))->toArray(request());

    expect($payload['target_percent'])->toBe(30)
        ->and($payload)->toHaveKey('target_amount')
        ->and($payload)->toHaveKey('target_remaining')
        ->and($payload)->toHaveKey('target_complete')
        // The real need stays on the card beside the stage.
        ->and($payload['need_amount'])->toBe($this->coverage->needAmount($case->fresh()));

    // And nothing outside the allowlist came with it.
    expect(array_diff(array_keys($payload), MaskedCaseResource::ALLOWED_KEYS))->toBeEmpty();
})->group('AUTH-03');

it('switches the sufficiency note on the list and in the basket separately', function () {
    $case = publishedCase($this->region);

    Setting::put('show_sufficiency_note', false);
    Setting::put('show_sufficiency_note_in_basket', true);
    Setting::forgetLoaded();

    $payload = (new MaskedCaseResource($case->fresh()))->toArray(request());

    expect($payload['show_sufficiency_note'])->toBeFalse()
        ->and((bool) Setting::value('show_sufficiency_note_in_basket'))->toBeTrue();

    // Both switches exist as data, so the association moves them itself.
    expect(config('sanabel.setting_defaults'))->toHaveKey('show_sufficiency_note')
        ->and(config('sanabel.setting_defaults'))->toHaveKey('show_sufficiency_note_in_basket');
});

it('says the sentence the association dictated, with both numbers in it', function () {
    $note = __('sanabel.funding.sufficiency_note', [
        'need' => '100,000',
        'currency' => 'ل.س',
        'percent' => 30,
    ]);

    expect($note)->toContain('100,000')
        ->and($note)->toContain('30')
        ->and($note)->not->toContain(':need')
        ->and($note)->not->toContain(':percent');
});
