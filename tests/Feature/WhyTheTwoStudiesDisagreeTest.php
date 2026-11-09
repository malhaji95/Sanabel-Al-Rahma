<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ViewBeneficiary;
use App\Filament\Resources\BeneficiaryResource\RelationManagers\AssessmentsRelationManager;
use App\Models\Assessment;
use App\Models\Beneficiary;
use App\Models\HouseholdMember;
use App\Models\RegionRate;
use App\Models\RegionRentReference;
use App\Models\ScoringWeight;
use App\Services\AssessmentService;
use Filament\Facades\Filament;

/*
 | T-281 — «مقارنة بين دراستين مع إظهار أسباب اختلاف النتائج», 9 November.
 |
 | Two studies differ for a small number of reasons, and the association asked
 | to be told which. Each test below makes exactly one of them happen and
 | checks the comparison names that one and not another.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** The current study, and the one before it. */
function twoStudies(Beneficiary $case): array
{
    $case->refresh();
    $newer = $case->currentAssessment();
    $older = Assessment::where('beneficiary_id', $case->id)
        ->where('id', '<', $newer->id)->orderByDesc('id')->firstOrFail();

    return [$older, $newer];
}

it('names a reference value that moved, and nothing else', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);

    RegionRate::create([
        'region_id' => $this->region->id,
        'person_class' => 'child',
        'amount' => 9000,
        'currency' => config('sanabel.currency'),
        'effective_from' => now()->subDay(),
        'version' => 7,
    ]);

    app(AssessmentService::class)->create($case->refresh(), status: 'approved');
    [$older, $newer] = twoStudies($case);

    $c = $newer->comparedWith($older);
    $child = collect($c['need'])->firstWhere('key', 'child');

    expect($child['reasons'])->toBe(['reference_changed'])
        ->and($child['older_unit'])->toBe(2000)
        ->and($child['newer_unit'])->toBe(9000)
        ->and($child['newer_version'])->toBe(7)
        ->and($child['delta'])->toBe(21000)
        // The money moved; no weight and no factor did.
        ->and($c['income'])->toBe([]);
});

it('names a household that grew, and says it was the count not the value', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);

    // Renting, and the «7 or more» band costs more than «4 to 6», so the
    // household crossing into it has somewhere to move to.
    $case->housing()->update(['housing_type' => 'rent', 'monthly_rent' => 50000]);

    RegionRentReference::create([
        'region_id' => $this->region->id,
        'family_size_band' => '7+',
        'reference_rent' => 80000,
        'currency' => config('sanabel.currency'),
        'effective_from' => now()->subYear(),
        'version' => 2,
    ]);

    app(AssessmentService::class)->create($case->refresh(), status: 'approved');

    HouseholdMember::factory()->count(2)->child()->create(['beneficiary_id' => $case->id]);
    app(AssessmentService::class)->create($case->refresh(), status: 'approved');

    [$older, $newer] = twoStudies($case);
    $c = $newer->comparedWith($older);

    $child = collect($c['need'])->firstWhere('key', 'child');

    expect($child['reasons'])->toBe(['count_changed'])
        ->and($child['older_count'])->toBe(3)
        ->and($child['newer_count'])->toBe(5)
        ->and($child['older_unit'])->toBe($child['newer_unit']);

    // Seven people now, so the household crossed a rent band. That is said
    // as its own reason: the association edited nothing, the family grew.
    $rent = collect($c['need'])->firstWhere('kind', 'rent');

    expect($rent['reasons'])->toContain('band_changed')
        ->and($rent['older_band'])->toBe('4-6')
        ->and($rent['newer_band'])->toBe('7+')
        ->and($rent['delta'])->toBe(40000);
});

it('names a line that appeared and a line that went away', function () {
    $case = publishedCase($this->region, adults: 2, children: 0);

    HouseholdMember::factory()->count(2)->child()->create(['beneficiary_id' => $case->id]);
    app(AssessmentService::class)->create($case->refresh(), status: 'approved');

    [$older, $newer] = twoStudies($case);
    $c = $newer->comparedWith($older);

    $child = collect($c['need'])->firstWhere('key', 'child');
    $adjustment = collect($c['need'])->firstWhere('key', 'children_present');

    expect($child['reasons'])->toBe(['added'])
        ->and($adjustment['reasons'])->toBe(['added']);
});

