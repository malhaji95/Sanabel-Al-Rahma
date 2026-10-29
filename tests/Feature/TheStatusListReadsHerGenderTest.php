<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use Filament\Facades\Filament;

/*
 | T-185 — «أنثى لازم يكون الخيارات طالبة موظفة عاملة بالتاء التأنيث»,
 | 29 October, with a photograph of a widow named «مها» being offered «طالب,
 | موظف, عامل, باحث عن عمل, متقاعد».
 |
 | The same pairing the marital states have had since T-121: two lists, one
 | set of keys, and only the word on the screen changing.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('offers a woman the list written for her', function () {
    expect(BeneficiaryResource::occupationOptions('female'))
        ->toMatchArray([
            'student' => 'طالبة',
            'employee' => 'موظفة',
            'worker' => 'عاملة',
            'jobseeker' => 'باحثة عن عمل',
            'retired' => 'متقاعدة',
            'unable' => 'غير قادرة على العمل',
        ]);
});

it('leaves a man’s list as it was', function () {
    expect(BeneficiaryResource::occupationOptions('male'))
        ->toMatchArray([
            'student' => 'طالب',
            'employee' => 'موظف',
            'worker' => 'عامل',
            'jobseeker' => 'باحث عن عمل',
            'retired' => 'متقاعد',
            'unable' => 'غير قادر على العمل',
        ])
        // A row whose gender nobody has answered yet reads the list every row
        // read before this task.
        ->and(BeneficiaryResource::occupationOptions(null))
        ->toBe(BeneficiaryResource::occupationOptions('male'));
});

it('keeps the two lists answering the same keys', function () {
    // The whole of the safety here: what is saved is the key, so a file filled
    // from one list and read through the other never loses its answer.
    expect(array_keys(__('sanabel.occupation_f')))
        ->toBe(array_keys(__('sanabel.occupation')));
});

it('saves the same word for a daughter as for a son', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'marital_status' => 'married',
        'spouse_name_ar' => 'سعاد',
        'birth_date' => '1985-01-01',
    ]);

    Livewire\Livewire::test(EditBeneficiary::class, [
        'record' => $case->getRouteKey(),
    ])
        ->fillForm([
            'members' => [memberRow([
                'name_ar' => 'مها',
                'relation' => 'daughter',
                'gender' => 'female',
                'birth_year' => 2005,
                'person_class' => 'adult',
                'occupation' => 'student',
            ])],
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->first()->occupation)
        ->toBe('student');
});

it('writes «طالبة» on the donor’s page for a daughter and «طالب» for a son', function () {
    $case = publishedCase($this->region, adults: 1, children: 0);

    $case->members()->create([
        'name_ar' => 'مها', 'relation' => 'daughter', 'gender' => 'female',
        'birth_year' => 2005, 'person_class' => 'adult', 'occupation' => 'student',
        'dependent' => true,
    ]);
    $case->members()->create([
        'name_ar' => 'بلال', 'relation' => 'son', 'gender' => 'male',
        'birth_year' => 2007, 'person_class' => 'child', 'occupation' => 'student',
        'dependent' => true,
    ]);

    $labels = collect(maskedOf($case->fresh())['dependents'] ?? [])
        ->pluck('occupation_label')
        ->filter()
        ->values();

    expect($labels)->toContain('طالبة')
        ->toContain('طالب');
});
