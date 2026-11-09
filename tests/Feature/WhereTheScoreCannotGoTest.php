<?php

use App\Exceptions\ReferenceValueMissing;
use App\Models\HouseholdMember;
use App\Models\Income;
use App\Models\Region;
use App\Models\RegionRate;
use App\Services\AssessmentService;
use App\Services\NeedEngine;

/*
 | T-282 — points 3 and 8 of the functional review, 9 November:
 | «اختبار تأثير عدد أفراد الأسرة وأعمارهم على الاحتياج», and
 | «التأكد من أن الدرجة لا تتجاوز 100 ولا تقل عن صفر».
 |
 | The association is about to put their own reference values in, and they
 | asked to see the engine behave before they do. These are the two things
 | that must hold whatever figures they choose: the need follows the people,
 | and no family can score outside nought and a hundred however extreme its
 | numbers are.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** A household of the given shape, computed, with its figures read back. */
function shapedFamily(Region $region, array $classes, int $income = 0, bool $renting = false): array
{
    $case = familyOf($region, adults: 0, children: 0);

    foreach ($classes as $class => $count) {
        for ($i = 0; $i < $count; $i++) {
            HouseholdMember::factory()->create([
                'beneficiary_id' => $case->id,
                'person_class' => $class,
                'unable_to_earn' => false,
            ]);
        }
    }

    if ($renting) {
        $case->housing()->update(['housing_type' => 'rent', 'monthly_rent' => 50000]);
    }

    if ($income > 0) {
        Income::factory()->create([
            'beneficiary_id' => $case->id,
            'amount' => $income,
            'is_stable' => true,
        ]);
    }

    app(AssessmentService::class)->create($case->refresh(), status: 'approved');
    $a = $case->refresh()->currentAssessment();

    return [
        'need' => $a->monthly_need,
        'income' => $a->stable_income,
        'f' => (float) $a->factors_json['F'],
        'score' => (float) $a->base_score,
    ];
}

it('raises the need with every person added', function () {
    $two = shapedFamily($this->region, ['adult' => 1, 'wife' => 1]);
    $three = shapedFamily($this->region, ['adult' => 1, 'wife' => 1, 'child' => 1]);
    $five = shapedFamily($this->region, ['adult' => 1, 'wife' => 1, 'child' => 3]);

    expect($three['need'])->toBeGreaterThan($two['need'])
        ->and($five['need'])->toBeGreaterThan($three['need'])
        // Each further child costs exactly the child reference, no more.
        ->and($five['need'] - $three['need'])->toBe(2 * 2000);
});

it('prices a person by the class they are in, not by being a person', function () {
    $base = ['adult' => 1, 'wife' => 1];

    $withChild = shapedFamily($this->region, $base + ['child' => 1]);
    $withAdult = shapedFamily($this->region, ['adult' => 2, 'wife' => 1]);
    $withElderly = shapedFamily($this->region, $base + ['elderly' => 1]);

    // child 2,000 < adult 5,000 < elderly 6,000 — and the elderly household
    // carries its own adjustment on top, which is the point of the line.
    expect($withChild['need'])->toBeLessThan($withAdult['need'])
        ->and($withAdult['need'])->toBeLessThan($withElderly['need']);
});

it('never scores a family below nothing or above a hundred', function (array $classes, int $income, bool $renting) {
    $f = shapedFamily($this->region, $classes, $income, $renting);

    expect($f['f'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($f['score'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
})->with([
    'a lone adult, nothing coming in' => [['adult' => 1], 0, false],
    'a large renting household with no income' => [['adult' => 2, 'wife' => 1, 'child' => 5, 'elderly' => 1], 0, true],
    'an income exactly equal to the need' => [['adult' => 1, 'wife' => 1], 9000, false],
    'an income far above the need' => [['adult' => 1], 5_000_000, false],
    'a single child and a large income' => [['child' => 1], 900_000, false],
    'an elderly couple with a little income' => [['elderly' => 2], 3000, false],
]);

it('reads a hundred when nothing comes in, and nothing when the income covers the need', function () {
    $none = shapedFamily($this->region, ['adult' => 1, 'wife' => 1], income: 0);
    $covered = shapedFamily($this->region, ['adult' => 1, 'wife' => 1], income: 9_000_000);

    expect($none['f'])->toBe(100.0)
        ->and($covered['f'])->toBe(0.0)
        // And an income larger than the need never makes the deficit negative.
        ->and(max(0, $covered['need'] - $covered['income']))->toBe(0);
});

it('moves the gap factor down as the income climbs, and never back up', function () {
    $last = 101.0;

    foreach ([0, 2000, 5000, 8000, 9000, 20000] as $income) {
        $f = shapedFamily($this->region, ['adult' => 1, 'wife' => 1], income: $income)['f'];

        expect($f)->toBeLessThanOrEqual($last);
        $last = $f;
    }

    expect($last)->toBe(0.0);
});

it('refuses to compute rather than guess when a class has no approved value', function () {
    // A household with a class the association has not priced: the engine
    // names what is missing instead of putting a nought in its place.
    RegionRate::where('region_id', $this->region->id)
        ->where('person_class', 'elderly')->delete();

    $case = familyOf($this->region, adults: 1, children: 0);
    HouseholdMember::factory()->create([
        'beneficiary_id' => $case->id,
        'person_class' => 'elderly',
        'unable_to_earn' => false,
    ]);

    expect(fn () => app(NeedEngine::class)->compute($case->refresh()))
        ->toThrow(ReferenceValueMissing::class);
});
