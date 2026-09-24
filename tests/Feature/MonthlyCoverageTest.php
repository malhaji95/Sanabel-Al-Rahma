<?php

use App\Exceptions\ReservationUnavailable;
use App\Models\Beneficiary;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Setting;
use App\Services\BasketService;
use App\Services\CoverageService;
use App\Services\DonationService;
use Illuminate\Support\Carbon;

/**
 * Approved 24 Sep 2026: the coverage cycle is the Gregorian calendar month,
 * starting at the beginning of each month, with the next month opening for
 * funding a configurable number of days before this one ends.
 *
 * Until this, coverage compared everything a family had ever received against
 * one month's need, so a family funded once read as covered for ever and never
 * returned to the funding list.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

function fundFor(Beneficiary $case, int $amount, ?Carbon $month = null): Donation
{
    $donor = Donor::factory()->create();
    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($donor);

    $baskets->addItem($basket, $case, $amount, $month);
    $baskets->reserve($basket);

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'basket_id' => $basket->id,
        'amount' => $amount,
        'transaction_ref' => 'MONTH-'.uniqid(),
    ]);

    return app(DonationService::class)->verify($donation, userWithRole('admin')->id);
}

it('starts each family at zero coverage when a new month begins', function () {
    $case = publishedCase($this->region);
    $coverage = app(CoverageService::class);
    $need = $coverage->needAmount($case);

    fundFor($case, $need);

    expect($coverage->coveragePercent($case->fresh()))->toBe(100);

    // The first of next month: the same family, a fresh month, nothing received.
    Carbon::setTestNow(Carbon::now()->addMonthNoOverflow()->startOfMonth());

    expect($coverage->coveragePercent($case->fresh()))->toBe(0)
        ->and($coverage->remainingNeed($case->fresh()))->toBe($need);

    Carbon::setTestNow();
});

it('keeps each month accounted separately', function () {
    $case = publishedCase($this->region);
    $coverage = app(CoverageService::class);
    $need = $coverage->needAmount($case);

    $thisMonth = $coverage->currentMonth();
    fundFor($case, $need, $thisMonth);

    expect($coverage->confirmedForMonth($case->fresh(), $thisMonth))->toBe($need)
        ->and($coverage->confirmedForMonth($case->fresh(), $thisMonth->copy()->addMonth()))->toBe(0);
});

it('opens the next month only inside the window the association sets', function () {
    $coverage = app(CoverageService::class);
    Setting::put('next_month_opens_days_before', 7);

    // Mid-month: only the current month is fundable.
    Carbon::setTestNow(Carbon::create(2026, 10, 10, 12));
    expect($coverage->openMonths())->toHaveCount(1);

    // Seven days before the month ends, next month opens.
    Carbon::setTestNow(Carbon::create(2026, 10, 25, 12));
    $open = $coverage->openMonths();

    expect($open)->toHaveCount(2)
        ->and($open[1]->format('Y-m'))->toBe('2026-11');

    Carbon::setTestNow();
});

it('refuses a pledge for a month that has not opened yet', function () {
    $case = publishedCase($this->region);
    Setting::put('next_month_opens_days_before', 7);
    Carbon::setTestNow(Carbon::create(2026, 10, 10, 12));

    $basket = app(BasketService::class)->openFor(Donor::factory()->create());

    expect(fn () => app(BasketService::class)->addItem($basket, $case, 1_000, Carbon::create(2026, 11, 1)))
        ->toThrow(ReservationUnavailable::class);

    Carbon::setTestNow();
});

it('lets a donor cover next month before this one ends, with no gap', function () {
    $case = publishedCase($this->region);
    $coverage = app(CoverageService::class);
    Setting::put('next_month_opens_days_before', 7);

    Carbon::setTestNow(Carbon::create(2026, 10, 27, 12));
    $need = $coverage->needAmount($case);

    fundFor($case, $need, Carbon::create(2026, 11, 1));

    // October is untouched; November is already covered before it starts.
    expect($coverage->confirmedForMonth($case->fresh(), Carbon::create(2026, 10, 1)))->toBe(0)
        ->and($coverage->confirmedForMonth($case->fresh(), Carbon::create(2026, 11, 1)))->toBe($need);

    // And when November arrives the family is not waiting to be funded.
    Carbon::setTestNow(Carbon::create(2026, 11, 1, 0, 1));
    expect($coverage->coveragePercent($case->fresh()))->toBe(100);

    Carbon::setTestNow();
});

it('does not let this month and next month claim the same money', function () {
    $case = publishedCase($this->region);
    $coverage = app(CoverageService::class);
    Setting::put('next_month_opens_days_before', 7);
    Carbon::setTestNow(Carbon::create(2026, 10, 27, 12));

    $need = $coverage->needAmount($case);
    fundFor($case, $need, Carbon::create(2026, 10, 1));

    // October is full, November is still entirely open.
    expect($coverage->remainingNeed($case->fresh(), month: Carbon::create(2026, 10, 1)))->toBe(0)
        ->and($coverage->remainingNeed($case->fresh(), month: Carbon::create(2026, 11, 1)))->toBe($need);

    Carbon::setTestNow();
});
