<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use Filament\Facades\Filament;

/*
 | T-197 — «بخصوص خانة المبلغ الذي يتقاضاه من عمله: نحتاج شرح عنها», 29
 | October, and the list of sources they named with it.
 |
 | T-221 — «ينجمع تلقائياً», 1 November, which moved the answer. The field
 | used to be read by nobody and deducted from nothing: a researcher wrote
 | the salary on the person, and then wrote the same salary again as an
 | income row, and only the second one counted. Both halves had to be
 | remembered, and getting it wrong in either direction was silent — write
 | it once and the family looked poorer than it is; write it twice in the
 | table and it counted twice.
 |
 | Now the engine sums what is written on the people, and the income table
 | is for what is not written on a person: a handout, the rent of a plot, a
 | pension.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('tells the researcher the amount is counted from here, and not to repeat it', function () {
    $help = __('sanabel.beneficiary.work_income_help');

    expect($help)->toContain('يُجمَع تلقائيًّا')
        ->and($help)->toContain('لا تُعِد كتابته');
});

it('names the sources that are still typed by hand, and the ones that are not', function () {
    $help = __('sanabel.income.sources_help');

    // What the table is for now: what nobody's own page carries.
    foreach (['جمعية', 'جيران', 'أقارب', 'أجرة محل', 'معاش تقاعدي'] as $source) {
        expect($help)->toContain($source);
    }

    // And what it is no longer for, said in as many words.
    expect($help)->toContain('فلا تكتبه هنا مرّة ثانية')
        // «والسنوي ÷ ١٢», the one instruction a researcher gets wrong.
        ->and($help)->toContain('اثني عشر');
});

it('puts the list in front of the researcher on the income step', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    // The table opens only once «هل يوجد دخل» is answered «نعم» (T-156),
    // and the list belongs with the table.
    expect(
        Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
            ->fillForm(['has_income' => 'yes'])
            ->html()
    )->toContain(e(__('sanabel.income.sources_help')));
});

it('shows the figure already counted from the people, above the table', function () {
    $case = familyOf($this->region, adults: 2, children: 0);
    $case->forceFill(['work_income' => 300_000, 'spouse_work_income' => 150_000])->save();

    expect(
        Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->fresh()->getRouteKey()])
            ->html()
    )->toContain(e(__('sanabel.income.from_people')))
        ->toContain(number_format(450_000));
});
