<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use Filament\Facades\Filament;

/*
 | T-155 — «عند اختيار الصفة اخرى لازم يتم حقل لكتابة بشكل حر», 29 October.
 |
 | The kinship list has asked which since the beginning. The status list — the
 | one T-129 made decide what is asked next — ended at «أخرى», so the file
 | carried a member nobody could describe.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('asks what «أخرى» means, and will not take the row without it', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['members' => [memberRow(['occupation' => 'other'])]])
        ->call('save')
        ->assertHasFormErrors();
});

it('keeps what was written in the family’s own words', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['members' => [memberRow([
            'occupation' => 'other',
            'occupation_other_ar' => 'يرعى أغنام الجيران بالأجرة',
        ])]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())
        ->members()->first()->occupation_other_ar)
        ->toBe('يرعى أغنام الجيران بالأجرة');
});

it('asks nothing extra of any other status', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['members' => [memberRow(['occupation' => 'housewife'])]])
        ->call('save')
        ->assertHasNoFormErrors();
});
