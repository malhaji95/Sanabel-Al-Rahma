<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;

use function Pest\Livewire\livewire;

/*
 | T-54 — the social survey on the screen, not only in the table.
 |
 | The association confirmed on 11 October that the internal file must carry
 | everything their external survey asks, so these go through the panel the
 | way a delegate does: fill the form, save, and read the row back.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    /*
     | T-105 — the editor here used to be the system administrator, on the
     | convenience that it held every key. It no longer holds the key to a
     | family file: on 16 October the association confined that account to
     | preparing the system. The screens under test are the case officer's,
     | and that is who opens them now.
     */
    $this->admin = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->admin);
});

it('saves the survey questions about the household through the form', function () {
    $case = familyOf($this->region, adults: 1, children: 1);

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'gender' => 'male',
            'birth_date' => '1984-03-02',
            'town_ar' => 'حي المنشية',
            'latitude' => 32.6189,
            'longitude' => 36.1021,
            // T-127 — «هل يعمل» is three answers, and `works` follows it.
            'work_status' => 'yes',
            'workplace_ar' => 'ورشة حدادة',
            'has_quran_memorizers' => true,
            'quran_memorizer_count' => 2,
            'livelihood_proposal_ar' => 'بسطة خضار',
            'proposal_skills_ar' => 'خبرة عشر سنوات بالبيع',
            'proposal_cost' => 1_500_000,
            'surveyed_by_ar' => 'أبو محمد',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $case = Beneficiary::withoutGlobalScopes()->find($case->getKey());

    expect($case->gender)->toBe('male')
        ->and($case->birth_date->toDateString())->toBe('1984-03-02')
        ->and($case->town_ar)->toBe('حي المنشية')
        ->and((float) $case->latitude)->toBe(32.6189)
        ->and($case->work_status)->toBe('yes')
        ->and($case->works)->toBeTrue()
        ->and($case->workplace_ar)->toBe('ورشة حدادة')
        ->and($case->quran_memorizer_count)->toBe(2)
        ->and($case->livelihood_proposal_ar)->toBe('بسطة خضار')
        ->and($case->proposal_cost)->toBe(1_500_000)
        ->and($case->surveyed_by_ar)->toBe('أبو محمد');
});

it('opens the form with the survey answers it already holds', function () {
    $case = familyOf($this->region, adults: 1, children: 1);
    $case->update(['town_ar' => 'حي المنشية', 'surveyed_by_ar' => 'أبو محمد', 'works' => true]);

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertFormSet([
            'town_ar' => 'حي المنشية',
            'surveyed_by_ar' => 'أبو محمد',
            // T-127 — a row that only holds the old boolean is read onto the
            // three-valued question rather than opening it empty.
            'work_status' => 'yes',
        ]);
});

it('saves the dependant columns the second survey sheet carries', function () {
    $case = familyOf($this->region, adults: 1, children: 1);
    $member = $case->members()->first();

    $page = livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()]);

    // A repeater keys its rows by a generated key, not by id, so a row is
    // edited in place by merging into the state the page arrived with. Passing
    // a fresh list would make Filament add a row rather than change this one.
    //
    // birth_year is left alone here. On the real screen, typing a birth date
    // fills the year from it and re-derives the person class; that runs on the
    // field's own update event, which merging state does not raise.
    $rows = $page->get('data')['members'];
    $key = array_key_first($rows);
    $rows[$key] = array_merge($rows[$key], [
        'national_id_encrypted' => '09876543210',
        'birth_date' => '2012-06-01',
        'marital_status' => 'single',
        'occupation' => 'student',
        'is_student' => true,
        'school_grade_ar' => 'الصف السادس',
        'works' => false,
        'is_quran_memorizer' => true,
        'certificate_ar' => 'ابتدائية',
        'skills_ar' => 'حفظ ثلاثة أجزاء',
    ]);

    $page->fillForm(['members' => $rows])->call('save')->assertHasNoFormErrors();

    $member->refresh();

    expect($member->national_id_encrypted)->toBe('09876543210')
        ->and($member->birth_date->toDateString())->toBe('2012-06-01')
        ->and($member->school_grade_ar)->toBe('الصف السادس')
        ->and($member->occupation)->toBe('student')
        ->and($member->is_quran_memorizer)->toBeTrue()
        ->and($member->certificate_ar)->toBe('ابتدائية')
        ->and($member->skills_ar)->toBe('حفظ ثلاثة أجزاء');
});

