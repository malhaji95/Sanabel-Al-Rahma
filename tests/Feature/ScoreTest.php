<?php

use App\Models\HealthRecord;
use App\Services\AssessmentService;
use App\Services\DependencyRules;
use App\Services\ScoreService;

beforeEach(function () {
    seedCore();
});

it('bands overcrowding by persons per habitable room', function (int $persons, int $rooms, int $expected) {
    expect(ScoreService::overcrowdingBand($persons, $rooms))->toBe($expected);
})->with([
    'exactly 3 per room' => [6, 2, 25],
    '3.5 per room' => [7, 2, 50],
    '6 per room' => [6, 1, 100],
    '2 per room is not crowded' => [4, 2, 0],
]);

it('excludes kitchen and bathroom from the room count', function () {
    // habitable_rooms holds only rooms people live in: a flat with 2 bedrooms,
    // a kitchen and a bathroom is 2 habitable rooms, not 4.
    $region = regionWithRates();
    $family = familyOf($region, adults: 2, children: 5);
    $family->housing->update(['habitable_rooms' => 2]);

    $counted = ScoreService::overcrowdingBand(7, $family->housing->fresh()->habitable_rooms);
    $ifKitchenAndBathCounted = ScoreService::overcrowdingBand(7, 4);

    // Counting the kitchen and bathroom would report this flat as not crowded at all.
    expect($counted)->toBe(50)
        ->and($ifKitchenAndBathCounted)->toBe(0);
});

it('does not mark an unemployed adult as unable to earn without a documented condition', function () {
    expect(DependencyRules::isUnableToEarn(hasDocumentedCondition: false))->toBeFalse()
        ->and(DependencyRules::isUnableToEarn(hasDocumentedCondition: true))->toBeTrue();
});

it('marks a full-time student aged 20 with no income as dependent but able to earn', function () {
    expect(DependencyRules::isDependent(age: 20, isStudent: true, hasOwnIncome: false))->toBeTrue()
        ->and(DependencyRules::isUnableToEarn(hasDocumentedCondition: false))->toBeFalse()
        ->and(DependencyRules::isDependent(age: 20, isStudent: false, hasOwnIncome: false))->toBeFalse();
});

it('stores the automatic score, the new score, the reason and the approver on an override', function () {
    $region = regionWithRates();
    $family = familyOf($region);
    $assessment = app(AssessmentService::class)->create($family, status: 'approved');
    $auto = $assessment->base_score;

    $requester = userWithRole('case_officer');
    $approver = userWithRole('admin');

    $override = app(AssessmentService::class)->override(
        $assessment, 88.5, 'ظروف استثنائية موثقة', $requester->id, $approver->id
    );

    expect($override->auto_score)->toBe($auto)
        ->and($override->new_score)->toBe(88.5)
        ->and($override->reason_ar)->toBe('ظروف استثنائية موثقة')
        ->and($override->requested_by)->toBe($requester->id)
        ->and($override->approved_by)->toBe($approver->id)
        // The automatic score is never erased.
        ->and($assessment->fresh()->base_score)->toBe($auto)
        ->and($assessment->fresh()->effectiveScore())->toBe(88.5);
});

it('refuses to overwrite the automatic score on an existing override', function () {
    $region = regionWithRates();
    $family = familyOf($region);
    $assessment = app(AssessmentService::class)->create($family, status: 'approved');

    $override = app(AssessmentService::class)->override(
        $assessment, 70.0, 'سبب', userWithRole('case_officer')->id, userWithRole('admin')->id
    );

    expect(fn () => $override->update(['auto_score' => 1]))
        ->toThrow(RuntimeException::class, 'auto_score is immutable');
});

it('computes the health factor from the worst band and the summed medical cost', function () {
    $region = regionWithRates();
    $family = familyOf($region, adults: 2, children: 3); // need 16,000

    HealthRecord::factory()->create([
        'beneficiary_id' => $family->id,
        'severity_band' => 100, 'economic_impact_band' => 50,
        'care_burden_band' => 0, 'monthly_medical_cost' => 1_600,
    ]);
    HealthRecord::factory()->create([
        'beneficiary_id' => $family->id,
        'severity_band' => 25, 'economic_impact_band' => 75,
        'care_burden_band' => 100, 'monthly_medical_cost' => 1_600,
    ]);

    $assessment = app(AssessmentService::class)->create($family->refresh(), status: 'approved');

    // 0.45×100 + 0.25×75 + 0.15×100 + 0.15×(100×3200/16000 = 20) = 45 + 18.75 + 15 + 3 = 81.75
    expect($assessment->factors_json['M'])->toBe(81.75);
});

it('bands urgency by the distance to the deadline', function () {
    $now = now();

    expect(ScoreService::urgencyBand(null, $now))->toBe(0)
        ->and(ScoreService::urgencyBand($now->copy()->addHours(24), $now))->toBe(100)
        ->and(ScoreService::urgencyBand($now->copy()->addDays(5), $now))->toBe(75)
        ->and(ScoreService::urgencyBand($now->copy()->addDays(20), $now))->toBe(50)
        ->and(ScoreService::urgencyBand($now->copy()->addDays(60), $now))->toBe(25)
        ->and(ScoreService::urgencyBand($now->copy()->addDays(200), $now))->toBe(0);
});

it('weights the base score exactly as docs/03-rules.md states', function () {
    $region = regionWithRates();
    // No income, no health, no housing risk, no debt, no deadline, no support:
    // F = 100, M = 0, H = 0.20×overcrowding only, U = 0, D = 100, B = 0.
    $family = familyOf($region, adults: 2, children: 3);
    $family->housing->update(['habitable_rooms' => 4]); // 5 persons / 4 rooms => band 25

    $assessment = app(AssessmentService::class)->create($family->refresh(), status: 'approved');
    $f = $assessment->factors_json;

    $expected = 0.25 * $f['F'] + 0.20 * $f['M'] + 0.15 * $f['V']
        + 0.10 * $f['H'] + 0.15 * $f['U'] + 0.10 * $f['D'] + 0.05 * $f['B'];

    expect($assessment->base_score)->toBe(round($expected, 2))
        ->and($f['F'])->toEqual(100)
        ->and($f['D'])->toEqual(100)
        ->and($f['U'])->toEqual(0);
});
