<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use Filament\Facades\Filament;

/*
 | T-197 — «بخصوص خانة المبلغ الذي يتقاضاه من عمله: نحتاج شرح عنها», 29
 | October, and the list of sources they named with it: the spouse, the
 | children, the dependants, a monthly handout from an association or from
 | neighbours or relatives, the rent of a shop or a plot, a taxi — and a
 | yearly sum divided by twelve.
 |
 | The field is read by nobody and deducted from nothing: it says who in the
 | household carries an income. The deduction is made from the «الدخل» step
 | alone, and the old sentence said only the first half of that, so a
 | researcher could not tell whether writing it twice would count it twice.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('says where the amount is counted, not only where it is not', function () {
    $help = __('sanabel.beneficiary.work_income_help');

    expect($help)->toContain('خطوة «الدخل»')
        // The half that was missing: write it there as well, and it is not
        // counted twice.
        ->and($help)->toContain('ولا يُحتسب مرّتين');
});

it('names every source the association listed', function () {
    $help = __('sanabel.income.sources_help');

    foreach (['الزوج', 'الأولاد', 'المعالين', 'جمعية', 'جيران', 'أقارب', 'أجرة محل', 'سيارة أجرة'] as $source) {
        expect($help)->toContain($source);
    }

    // «والسنوي ÷ ١٢», which is the one instruction a researcher gets wrong.
    expect($help)->toContain('اثني عشر');
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

it('still deducts from the income rows alone, and never from the person’s field', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    // A head who earns, with nothing written on the income step: the family
    // has no recorded income, which is what the engine reads.
    $case->forceFill(['work_income' => 500_000])->save();

    expect($case->fresh()->incomes()->sum('amount'))->toBe(0);
});
