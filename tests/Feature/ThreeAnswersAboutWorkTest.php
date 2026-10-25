<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;

/*
 | T-127 — «بخصوص خيار هل يعمل صاحب العلاقة, الافضل يكون نعم, لا, يبحث عن عمل.
 | في حال نعم يذكر المهنة والراتب الحالي, في حال يبحث عن عمل تظهر عنده ما هي
 | المهنة التي يستطيع العمل بها وهل لديه شهادة وكم سنوات الخبرة واضف خيار هل
 | يستطيع تدريس القرأن الكريم», 27 October.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** The edit screen of one family, open in front of an account that may write. */
function openFile(Beneficiary $case): Testable
{
    return Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()]);
}

it('asks where the work is only when there is work', function () {
    $case = familyOf($this->region);

    openFile($case)
        ->fillForm(['work_status' => 'yes'])
        ->assertFormFieldIsVisible('profession_ar')
        ->assertFormFieldIsVisible('workplace_ar')
        ->assertFormFieldIsVisible('work_income')
        // None of the jobseeker's questions mean anything of someone in work.
        ->assertFormFieldIsHidden('sought_trade_ar')
        ->assertFormFieldIsHidden('experience_years')
        ->assertFormFieldIsHidden('can_teach_quran');
});

it('asks what a jobseeker can do, and what they can show for it', function () {
    $case = familyOf($this->region);

    $page = openFile($case)
        ->fillForm(['work_status' => 'seeking'])
        ->assertFormFieldIsVisible('sought_trade_ar')
        ->assertFormFieldIsVisible('has_certificate')
        ->assertFormFieldIsVisible('experience_years')
        ->assertFormFieldIsVisible('can_teach_quran')
        ->assertFormFieldIsHidden('workplace_ar')
        ->assertFormFieldIsHidden('work_income')
        // Which certificate is a question for whoever has one.
        ->assertFormFieldIsHidden('certificate_ar');

    $page->fillForm(['has_certificate' => true])
        ->assertFormFieldIsVisible('certificate_ar');
});

it('asks nothing further of a household with no work and no search', function () {
    $case = familyOf($this->region);

    openFile($case)
        ->fillForm(['work_status' => 'no'])
        ->assertFormFieldIsHidden('profession_ar')
        ->assertFormFieldIsHidden('workplace_ar')
        ->assertFormFieldIsHidden('sought_trade_ar');
});

it('saves the search as a search, and keeps the old flag honest', function () {
    $case = familyOf($this->region);

    openFile($case)
        ->fillForm([
            'work_status' => 'seeking',
            'sought_trade_ar' => 'نجارة',
            'has_certificate' => true,
            'certificate_ar' => 'شهادة مهنية في النجارة',
            'experience_years' => 7,
            'can_teach_quran' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $case->refresh();

    expect($case->work_status)->toBe('seeking')
        ->and($case->sought_trade_ar)->toBe('نجارة')
        ->and($case->experience_years)->toBe(7)
        ->and($case->can_teach_quran)->toBeTrue()
        // «يبحث عن عمل» is not work: the boolean the masked card reads says so.
        ->and($case->works)->toBeFalse();
});

it('keeps the two columns in step whichever one is written', function () {
    // Not only from the panel: the offline sync and the import write rows too.
    $case = familyOf($this->region);

    $case->forceFill(['work_status' => 'yes'])->save();
    expect($case->refresh()->works)->toBeTrue();

    $case->forceFill(['work_status' => 'no'])->save();
    expect($case->refresh()->works)->toBeFalse();
});

it('refuses work, and the search for it, from a three-year-old', function () {
    $case = familyOf($this->region);

    openFile($case)
        ->fillForm(['birth_date' => now()->subYears(3)->toDateString(), 'work_status' => 'yes'])
        ->call('save')
        ->assertHasFormErrors(['work_status']);

    openFile($case)
        ->fillForm(['birth_date' => now()->subYears(3)->toDateString(), 'work_status' => 'seeking'])
        ->call('save')
        ->assertHasFormErrors(['work_status']);

    /*
     | And «لا يعمل» is true of a three-year-old, so it is accepted — with
     | the marriage cleared too, because T-121 refuses that of a child as
     | well and this test is about the work question.
     */
    openFile($case)
        ->fillForm([
            'birth_date' => now()->subYears(3)->toDateString(),
            'marital_status' => 'single',
            'work_status' => 'no',
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});
