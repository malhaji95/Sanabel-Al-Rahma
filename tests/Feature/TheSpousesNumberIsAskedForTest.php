<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use Filament\Facades\Filament;

/*
 | T-187 — «بيانات الزوج أو الزوجة: لما يكون متزوج أو أرمل يكون الرقم الوطني
 | إجباري, لما يكون مطلق أو منفصل ما يكون إجباري», 29 October.
 |
 | T-152 left it optional everywhere, reasoning that a husband who died in
 | 2013 may have left no number behind. The association has decided otherwise
 | for those two: a marriage and a widowhood are the files they check against
 | the civil registry. A separation is the one where the paper is not the
 | family's to produce, and there it stays open.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** Saves the file with the spouse block filled as the test asks. */
function saveWithSpouse($case, string $status, ?string $nationalId)
{
    return Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'marital_status' => $status,
            'spouse_name_ar' => 'خالد',
            'spouse_national_id_encrypted' => $nationalId,
            ...gatesAnsweredNo(),
        ])
        ->call('save');
}

it('refuses a married file with no number for the spouse', function () {
    saveWithSpouse(familyOf($this->region, adults: 1, children: 0), 'married', null)
        ->assertHasFormErrors(['spouse_national_id_encrypted']);
});

it('refuses a widowed file with no number for the spouse', function () {
    saveWithSpouse(familyOf($this->region, adults: 1, children: 0), 'widowed', null)
        ->assertHasFormErrors(['spouse_national_id_encrypted']);
});

it('takes a divorced file without one', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    saveWithSpouse($case, 'divorced', null)->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->marital_status)
        ->toBe('divorced');
});

it('takes a separated file without one', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    saveWithSpouse($case, 'separated', null)->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->marital_status)
        ->toBe('separated');
});

it('keeps the number it is given, and keeps it encrypted', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    saveWithSpouse($case, 'married', '09876543210')->assertHasNoFormErrors();

    $saved = Beneficiary::withoutGlobalScopes()->find($case->getKey());

    expect($saved->spouse_national_id_encrypted)->toBe('09876543210')
        // Encrypted at rest, like the head's own: the column never holds it
        // in the clear.
        ->and($saved->getRawOriginal('spouse_national_id_encrypted'))->not->toBe('09876543210');
})->group('DATA-04');

it('still refuses a number of the wrong length', function () {
    saveWithSpouse(familyOf($this->region, adults: 1, children: 0), 'married', '123')
        ->assertHasFormErrors(['spouse_national_id_encrypted']);
});

it('asks nothing of a single head, who has no spouse block at all', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'marital_status' => 'single',
            'birth_date' => '1990-01-01',
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});
