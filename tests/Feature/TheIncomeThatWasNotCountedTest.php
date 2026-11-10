<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Services\NeedEngine;
use Filament\Facades\Filament;

/*
 | T-285 — «أُدخل راتب بقيمة 500، ودخل إضافي بقيمة 100 … لكن الظاهر أنه تم
 | احتساب 500 فقط», 10 November.
 |
 | Nothing miscounted: the second row was saved as an unstable income, and an
 | unstable income is never deducted. It was a toggle, and a toggle starts
 | off, so an unanswered question and a deliberate «لا» looked identical.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
    $this->case = familyOf($this->region, adults: 2, children: 1);
});

it('reproduces the association’s case: both rows counted once both are answered', function () {
    Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['has_income' => 'yes', 'incomes' => [
            ['source_type' => 'راتب', 'amount' => 500, 'is_stable' => true],
            ['source_type' => 'دخل إضافي', 'amount' => 100, 'is_stable' => true],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(NeedEngine::class)->compute($this->case->refresh())['stable_income'])->toBe(600);
});

it('refuses a row that never says whether the income is steady', function () {
    Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['has_income' => 'yes', 'incomes' => [
            ['source_type' => 'راتب', 'amount' => 500],
        ]])
        ->call('save')
        ->assertHasFormErrors();

    expect($this->case->refresh()->incomes()->count())->toBe(0);
});

it('still keeps an income the researcher called unsteady out of the deduction', function () {
    Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['has_income' => 'yes', 'incomes' => [
            ['source_type' => 'راتب', 'amount' => 500, 'is_stable' => true],
            ['source_type' => 'موسم الزيتون', 'amount' => 100, 'is_stable' => false, 'recurrence' => array_key_first(__('sanabel.income_recurrence'))],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $case = $this->case->refresh();

    // Both rows are on the file; one of them is money the family does not
    // have every month, and the need is not reduced by it.
    expect($case->incomes()->count())->toBe(2)
        ->and(app(NeedEngine::class)->compute($case)['stable_income'])->toBe(500);
});

it('asks for the recurrence only when the answer is «لا», and not before', function () {
    $actor = userWithRole('case_officer');

    // Answered «نعم»: a steady income has no recurrence to record, and the
    // row saves without one.
    Livewire\Livewire::actingAs($actor)
        ->test(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['has_income' => 'yes', 'incomes' => [
            ['source_type' => 'راتب', 'amount' => 500, 'is_stable' => true],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    // Answered «لا» and left without one: refused, because an unsteady income
    // that does not say how often it comes is not documented at all.
    Livewire\Livewire::actingAs($actor)
        ->test(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['has_income' => 'yes', 'incomes' => [
            ['source_type' => 'موسم', 'amount' => 100, 'is_stable' => false],
        ]])
        ->call('save')
        ->assertHasFormErrors();
});
