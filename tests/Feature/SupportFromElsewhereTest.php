<?php

use App\Models\SupportSource;
use App\Services\AssessmentService;
use App\Services\CoverageService;
use App\Services\DisbursementService;
use App\Services\NeedEngine;

/*
 | T-51 — support another body actually paid.
 |
 | Approved 11 October: it comes off the remaining need for the month it
 | answers, exactly as a partial donation does; it is coverage and never
 | income, so it leaves stable income and the score factor alone; and it does
 | not travel to the following month.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->family = familyOf($this->region, adults: 1, children: 1);
    $this->coverage = app(CoverageService::class);
});

function paidByAnother($family, int $amount, string $month, string $kind = 'one_off'): SupportSource
{
    return SupportSource::create([
        'beneficiary_id' => $family->getKey(),
        'name_ar' => 'جمعية البر',
        'kind' => $kind,
        'amount' => $amount,
        'currency' => config('sanabel.currency'),
        'coverage_month' => $month,
        'paid_on' => $month,
    ]);
}

it('takes what another body paid off the same month and no other', function () {
    $thisMonth = $this->coverage->currentMonth();
    paidByAnother($this->family, 30_000, $thisMonth->toDateString());

    expect($this->coverage->externalForMonth($this->family, $thisMonth))->toBe(30_000)
        ->and($this->coverage->externalForMonth($this->family, $thisMonth->copy()->addMonth()))->toBe(0)
        ->and($this->coverage->externalForMonth($this->family, $thisMonth->copy()->subMonth()))->toBe(0);
});

it('lowers the remaining need by what was paid', function () {
    $thisMonth = $this->coverage->currentMonth();
    app(AssessmentService::class)->create($this->family, status: 'approved');

    $before = $this->coverage->remainingNeed($this->family);
    paidByAnother($this->family, 30_000, $thisMonth->toDateString());

    expect($this->coverage->remainingNeed($this->family))->toBe(max(0, $before - 30_000));
});

it('is coverage, not income: it moves the gap but never the score factor', function () {
    $engine = app(NeedEngine::class);
    $before = $engine->compute($this->family);

    paidByAnother($this->family, 25_000, $this->coverage->currentMonth()->toDateString());
    $after = $engine->compute($this->family);

    expect($after['stable_income'])->toBe($before['stable_income'])
        ->and($after['f'])->toBe($before['f'])
        ->and($after['monthly_need'])->toBe($before['monthly_need'])
        ->and($after['received'])->toBe($before['received'] + 25_000)
        ->and($after['gap'])->toBe(max(0, $before['gap'] - 25_000))
        ->and($after['snapshot']['external_support'])->toBe(25_000);
});

it('does not carry a one-off payment into the next month', function () {
    $thisMonth = $this->coverage->currentMonth();
    app(AssessmentService::class)->create($this->family, status: 'approved');

    // Far more than the month's need, so a carry would be visible if there was one.
    paidByAnother($this->family, 10_000_000, $thisMonth->copy()->subMonth()->toDateString());

    expect($this->coverage->carriedInto($this->family, $thisMonth))->toBe(0)
        ->and($this->coverage->fundedForMonth($this->family, $thisMonth))->toBe(0);
});

it('lowers the ceiling on what the association may itself pay', function () {
    $period = $this->coverage->currentMonth();
    app(AssessmentService::class)->create($this->family, status: 'approved');
    $service = app(DisbursementService::class);

    $before = $service->remainingForPeriod($this->family, $period);
    paidByAnother($this->family, 20_000, $period->toDateString());

    expect($service->remainingForPeriod($this->family, $period))->toBe(max(0, $before - 20_000));
});

it('ignores a row that records a source without a payment', function () {
    SupportSource::create([
        'beneficiary_id' => $this->family->getKey(),
        'name_ar' => 'جمعية البر',
        'kind' => 'recurring',
        'notes_ar' => 'تدعمهم منذ سنة، المبلغ غير معروف',
    ]);

    expect($this->coverage->externalForMonth($this->family))->toBe(0);
});

it('reads many families in one query', function () {
    $other = familyOf($this->region, adults: 2, children: 0);
    $month = $this->coverage->currentMonth();
    paidByAnother($this->family, 30_000, $month->toDateString());
    paidByAnother($other, 11_000, $month->toDateString());

    expect($this->coverage->externalForMonthForMany([$this->family, $other], $month))
        ->toBe([$this->family->getKey() => 30_000, $other->getKey() => 11_000]);
});
