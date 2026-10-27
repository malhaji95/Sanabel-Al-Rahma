<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Services\AssessmentService;
use Filament\Facades\Filament;

/*
 | T-172 — «ضمن ملفات المستفيد لما يتم اختيار لأنثى الحالة الاجتماعية متزوجة
 | ويظهر تحت بيانات الزوج, لازم يتم أيضًا ظهور حقول إدخال بيانات عمل الزوج إذا
 | كان يعمل», 1 November.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('keeps the husband’s work beside his name', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'gender' => 'female',
        'marital_status' => 'married',
    ]);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'spouse_name_ar' => 'خالد',
            'spouse_work_status' => 'yes',
            'spouse_profession_ar' => 'نجّار',
            'spouse_workplace_ar' => 'ورشة في درعا',
            'spouse_work_income' => 350_000,
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = Beneficiary::withoutGlobalScopes()->find($case->getKey());

    expect($saved->spouse_work_status)->toBe('yes')
        ->and($saved->spouse_profession_ar)->toBe('نجّار')
        ->and($saved->spouse_workplace_ar)->toBe('ورشة في درعا')
        ->and($saved->spouse_work_income)->toBe(350_000);
});

it('asks a woman about her husband and a man about his wife', function () {
    expect(BeneficiaryResource::spouseWorkLabel('female'))->toBe('هل يعمل الزوج؟')
        ->and(BeneficiaryResource::spouseWorkLabel('male'))->toBe('هل تعمل الزوجة؟')
        // A file whose head has not said is not guessed at.
        ->and(BeneficiaryResource::spouseWorkLabel(null))->toBe('هل يعمل الزوج أو الزوجة؟');
});

it('keeps the money an integer in the smallest unit', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'gender' => 'female',
        'marital_status' => 'married',
        'spouse_name_ar' => 'خالد',
        'spouse_work_income' => 350_000,
    ]);

    expect($case->fresh()->spouse_work_income)->toBeInt();
});

it('lets a husband who finds work later be corrected without reopening the file', function () {
    // The head's own four are correctable (T-127); the spouse's four are the
    // same question asked of the other adult in the house.
    expect(ChangeRequest::CHANGEABLE_FIELDS)
        ->toContain('spouse_work_status')
        ->toContain('spouse_profession_ar')
        ->toContain('spouse_workplace_ar')
        ->toContain('spouse_work_income');
});

it('leaves the monthly need where it was, because the income step is what prices it', function () {
    $case = publishedCase($this->region, attributes: [
        'gender' => 'female',
        'marital_status' => 'married',
        'spouse_name_ar' => 'خالد',
    ]);

    $before = $case->currentAssessment()->monthly_need;

    $case->forceFill([
        'spouse_work_status' => 'yes',
        'spouse_work_income' => 900_000,
    ])->save();

    expect(app(AssessmentService::class)->create($case->fresh())->monthly_need)
        ->toBe($before);
});
