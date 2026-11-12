<?php

use App\Models\AdjustmentCatalog;
use App\Models\Beneficiary;
use App\Models\HouseholdMember;
use App\Services\NeedEngine;
use App\Services\ReferenceResolver;
use App\Services\ScoreService;

/*
 | T-301 — «أن يُعامل مقدم الطلب كفرد من أفراد الأسرة في جميع المعادلات»,
 | 12 November.
 |
 | T-284 put the applicant into the pricing and the household's size. The
 | additional items and the vulnerability factor still read the member rows
 | alone, and the applicant is not one of those rows — so the result of a
 | study depended on which member of a household had been written as its
 | applicant.
 |
 | Their acceptance test, in their words: «إجراء الدراسة نفسها مرتين مع
 | تغيير مقدم الطلب فقط، والتأكد من تطابق النتائج عندما لا تتغير خصائص
 | الأسرة الفعلية».
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/**
 * One household of two adults, built twice over: once with the first adult
 * holding the file, once with the second. Nothing else differs.
 */
function householdHeldBy(array $applicant, array $other, array $children = []): Beneficiary
{
    $case = familyOf(test()->region, adults: 1, children: 0);
    $case->members()->forceDelete();

    $case->forceFill([
        'birth_date' => now()->subYears($applicant['age'])->toDateString(),
        'dependent' => $applicant['dependent'],
        'unable_to_earn' => $applicant['unable_to_earn'] ?? false,
        'work_income' => $applicant['work_income'] ?? null,
    ])->save();

    HouseholdMember::factory()->for($case)->create([
        'relation' => $other['relation'],
        'birth_date' => now()->subYears($other['age'])->toDateString(),
        'birth_year' => now()->subYears($other['age'])->year,
        'person_class' => $other['class'],
        'dependent' => $other['dependent'],
        'unable_to_earn' => $other['unable_to_earn'] ?? false,
        'work_income' => $other['work_income'] ?? null,
    ]);

    foreach ($children as $age) {
        HouseholdMember::factory()->for($case)->create([
            'relation' => 'son',
            'birth_date' => now()->subYears($age)->toDateString(),
            'birth_year' => now()->subYears($age)->year,
            'person_class' => 'child',
            'dependent' => true,
            'unable_to_earn' => false,
            'work_income' => null,
        ]);
    }

    return $case->refresh();
}

/** The whole result of a study, as the file would carry it. */
function studyOf(Beneficiary $case): array
{
    $need = app(NeedEngine::class)->compute($case);
    $score = app(ScoreService::class)->compute($case, $need);

    return [
        'need' => $need['monthly_need'],
        'adjustments' => array_keys($need['snapshot']['adjustments']),
        'factors' => $score['factors'],
        'score' => $score['base_score'],
    ];
}

it('gives the same result whichever of two people holds the file', function () {
    // An elderly woman and a working adult son, with two children. The same
    // household either way round.
    $elderly = ['age' => 70, 'dependent' => true, 'work_income' => null];
    $worker = ['age' => 40, 'dependent' => false, 'work_income' => 50_000];

    $grandmotherHolds = householdHeldBy(
        $elderly,
        ['relation' => 'son', 'age' => 40, 'class' => 'adult', 'dependent' => false, 'work_income' => 50_000],
        children: [8, 11],
    );

    $sonHolds = householdHeldBy(
        $worker,
        ['relation' => 'mother', 'age' => 70, 'class' => 'elderly', 'dependent' => true],
        children: [8, 11],
    );

    expect(studyOf($sonHolds))->toBe(studyOf($grandmotherHolds));
});

it('opens the elderly item for an elderly applicant living alone with grandchildren', function () {
    /*
     | Their own example. Before this, the only elderly person in the house
     | was the one holding the file, so the item never opened.
     */
    $case = householdHeldBy(
        ['age' => 70, 'dependent' => true],
        ['relation' => 'grandchild', 'age' => 8, 'class' => 'child', 'dependent' => true],
        children: [11],
    );

    expect(studyOf($case)['adjustments'])->toContain('elderly_present')
        ->and(studyOf($case)['adjustments'])->toContain('children_present');
});

it('reads an applicant who cannot earn, which the file could not even say before', function () {
    // The item has to be worth something before the money can follow it.
    AdjustmentCatalog::create([
        'key' => 'unable_to_earn_present',
        'name_ar' => 'وجود عاجز عن الكسب',
        'amount' => 5_000,
        'currency' => config('sanabel.currency'),
        'region_id' => null,
        'effective_from' => now()->subYear(),
        'version' => 1,
    ]);

    $able = householdHeldBy(
        ['age' => 45, 'dependent' => false, 'unable_to_earn' => false],
        ['relation' => 'son', 'age' => 10, 'class' => 'child', 'dependent' => true],
    );

    $unable = householdHeldBy(
        ['age' => 45, 'dependent' => false, 'unable_to_earn' => true],
        ['relation' => 'son', 'age' => 10, 'class' => 'child', 'dependent' => true],
    );

    expect(studyOf($able)['adjustments'])->not->toContain('unable_to_earn_present')
        ->and(studyOf($unable)['adjustments'])->toContain('unable_to_earn_present')
        // And the money follows: the item is an amount on the need.
        ->and(studyOf($unable)['need'])->toBeGreaterThan(studyOf($able)['need']);
});

it('counts an applicant who works as an earner, so she is not a house with no carer', function () {
    /*
     | A widow with three children who works. The earners were counted off
     | the member rows, so she was nobody — and «معيل وحيد» needs exactly one
     | earner, which nought is not.
     */
    $case = householdHeldBy(
        ['age' => 38, 'dependent' => false, 'work_income' => 40_000],
        ['relation' => 'son', 'age' => 9, 'class' => 'child', 'dependent' => true],
        children: [12, 15],
    );

    $w = app(ReferenceResolver::class)->weights(now())['values'];

    // One earner and three dependants: the single-carer share is in V.
    expect(studyOf($case)['factors']['V'])
        ->toBeGreaterThanOrEqual((float) $w['V_single_caregiver']);
});

it('counts the applicant once, even when a member row names them again', function () {
    $case = householdHeldBy(
        ['age' => 70, 'dependent' => true],
        ['relation' => 'grandchild', 'age' => 8, 'class' => 'child', 'dependent' => true],
    );

    $before = studyOf($case);

    // The same person written a second time, under «ربّ الأسرة».
    HouseholdMember::factory()->for($case)->create([
        'relation' => 'head',
        'birth_date' => now()->subYears(70)->toDateString(),
        'birth_year' => now()->subYears(70)->year,
        'person_class' => 'elderly',
        'dependent' => true,
        'unable_to_earn' => false,
    ]);

    expect(studyOf($case->refresh()))->toBe($before)
        ->and($case->people())->toHaveCount(2);
});
