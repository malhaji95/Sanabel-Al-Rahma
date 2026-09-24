<?php

use App\Exceptions\ReferenceValueMissing;
use App\Models\RegionRate;
use App\Services\AssessmentService;
use App\Services\NeedEngine;

/**
 * The association's decision sheet of 24 Sep 2026, three times over: a money
 * value it has not approved stays empty, and zero is never assumed for it.
 * Before this, a missing rate resolved to zero and the family's need was
 * computed as if that class of person cost nothing.
 */
beforeEach(function () {
    seedCore();
});

it('refuses to assess a family whose region has no approved rate', function () {
    $region = regionWithRates();
    $family = familyOf($region);

    // The association has not approved what a child costs in this region.
    RegionRate::withoutGlobalScopes()->where('person_class', 'child')->delete();

    expect(fn () => app(NeedEngine::class)->compute($family->fresh()))
        ->toThrow(ReferenceValueMissing::class);
});

it('names the region and the class, so the gap can be filled', function () {
    $region = regionWithRates();
    $family = familyOf($region);

    RegionRate::withoutGlobalScopes()->where('person_class', 'elderly')->delete();
    RegionRate::withoutGlobalScopes()->where('person_class', 'child')->delete();
    $family->members()->create([
        'name_ar' => 'جد 1', 'relation' => 'والد', 'birth_year' => (int) date('Y') - 70,
        'gender' => 'male', 'person_class' => 'elderly', 'dependent' => true, 'unable_to_earn' => false,
    ]);

    try {
        app(NeedEngine::class)->compute($family->fresh());
        expect(false)->toBeTrue('the assessment should have been refused');
    } catch (ReferenceValueMissing $e) {
        expect($e->regionName)->toBe($region->name_ar)
            ->and($e->missing)->toContain(__('sanabel.person_class.child'))
            ->and($e->missing)->toContain(__('sanabel.person_class.elderly'))
            ->and($e->getMessage())->toContain($region->name_ar);
    }
});

it('treats an empty amount the same as no row at all', function () {
    $region = regionWithRates();
    $family = familyOf($region);

    // A row exists but carries no approved figure — which is how the
    // association's own template arrives.
    RegionRate::withoutGlobalScopes()->where('person_class', 'child')->update(['amount' => null]);

    expect(fn () => app(NeedEngine::class)->compute($family->fresh()))
        ->toThrow(ReferenceValueMissing::class);
});

it('blocks the assessment the panel would create, not just the engine', function () {
    $region = regionWithRates();
    $family = familyOf($region);

    RegionRate::withoutGlobalScopes()->where('person_class', 'adult')->delete();

    expect(fn () => app(AssessmentService::class)->create($family->fresh(), status: 'approved'))
        ->toThrow(ReferenceValueMissing::class);
});

it('still assesses a family once every value it needs is approved', function () {
    $region = regionWithRates();
    $family = familyOf($region);

    $need = app(NeedEngine::class)->compute($family->fresh());

    expect($need['monthly_need'])->toBeGreaterThan(0);
});

it('stops using a reference value once it is deleted from the panel', function () {
    // The lookups bypassed every global scope to escape the region one, which
    // took the soft-delete scope with it: a rate removed from the panel went
    // on being used in every computation afterwards.
    $region = regionWithRates();
    $family = familyOf($region);

    expect(app(NeedEngine::class)->compute($family->fresh())['monthly_need'])->toBeGreaterThan(0);

    RegionRate::where('person_class', 'adult')->delete();

    expect(fn () => app(NeedEngine::class)->compute($family->fresh()))
        ->toThrow(ReferenceValueMissing::class);
});
