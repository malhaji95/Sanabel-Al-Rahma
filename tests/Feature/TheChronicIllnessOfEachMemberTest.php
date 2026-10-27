<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use App\Services\AssessmentService;
use Filament\Facades\Filament;

/*
 | T-174 and T-176 — «بخصوص الحالة الصحية, هل فقط تظهر في حال كان لا يعمل؟
 | لأنها كانت موجودة سابقًا» and «باقي معلومات المعالين: هل يوجد مرض مزمن؟ نعم
 | لا. إذا نعم, نوع المرض», 1 November.
 |
 | It did only appear there. T-129 attached «الوضع الصحي الخاص» and the
 | doctor's number to «غير قادر على العمل», which is where those two questions
 | belong — they explain that answer. A chronic illness is a different
 | question and is asked of everybody.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('asks a schoolchild the same question it asks a man who cannot work', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'marital_status' => 'married',
        'spouse_name_ar' => 'سعاد',
        'birth_date' => '1985-01-01',
    ]);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [memberRow([
                'name_ar' => 'بلال',
                'relation' => 'son',
                'gender' => 'male',
                'birth_year' => 2015,
                'person_class' => 'child',
                'occupation' => 'student',
                'has_chronic_illness' => true,
                'chronic_illness_ar' => 'ربو',
            ])],
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $son = Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->first();

    expect($son->has_chronic_illness)->toBeTrue()
        ->and($son->chronic_illness_ar)->toBe('ربو');
});

it('refuses «نعم» with no illness named beside it', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'marital_status' => 'married',
        'spouse_name_ar' => 'سعاد',
        'birth_date' => '1985-01-01',
    ]);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [memberRow([
                'name_ar' => 'بلال',
                'relation' => 'son',
                'gender' => 'male',
                'birth_year' => 2015,
                'person_class' => 'child',
                'has_chronic_illness' => true,
                'chronic_illness_ar' => null,
            ])],
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasFormErrors();
});

it('takes «لا» on its own', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'marital_status' => 'married',
        'spouse_name_ar' => 'سعاد',
        'birth_date' => '1985-01-01',
    ]);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [memberRow([
                'name_ar' => 'بلال',
                'relation' => 'son',
                'gender' => 'male',
                'birth_year' => 2015,
                'person_class' => 'child',
            ])],
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->first()->has_chronic_illness)
        ->toBeFalse();
});

it('moves no money: the health step is still what prices an illness', function () {
    $case = publishedCase($this->region, adults: 1, children: 1);
    $before = $case->currentAssessment()->monthly_need;

    $case->members()->first()->forceFill([
        'has_chronic_illness' => true,
        'chronic_illness_ar' => 'سكري',
    ])->save();

    expect(app(AssessmentService::class)->create($case->fresh())->monthly_need)->toBe($before);
});

it('tells no donor what anybody in the household has', function () {
    $case = publishedCase($this->region, adults: 1, children: 1);

    $case->members()->first()->forceFill([
        'has_chronic_illness' => true,
        'chronic_illness_ar' => 'قصور كلوي',
    ])->save();

    expect(json_encode(maskedOf($case->fresh()), JSON_UNESCAPED_UNICODE))
        ->not->toContain('قصور كلوي')
        ->not->toContain('chronic_illness_ar');
});