it('opens a dependant row with the identity number it already has', function () {
    $case = familyOf($this->region, adults: 1, children: 1);
    $member = $case->members()->first();
    $member->update(['national_id_encrypted' => '09876543210']);

    $rows = livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->get('data')['members'];

    // A repeater's row order is the relationship's, which is not the order the
    // family was seeded in, so the row is found by the member it belongs to.
    $row = collect($rows)->firstWhere('name_ar', $member->name_ar);

    expect($row['national_id_encrypted'])->toBe('09876543210');
});

it('keeps a dependant identity number when an unrelated field is saved', function () {
    $case = familyOf($this->region, adults: 1, children: 1);
    $member = $case->members()->first();
    $member->update(['national_id_encrypted' => '09876543210']);

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['family_name' => 'اسم معدَّل'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->fresh()->national_id_encrypted)->toBe('09876543210');
});

it('keeps the landlord name and phone when an unrelated field is saved', function () {
    $case = familyOf($this->region, adults: 1, children: 1);
    $case->housing()->first()->update([
        'landlord_name_ar' => 'أبو فؤاد',
        'landlord_phone_encrypted' => '0933444555',
    ]);

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['family_name' => 'اسم معدَّل'])
        ->call('save')
        ->assertHasNoFormErrors();

    $housing = $case->housing()->first();

    expect($housing->landlord_name_ar)->toBe('أبو فؤاد')
        ->and($housing->landlord_phone_encrypted)->toBe('0933444555');
});

it('saves the housing columns the survey asks about', function () {
    $case = familyOf($this->region, adults: 1, children: 1);
    $housing = $case->housing()->first();

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'housing' => [
                'housing_type' => 'rent',
                'type_other_ar' => 'غرفة على السطح',
                'monthly_rent' => 300_000,
                'rent_period' => 'monthly',
                'habitable_rooms' => 2,
                'bathrooms' => 1,
                'has_kitchen' => true,
                'safety_band' => $housing?->safety_band ?? 0,
                'services_band' => $housing?->services_band ?? 0,
                'eviction_band' => $housing?->eviction_band ?? 0,
            ],
            // The survey asks it under housing; the column is on the file.
            'furnishing_ar' => 'فرش بسيط',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $housing = $case->housing()->first();

    expect($housing->type_other_ar)->toBe('غرفة على السطح')
        ->and($housing->rent_period)->toBe('monthly')
        ->and($housing->bathrooms)->toBe(1)
        ->and($housing->has_kitchen)->toBeTrue()
        ->and($case->fresh()->furnishing_ar)->toBe('فرش بسيط');
});

it('saves the treatment columns on a health record', function () {
    $case = familyOf($this->region, adults: 1, children: 1);
    $case->healthRecords()->create([
        'severity_band' => 25,
        'economic_impact_band' => 0,
        'care_burden_band' => 0,
        'monthly_medical_cost' => 0,
    ]);

    $page = livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()]);
    $rows = $page->get('data')['healthRecords'];
    $key = array_key_first($rows);
    $rows[$key] = array_merge($rows[$key], [
        'ongoing_treatment' => true,
        'treatment_place_ar' => 'المشفى الوطني',
        'medicines_ar' => 'أنسولين',
        'doctor_name_ar' => 'د. سامر',
        'doctor_phone_encrypted' => '0944555666',
    ]);

    $page->fillForm(['healthRecords' => $rows])->call('save')->assertHasNoFormErrors();

    $record = $case->healthRecords()->first();

    expect($record->ongoing_treatment)->toBeTrue()
        ->and($record->treatment_place_ar)->toBe('المشفى الوطني')
        ->and($record->medicines_ar)->toBe('أنسولين')
        ->and($record->doctor_name_ar)->toBe('د. سامر')
        ->and($record->doctor_phone_encrypted)->toBe('0944555666');
});

it('opens a health record with the doctor phone and description it already has', function () {
    $case = familyOf($this->region, adults: 1, children: 1);
    $case->healthRecords()->create([
        'severity_band' => 25,
        'economic_impact_band' => 0,
        'care_burden_band' => 0,
        'monthly_medical_cost' => 0,
        'description_ar' => 'سكري من النوع الأول',
        'doctor_phone_encrypted' => '0944555666',
    ]);

    $rows = livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->get('data')['healthRecords'];

    $row = array_values($rows)[0];

    expect($rows)->toHaveCount(1)
        ->and($row['description_ar'])->toBe('سكري من النوع الأول')
        ->and($row['doctor_phone_encrypted'])->toBe('0944555666');
});
