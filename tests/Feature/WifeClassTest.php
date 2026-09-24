<?php

use App\Exceptions\ReferenceValueMissing;
use App\Models\RegionRate;
use App\Services\DependencyRules;
use App\Services\NeedEngine;

/**
 * Approved by the association on 24 Sep 2026: the wife is a money class of her
 * own, set from the kinship rather than from age, and priced from the panel
 * like adult, child and elderly.
 */
beforeEach(function () {
    seedCore();
});

it('puts an adult spouse in her own class, not with the adults', function () {
    expect(DependencyRules::personClass(30, 'spouse'))->toBe('wife')
        ->and(DependencyRules::personClass(30, 'head'))->toBe('adult')
        ->and(DependencyRules::personClass(10, 'daughter'))->toBe('child')
        // Age still decides the two objective boundaries. This precedence is
        // the one assumption in the rule and is flagged to the association.
        ->and(DependencyRules::personClass(70, 'spouse'))->toBe('elderly');
});

it('prices the wife separately in the monthly need', function () {
    $region = regionWithRates(adult: 5_000, child: 2_000, elderly: 6_000, rent: 40_000, wife: 4_000);
    $family = familyOf($region, adults: 1, children: 0);

    $withoutWife = app(NeedEngine::class)->compute($family->fresh())['monthly_need'];

    $family->members()->create([
        'name_ar' => 'زوجة 1', 'relation' => 'spouse', 'birth_year' => (int) date('Y') - 30,
        'gender' => 'female', 'person_class' => DependencyRules::personClass(30, 'spouse'),
        'dependent' => false, 'unable_to_earn' => false,
    ]);

    // The wife's own rate is added — not the adult one.
    expect(app(NeedEngine::class)->compute($family->fresh())['monthly_need'])
        ->toBe($withoutWife + 4_000);
});

it('follows the wife rate when the association changes it', function () {
    $region = regionWithRates(wife: 4_000);
    $family = familyOf($region, adults: 1, children: 0);
    $family->members()->create([
        'name_ar' => 'زوجة 1', 'relation' => 'spouse', 'birth_year' => (int) date('Y') - 30,
        'gender' => 'female', 'person_class' => 'wife', 'dependent' => false, 'unable_to_earn' => false,
    ]);

    $before = app(NeedEngine::class)->compute($family->fresh())['monthly_need'];

    RegionRate::factory()->create([
        'region_id' => $region->id,
        'person_class' => 'wife',
        'amount' => 9_000,
        'effective_from' => now()->addDay(),
        'version' => 2,
    ]);

    // Tomorrow's version, read as of tomorrow.
    expect(app(NeedEngine::class)->compute($family->fresh(), now()->addDays(2))['monthly_need'])
        ->toBe($before + 5_000);
});

it('refuses the assessment when the wife rate has not been approved', function () {
    $region = regionWithRates();
    $family = familyOf($region, adults: 1, children: 0);
    $family->members()->create([
        'name_ar' => 'زوجة 1', 'relation' => 'spouse', 'birth_year' => (int) date('Y') - 30,
        'gender' => 'female', 'person_class' => 'wife', 'dependent' => false, 'unable_to_earn' => false,
    ]);

    RegionRate::where('person_class', 'wife')->delete();

    expect(fn () => app(NeedEngine::class)->compute($family->fresh()))
        ->toThrow(ReferenceValueMissing::class, __('sanabel.person_class.wife'));
});
