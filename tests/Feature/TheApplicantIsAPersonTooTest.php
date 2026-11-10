<?php

use App\Exceptions\HouseholdIncomplete;
use App\Models\HouseholdMember;
use App\Models\RegionRentReference;
use App\Services\NeedEngine;

/*
 | T-284 — «لاحظنا في إحدى التجارب أن مقدم الطلب، وهي أم أرملة، لم يظهر ضمن
 | حساب الاحتياج», 10 November.
 |
 | They were right, and it was the worst kind of wrong: a number that looked
 | right. The engine priced the rows of `household_members`, and the
 | applicant is not one of those rows — they are the file. So a widowed
 | mother of two was costed as two children, and nothing on any screen said
 | a person was missing.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('counts the applicant, who is not a row in the members table', function () {
    // A widowed mother and two children, the way the researcher entered it:
    // herself on the file, the two children as members.
    $case = familyOf($this->region, adults: 0, children: 2, attributes: [
        'marital_status' => 'widowed',
        'gender' => 'female',
        'birth_date' => now()->subYears(41)->toDateString(),
    ]);

    $out = app(NeedEngine::class)->compute($case->refresh());
    $counts = array_map(fn ($r) => $r['count'], $out['snapshot']['rates']);

    expect($counts)->toBe(['adult' => 1, 'child' => 2])
        // Two children at 2,000 and the mother at the adult reference 5,000.
        // Before this she was costed at 4,000: her own keep was missing.
        // (The fixture region carries no adjustment amounts, so none apply.)
        ->and($out['monthly_need'])->toBe(2 * 2000 + 5000);
});

it('counts the applicant once even when they are also written as a member row', function () {
    $case = familyOf($this->region, adults: 1, children: 2);

    // The same person, entered a second time under «رب الأسرة».
    HouseholdMember::factory()->create([
        'beneficiary_id' => $case->id,
        'relation' => 'head',
        'person_class' => 'adult',
    ]);

    $counts = array_map(
        fn ($r) => $r['count'],
        app(NeedEngine::class)->compute($case->refresh())['snapshot']['rates'],
    );

    expect($counts['adult'])->toBe(1);
});

it('refuses to compute rather than guess the applicant’s class', function () {
    $case = familyOf($this->region, adults: 1, children: 2, attributes: ['birth_date' => null]);

    expect(fn () => app(NeedEngine::class)->compute($case->refresh()))
        ->toThrow(HouseholdIncomplete::class);
});

it('prices the applicant by their own age, like anybody else', function () {
    $young = familyOf($this->region, adults: 1, children: 0, attributes: [
        'birth_date' => now()->subYears(40)->toDateString(),
    ]);

    $old = familyOf($this->region, adults: 1, children: 0, attributes: [
        'birth_date' => now()->subYears(70)->toDateString(),
    ]);

    $needOf = fn ($case) => app(NeedEngine::class)->compute($case->refresh())['monthly_need'];

    // The adult reference is 5,000 and the elderly one 6,000, and the elderly
    // household carries its own adjustment too.
    expect($needOf($young))->toBe(5000)
        ->and($needOf($old))->toBeGreaterThan($needOf($young));
});

it('keeps the applicant on the breakdown the association reads', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);

    $rows = collect($case->currentAssessment()->needBreakdown()['need']);
    $adults = $rows->firstWhere('key', 'adult');

    // Two adults live here: the applicant and one member row.
    expect($adults['count'])->toBe(2);
});

it('puts the household in the rent band its real size falls in', function () {
    // Six people: the applicant and five member rows. The band is «4 to 6»;
    // before this the engine saw five and agreed by luck, and a seventh
    // person would have left it in the wrong band.
    $case = familyOf($this->region, adults: 2, children: 4);
    $case->housing()->update(['housing_type' => 'rent', 'monthly_rent' => 50000]);

    RegionRentReference::create([
        'region_id' => $this->region->id,
        'family_size_band' => '7+',
        'reference_rent' => 90000,
        'currency' => config('sanabel.currency'),
        'effective_from' => now()->subYear(),
        'version' => 2,
    ]);

    expect($case->refresh()->householdSize())->toBe(6)
        ->and(app(NeedEngine::class)->compute($case->refresh())['snapshot']['rent_reference']['band'])
        ->toBe('4-6');

    // One more child and the household crosses the band.
    HouseholdMember::factory()->child()->create(['beneficiary_id' => $case->id]);

    expect($case->refresh()->householdSize())->toBe(7)
        ->and(app(NeedEngine::class)->compute($case->refresh())['snapshot']['rent_reference']['band'])
        ->toBe('7+');
});

it('counts the household the same way wherever the question is asked', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);

    HouseholdMember::factory()->create([
        'beneficiary_id' => $case->id,
        'relation' => 'head',
        'person_class' => 'adult',
    ]);

    // The duplicate row is nobody, in the need and in the headcount alike.
    $counts = array_map(
        fn ($r) => $r['count'],
        app(NeedEngine::class)->compute($case->refresh())['snapshot']['rates'],
    );

    expect($case->refresh()->householdSize())->toBe(5)
        ->and(array_sum($counts))->toBe(5);
});
