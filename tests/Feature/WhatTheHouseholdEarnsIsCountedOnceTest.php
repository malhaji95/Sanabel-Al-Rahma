<?php

use App\Models\HouseholdMember;
use App\Services\AssessmentService;
use App\Services\NeedEngine;

/*
 | T-221 — «ينجمع تلقائياً», 1 November, choosing (ب) of the two we put to
 | the association.
 |
 | T-121 recorded what a person's work pays and deliberately kept it out of
 | the score, because the same money was also written as an `incomes` row
 | and counting both would count it twice. The cost of that was a researcher
 | who had to tell the engine a salary twice, and know which of the two
 | places was the one that counted. Writing it once meant a household looked
 | poorer than it is.
 |
 | Now the three places a person's own earnings are written — the head, the
 | spouse, each member — are summed by the engine, as livestock income
 | already was, and the income table is for what is not written on a person.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->engine = app(NeedEngine::class);
});

it('counts what the head of the file earns', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    $case->forceFill(['work_income' => 120_000])->save();

    expect($this->engine->compute($case->fresh())['stable_income'])->toBe(120_000);
});

it('counts the spouse’s earnings beside them', function () {
    $case = familyOf($this->region, adults: 2, children: 0);
    $case->forceFill(['work_income' => 120_000, 'spouse_work_income' => 80_000])->save();

    expect($this->engine->compute($case->fresh())['stable_income'])->toBe(200_000);
});

it('counts a member of the household who earns too', function () {
    $case = familyOf($this->region, adults: 2, children: 2);
    $case->forceFill(['work_income' => 100_000])->save();

    HouseholdMember::where('beneficiary_id', $case->id)
        ->limit(1)
        ->update(['work_income' => 45_000]);

    expect($this->engine->compute($case->fresh())['stable_income'])->toBe(145_000);
});

it('adds the income rows to it rather than replacing them', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    $case->forceFill(['work_income' => 100_000])->save();

    // A handout, which is not written on anybody's own page.
    $case->incomes()->create([
        'source_type' => 'إعانة شهرية من جمعية',
        'amount' => 60_000,
        'currency' => 'SYP',
        'is_stable' => true,
    ]);

    expect($this->engine->compute($case->fresh())['stable_income'])->toBe(160_000);
});

it('leaves an unstable income row out, as it always did', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    $case->forceFill(['work_income' => 100_000])->save();

    $case->incomes()->create([
        'source_type' => 'عمل موسمي',
        'amount' => 70_000,
        'currency' => 'SYP',
        'is_stable' => false,
        'recurrence' => 'موسمي',
    ]);

    expect($this->engine->compute($case->fresh())['stable_income'])->toBe(100_000);
});

it('counts nothing at all for a household where nobody earns', function () {
    $case = familyOf($this->region, adults: 1, children: 2);

    expect($this->engine->compute($case->fresh())['stable_income'])->toBe(0);
});

it('lowers the gap by what the household earns, which is the whole point', function () {
    $poor = familyOf($this->region, adults: 2, children: 2);
    $earning = familyOf($this->region, adults: 2, children: 2);
    // Less than the household is short, so the gap narrows rather than
    // closing: `max(0, …)` would hide the arithmetic otherwise.
    $earning->forceFill(['work_income' => 5_000])->save();

    $a = $this->engine->compute($poor->fresh());
    $b = $this->engine->compute($earning->fresh());

    expect($a['monthly_need'])->toBe($b['monthly_need'])
        ->and($a['gap'])->toBeGreaterThan(5_000)
        ->and($b['gap'])->toBe($a['gap'] - 5_000);
});

it('writes the figure into the snapshot, so an old assessment still explains itself', function () {
    $case = familyOf($this->region, adults: 2, children: 0);
    $case->forceFill(['work_income' => 90_000, 'spouse_work_income' => 10_000])->save();

    $assessment = app(AssessmentService::class)->create($case->fresh(), status: 'approved');

    expect($assessment->snapshot_json['person_work_income'])->toBe(100_000);
});

it('does not move an assessment that was computed before the change', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    $before = app(AssessmentService::class)->create($case, status: 'approved');
    $wasIncome = $before->stable_income;

    // Rule 8 — the snapshot is the record. A figure written on the file
    // afterwards changes the next assessment, never the one already signed.
    $case->forceFill(['work_income' => 500_000])->save();

    expect($before->fresh()->stable_income)->toBe($wasIncome);
});