it('names a weight that was edited, with the money standing still', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);

    ScoringWeight::create([
        'factor_key' => 'F',
        'weight' => 0.60,
        'effective_from' => now()->subDay(),
        'version' => 4,
    ]);

    app(AssessmentService::class)->create($case->refresh(), status: 'approved');
    [$older, $newer] = twoStudies($case);

    $c = $newer->comparedWith($older);
    $f = collect($c['factors'])->firstWhere('key', 'F');

    expect($f['reasons'])->toBe(['weight_changed'])
        ->and($f['older_weight'])->toBe(0.25)
        ->and($f['newer_weight'])->toBe(0.6)
        // And not one figure of money moved.
        ->and($c['need'])->toBe([])
        ->and($c['income'])->toBe([])
        ->and(collect($c['totals'])->firstWhere('key', 'monthly_need')['delta'])->toBe(0)
        ->and(collect($c['totals'])->firstWhere('key', 'base_score')['delta'])->toBeGreaterThan(0);
});

it('reads the same whichever order the two are handed in', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);
    app(AssessmentService::class)->create($case->refresh(), status: 'approved');

    [$older, $newer] = twoStudies($case);

    expect($newer->comparedWith($older))->toEqual($older->comparedWith($newer));
});

it('compares nothing rather than inventing a comparison', function () {
    $empty = new Assessment(['snapshot_json' => []]);

    expect($empty->comparedWith($empty))->toBe([]);
});

it('offers the comparison on the screen the association opens', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);

    RegionRate::create([
        'region_id' => $this->region->id,
        'person_class' => 'child',
        'amount' => 9000,
        'currency' => config('sanabel.currency'),
        'effective_from' => now()->subDay(),
        'version' => 7,
    ]);

    app(AssessmentService::class)->create($case->refresh(), status: 'approved');
    [$older, $newer] = twoStudies($case);

    passTwoFactor();
    $this->actingAs(userWithRole('executive_director'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire\Livewire::test(AssessmentsRelationManager::class, [
        'ownerRecord' => $case->refresh(),
        'pageClass' => ViewBeneficiary::class,
    ])
        ->mountTableAction('compare_previous', $newer->getKey())
        ->assertSee(__('sanabel.study_comparison.need_title'))
        ->assertSee(__('sanabel.study_comparison.reasons.reference_changed'))
        ->assertSee(__('sanabel.study_comparison.frozen_note'));

    // And the oldest study has nothing before it, so it is not offered.
    Livewire\Livewire::test(AssessmentsRelationManager::class, [
        'ownerRecord' => $case->refresh(),
        'pageClass' => ViewBeneficiary::class,
    ])->assertTableActionHidden('compare_previous', $older->getKey());
});

it('compares any two the reader ticks, and asks for exactly two', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);
    app(AssessmentService::class)->create($case->refresh(), status: 'approved');
    app(AssessmentService::class)->create($case->refresh(), status: 'approved');

    $all = Assessment::where('beneficiary_id', $case->id)->orderBy('id')->pluck('id')->all();

    passTwoFactor();
    $this->actingAs(userWithRole('executive_director'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The first and the third, which no row action would pair.
    Livewire\Livewire::test(AssessmentsRelationManager::class, [
        'ownerRecord' => $case->refresh(),
        'pageClass' => ViewBeneficiary::class,
    ])
        ->mountTableBulkAction('compare', [$all[0], $all[2]])
        ->assertSee(__('sanabel.study_comparison.need_title'))
        ->assertDontSee(__('sanabel.study_comparison.pick_two'));

    // And three is not a comparison.
    Livewire\Livewire::test(AssessmentsRelationManager::class, [
        'ownerRecord' => $case->refresh(),
        'pageClass' => ViewBeneficiary::class,
    ])
        ->mountTableBulkAction('compare', $all)
        ->assertSee(__('sanabel.study_comparison.pick_two'));
});
