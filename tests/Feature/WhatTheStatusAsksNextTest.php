<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use App\Models\HouseholdMember;
use App\Services\AnonymiseService;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;

/*
 | T-129 — «الربط بين صلة القرابة والجنس … بخصوص الصفة طالب يطلع مباشر خيار
 | بأي مرحلة وأي صف … في حال اختيار موظف يتم اظهار مكان العمل والراتب الشهري,
 | نفس الشي اذا كان عامل, الباحث عن عمل يتم ذكر العمل الذي يستطيع العمل به,
 | المتقاعد يتم ذكر الراتب التقاعدي, ازالة صفة اليتيم من هذا المكان, وعند
 | اختيار غير قادر على العمل يظهر الوضع الصحي الخاص مع رفع ملف او وصف مع رقم
 | الطبيب, وعند معال او عاجز عن العمل يجب ان يكون قابلة للتعديل», 27 October.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/**
 * One family's edit screen, open in front of an account that may write, with
 * the repeater key of its single member row and that member's id.
 *
 * @return array{0: Testable, 1: string, 2: int}
 */
function oneMember(Beneficiary $case): array
{
    $page = Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()]);

    return [$page, array_key_first($page->get('data.members')), $case->members()->first()->id];
}

it('answers the gender from the kinship, and says nothing where kinship says nothing', function () {
    expect(BeneficiaryResource::genderOfRelation('daughter'))->toBe('female')
        ->and(BeneficiaryResource::genderOfRelation('mother'))->toBe('female')
        ->and(BeneficiaryResource::genderOfRelation('sister'))->toBe('female')
        ->and(BeneficiaryResource::genderOfRelation('spouse'))->toBe('female')
        ->and(BeneficiaryResource::genderOfRelation('son'))->toBe('male')
        ->and(BeneficiaryResource::genderOfRelation('father'))->toBe('male')
        ->and(BeneficiaryResource::genderOfRelation('brother'))->toBe('male')
        // A head of household and «أخرى» are either.
        ->and(BeneficiaryResource::genderOfRelation('head'))->toBeNull()
        ->and(BeneficiaryResource::genderOfRelation('other'))->toBeNull();
});

it('sets the gender on the row when a daughter is chosen', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    [$page, $key] = oneMember($case);

    $page->set("data.members.{$key}.gender", 'male')
        ->set("data.members.{$key}.relation", 'daughter');

    expect($page->get("data.members.{$key}.gender"))->toBe('female');
});

it('offers no «يتيم» among the statuses, and keeps the flag that holds it', function () {
    expect(__('sanabel.occupation'))->not->toHaveKey('orphan')
        ->and(HouseholdMember::OCCUPATIONS)->not->toContain('orphan')
        // The question itself is still asked, as its own answer.
        ->and(__('sanabel.household_member.is_orphan'))->toBe('يتيم');
});

it('asks a student for the stage and the grade, and nothing about a workplace', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    [$page, $key] = oneMember($case);

    $page->set("data.members.{$key}.occupation", 'student');

    $page->assertFormFieldIsVisible("members.{$key}.school_stage")
        ->assertFormFieldIsVisible("members.{$key}.school_grade_ar")
        ->assertFormFieldIsHidden("members.{$key}.workplace_ar")
        ->assertFormFieldIsHidden("members.{$key}.pension_income");
});

it('asks an employee and a worker the same two questions', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    foreach (['employee', 'worker'] as $status) {
        [$page, $key] = oneMember($case);

        $page->set("data.members.{$key}.occupation", $status)
            ->assertFormFieldIsVisible("members.{$key}.workplace_ar")
            ->assertFormFieldIsVisible("members.{$key}.work_income")
            ->assertFormFieldIsHidden("members.{$key}.school_stage");
    }
});

it('asks a jobseeker what they can do, and a pensioner what they draw', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    [$page, $key] = oneMember($case);

    $page->set("data.members.{$key}.occupation", 'jobseeker')
        ->assertFormFieldIsVisible("members.{$key}.sought_trade_ar")
        ->assertFormFieldIsVisible("members.{$key}.has_certificate")
        ->assertFormFieldIsVisible("members.{$key}.experience_years")
        ->assertFormFieldIsVisible("members.{$key}.can_teach_quran")
        ->assertFormFieldIsHidden("members.{$key}.pension_income");

    $page->set("data.members.{$key}.occupation", 'retired')
        ->assertFormFieldIsVisible("members.{$key}.pension_income")
        ->assertFormFieldIsHidden("members.{$key}.sought_trade_ar");
});

it('asks about the health of somebody who cannot work, and where the paper goes', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    [$page, $key] = oneMember($case);

    $page->set("data.members.{$key}.occupation", 'unable')
        ->assertFormFieldIsVisible("members.{$key}.condition_description_ar")
        ->assertFormFieldIsVisible("members.{$key}.doctor_phone_encrypted");

    // The upload itself has one path in this system, and the screen says so.
    expect(__('sanabel.household_member.condition_evidence_help'))
        ->toContain('الوثائق')
        ->toContain('تقرير طبي');
});

it('saves what the status asked, and keeps the old toggles honest', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    $member = $case->members()->first();

    $member->forceFill([
        'occupation' => 'employee',
        'workplace_ar' => 'مخبز البلدة',
        'work_income' => 300000,
    ])->save();

    expect($member->refresh()->works)->toBeTrue()
        ->and($member->is_student)->toBeFalse();

    $member->forceFill(['occupation' => 'student'])->save();

    expect($member->refresh()->is_student)->toBeTrue()
        // A student does not also hold a job because a toggle said so once.
        ->and($member->works)->toBeFalse();

    $member->forceFill(['occupation' => 'jobseeker'])->save();

    expect($member->refresh()->works)->toBeFalse()
        ->and($member->is_student)->toBeFalse();
});

it('lets the researcher correct the dependency the rules worked out', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    [$page, $key, $id] = oneMember($case);

    // An adult the rules read as an earner, whom the researcher met and knows
    // is kept by the household.
    $page->set("data.members.{$key}.birth_year", (int) date('Y') - 30)
        ->set("data.members.{$key}.occupation", 'housewife')
        ->set("data.members.{$key}.dependent", true)
        ->set("data.members.{$key}.unable_to_earn", true)
        ->call('save')
        ->assertHasNoFormErrors();

    $member = HouseholdMember::find($id);

    expect($member->dependent)->toBeTrue()
        ->and($member->unable_to_earn)->toBeTrue();
});

it('still fills the dependency from the rules when nobody answers it', function () {
    // An import and the offline sync send neither flag, and the rules answer.
    $case = familyOf($this->region, adults: 0, children: 0);

    $child = $case->members()->create([
        'name_ar' => 'طفل', 'relation' => 'son', 'birth_year' => (int) date('Y') - 6,
        'gender' => 'male', 'person_class' => 'child',
        'dependent' => true, 'unable_to_earn' => false,
    ]);

    expect($child->dependent)->toBeTrue();
});

it('destroys the member health note with the rest of the family', function () {
    expect(AnonymiseService::CLEARED[HouseholdMember::class])
        ->toContain('condition_description_ar')
        ->toContain('doctor_phone_encrypted');
})->group('GOV-01');
