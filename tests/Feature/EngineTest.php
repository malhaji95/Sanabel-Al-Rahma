<?php

use App\Models\Assessment;
use App\Models\Beneficiary;
use App\Models\HealthRecord;
use App\Models\HouseholdMember;
use App\Models\Housing;
use App\Models\Income;
use App\Models\RegionRate;
use App\Models\Visit;
use App\Services\AssessmentService;
use App\Services\NeedEngine;

beforeEach(function () {
    seedCore();
});

it('computes 16,000 for 2 adults and 3 children at 5000/2000', function () {
    $region = regionWithRates(adult: 5000, child: 2000);
    $family = familyOf($region, adults: 2, children: 3);

    $need = app(NeedEngine::class)->compute($family);

    expect($need['monthly_need'])->toBe(16_000);
});

it('computes 18,500 for the same family in a region at 5500/2500', function () {
    $region = regionWithRates(adult: 5500, child: 2500);
    $family = familyOf($region, adults: 2, children: 3);

    $need = app(NeedEngine::class)->compute($family);

    expect($need['monthly_need'])->toBe(18_500);
});

it('applies the rent reference only to renting households', function () {
    $region = regionWithRates(adult: 5000, child: 2000, rent: 40_000);

    $owner = familyOf($region, adults: 2, children: 3);
    $renter = familyOf($region, adults: 2, children: 3);
    $renter->housing->update(['housing_type' => 'rent', 'monthly_rent' => 35_000]);

    $ownerNeed = app(NeedEngine::class)->compute($owner);
    $renterNeed = app(NeedEngine::class)->compute($renter->refresh());

    expect($ownerNeed['monthly_need'])->toBe(16_000)
        ->and($renterNeed['monthly_need'])->toBe(56_000)
        ->and($ownerNeed['snapshot']['rent_reference']['amount'])->toBe(0)
        ->and($renterNeed['snapshot']['rent_reference']['amount'])->toBe(40_000);
});

it('counts each member exactly once, under one person class', function () {
    $region = regionWithRates(adult: 5000, child: 2000, elderly: 6000);
    $family = familyOf($region, adults: 2, children: 3);
    HouseholdMember::factory()->elderly()->create(['beneficiary_id' => $family->id]);

    $need = app(NeedEngine::class)->compute($family->refresh());
    $counts = array_map(fn ($r) => $r['count'], $need['snapshot']['rates']);

    expect(array_sum($counts))->toBe(6)
        ->and($counts)->toBe(['adult' => 2, 'child' => 3, 'elderly' => 1])
        ->and($need['monthly_need'])->toBe(22_000);
});

it('never returns a negative gap when income exceeds need', function () {
    $region = regionWithRates(adult: 5000, child: 2000);
    $family = familyOf($region, adults: 2, children: 3);
    Income::factory()->create(['beneficiary_id' => $family->id, 'amount' => 90_000, 'is_stable' => true]);

    $need = app(NeedEngine::class)->compute($family->refresh());

    expect($need['gap'])->toBe(0)
        ->and($need['f'])->toBe(0.0);
});

it('leaves an existing assessment untouched when a region rate is edited', function () {
    $region = regionWithRates(adult: 5000, child: 2000);
    $family = familyOf($region, adults: 2, children: 3);

    $assessment = app(AssessmentService::class)->create($family, status: 'approved');
    expect($assessment->monthly_need)->toBe(16_000);

    RegionRate::where('region_id', $region->id)
        ->where('person_class', 'adult')
        ->update(['amount' => 9_000, 'version' => 2]);

    $stored = Assessment::find($assessment->id);

    expect($stored->monthly_need)->toBe(16_000)
        ->and($stored->snapshot_json['rates']['adult']['amount'])->toBe(5_000);
});

it('stores the versions used in snapshot_json', function () {
    $region = regionWithRates();
    $family = familyOf($region, adults: 2, children: 3);
    $family->housing->update(['housing_type' => 'rent', 'monthly_rent' => 30_000]);

    $assessment = app(AssessmentService::class)->create($family->refresh(), status: 'approved');
    $snapshot = $assessment->snapshot_json;

    expect($snapshot['rates']['adult']['version'])->toBe(1)
        ->and($snapshot['rent_reference']['version'])->toBe(1)
        ->and($snapshot)->toHaveKeys(['weights', 'weight_versions', 'computed_at', 'region_id'])
        ->and($snapshot['weights']['F'])->toBe(0.25);
});

it('accepts no score factor as an input anywhere', function () {
    // No API accepts M, H, D, B or BaseScore directly — they are computed.
    $forbidden = ['m', 'h', 'd', 'b', 'base_score', 'M', 'H', 'D', 'B'];

    $writable = array_merge(
        (new Beneficiary)->getFillable(),
        (new HouseholdMember)->getFillable(),
        (new Housing)->getFillable(),
        (new Income)->getFillable(),
        (new HealthRecord)->getFillable(),
        (new Visit)->getFillable(),
    );

    expect(array_intersect($forbidden, $writable))->toBeEmpty();
});
