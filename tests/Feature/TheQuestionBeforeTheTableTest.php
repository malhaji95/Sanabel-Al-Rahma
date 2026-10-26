<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use App\Models\Income;
use Filament\Facades\Filament;

/*
 | T-156 — «عند خيار الدخل او الصحة او الممتلكات … بالبداية يتم تفعيل متل
 | Radio button هل يوجد دخل, اذا كان نعم يظهر باقي تفاصيل الادخال واذا كان لا
 | يتم اخفاء تفاصيل الادخال للانتقال للصفحة التالية. وبشكل عام عند عدم تعبئة
 | البيانات لا يجب ان يتم الانتقال للصفحة التالية», 29 October.
 |
 | T-130 made an empty table mean «لا», which stopped the form refusing a file
 | over a blank row nobody had asked for. It also left no way to tell an
 | unasked question from an answered one. The question is asked outright now.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('opens on the answer the file already gives', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    expect(BeneficiaryResource::gateAnswer($case, 'incomes'))->toBe('no');

    Income::factory()->create(['beneficiary_id' => $case->id, 'amount' => 50_000]);

    expect(BeneficiaryResource::gateAnswer($case->fresh(), 'incomes'))->toBe('yes')
        // A file being written for the first time has not answered anything.
        ->and(BeneficiaryResource::gateAnswer(null, 'incomes'))->toBeNull();
});

it('shows the table on «نعم» and hides it on «لا»', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    $page = Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['has_income' => 'yes']);

    $page->assertSee(__('sanabel.repeater.add_incomes'));

    $page->fillForm(['has_income' => 'no'])
        ->assertDontSee(__('sanabel.repeater.add_incomes'));
});

it('will not let the page turn on an unanswered question', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['has_income' => null])
        ->call('save')
        ->assertHasFormErrors(['has_income']);
});

it('empties the table when the answer becomes «لا»', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    Income::factory()->create(['beneficiary_id' => $case->id, 'amount' => 50_000]);

    expect($case->incomes()->count())->toBe(1);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['has_income' => 'no'])
        ->call('save')
        ->assertHasNoFormErrors();

    // The file cannot say «لا» while holding rows that say otherwise.
    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->incomes()->count())->toBe(0);
});

it('asks the same question of health and of what the family owns', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['has_health' => 'yes', 'has_assets' => 'yes'])
        ->assertSee(__('sanabel.repeater.add_health'))
        ->assertSee(__('sanabel.repeater.add_assets'))
        ->fillForm(['has_health' => 'no', 'has_assets' => 'no'])
        ->assertDontSee(__('sanabel.repeater.add_health'))
        ->assertDontSee(__('sanabel.repeater.add_assets'));
});
