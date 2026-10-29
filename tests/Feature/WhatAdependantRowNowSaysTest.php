<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\HealthRecord;
use App\Models\HouseholdMember;
use App\Models\Setting;

/*
 | T-162 — «ونحتاج اضافة معلومات المعالين كاملة مثل ما ذكرناها من قبل»,
 | 30 October.
 |
 | Four more answers the survey already holds about each dependant, every one
 | a band or a label out of a list. Still no name, no birth year, no age in
 | years, and not one sentence anybody wrote — which is what lets a roster of
 | children be published at all.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** The first dependant's row, as a donor receives it. */
function firstDependent($case): array
{
    return collect(maskedOf($case)['dependents'])->first() ?? [];
}

it('says which age class, where at school, and whether they memorise the Qur’an', function () {
    $case = publishedCase($this->region, adults: 1, children: 0);

    HouseholdMember::factory()->child()->create([
        'beneficiary_id' => $case->id,
        'name_ar' => 'اسم لا يُنشر',
        'birth_year' => (int) date('Y') - 10,
        'person_class' => 'child',
        'dependent' => true,
        'occupation' => 'student',
        'school_stage' => 'primary',
        'is_quran_memorizer' => true,
        // T-185 — the status list follows the member's own gender now, so the
        // row has to say which it is for the label to be checkable at all.
        'gender' => 'male',
    ]);

    $row = firstDependent($case);

    expect($row['age_band_label'])->toBe(__('sanabel.masked.age_band.child'))
        ->and($row['school_stage_label'])->toBe(__('sanabel.school_stage.primary'))
        ->and($row['is_quran_memorizer'])->toBeTrue()
        ->and($row['occupation_label'])->toBe(__('sanabel.occupation.student'));
});

it('says how heavy the illness is, beside what it is called', function () {
    $case = publishedCase($this->region, adults: 1, children: 0);

    $member = HouseholdMember::factory()->child()->create([
        'beneficiary_id' => $case->id,
        'birth_year' => (int) date('Y') - 10,
        'person_class' => 'child',
        'dependent' => true,
    ]);

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'member_id' => $member->id,
        'severity_band' => 75,
    ]);

    expect(firstDependent($case)['severity_label'])->toBe(__('sanabel.bands.severity.75'));

    // And it goes quiet with the switch that names the illness.
    Setting::put('show_health_condition', false);

    expect(firstDependent($case)['severity_label'])->toBeNull();
});

it('adds nothing that could name the child', function () {
    $case = publishedCase($this->region, adults: 1, children: 0);

    HouseholdMember::factory()->child()->create([
        'beneficiary_id' => $case->id,
        'name_ar' => 'اسم لا يُنشر',
        'birth_year' => 2016,
        'person_class' => 'child',
        'dependent' => true,
        'notes_ar' => 'ملاحظة الباحث',
    ]);

    $row = firstDependent($case);

    expect(array_diff(array_keys($row), MaskedCaseResource::DEPENDENT_KEYS))->toBeEmpty()
        ->and(json_encode($row, JSON_UNESCAPED_UNICODE))
        ->not->toContain('اسم لا يُنشر')
        ->not->toContain('ملاحظة الباحث')
        ->not->toContain('2016');
});

it('draws the new badges on the family’s own page', function () {
    $case = publishedCase($this->region, adults: 1, children: 0);

    HouseholdMember::factory()->child()->create([
        'beneficiary_id' => $case->id,
        'birth_year' => (int) date('Y') - 10,
        'person_class' => 'child',
        'dependent' => true,
        'occupation' => 'student',
        'school_stage' => 'primary',
        'is_quran_memorizer' => true,
    ]);

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.school_stage.primary'))
        ->assertSee(__('sanabel.masked.quran_memorizer'))
        ->assertSee(__('sanabel.masked.age_band.child'));
});
