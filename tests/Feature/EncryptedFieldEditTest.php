<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Beneficiary;

use function Pest\Livewire\livewire;

/*
 | The encrypted columns sit in the models' $hidden, which Eloquent honours in
 | attributesToArray() — the array Filament fills an edit form from. They
 | therefore reached the screen empty and were saved back as blanks, quietly
 | erasing a family's phone and wallet on any edit.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->admin = userWithRole('admin', ['region_id' => $this->region->id]);
    passTwoFactor();
});

it('opens a family edit form with the phone and wallet it already has', function () {
    $case = familyOf($this->region, attributes: [
        'phone_encrypted' => '0911111111',
        'wallet_encrypted' => '0922222222',
    ]);

    $this->actingAs($this->admin);

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertFormSet([
            'phone_encrypted' => '0911111111',
            'wallet_encrypted' => '0922222222',
        ]);
});

it('does not erase a family wallet when an unrelated field is saved', function () {
    $case = familyOf($this->region, attributes: [
        'phone_encrypted' => '0911111111',
        'wallet_encrypted' => '0922222222',
    ]);

    $this->actingAs($this->admin);

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['family_name' => 'اسم معدَّل'])
        ->call('save')
        ->assertHasNoFormErrors();

    $case = Beneficiary::withoutGlobalScopes()->find($case->getKey());

    expect($case->family_name)->toBe('اسم معدَّل')
        ->and($case->phone_encrypted)->toBe('0911111111')
        ->and($case->wallet_encrypted)->toBe('0922222222');
});

it('opens an association account with the wallet it already has', function () {
    $association = userWithRole('association', [
        'name' => 'جمعية الاختبار',
        'wallet_encrypted' => '0933333333',
    ]);

    $this->actingAs($this->admin);

    livewire(EditUser::class, ['record' => $association->getRouteKey()])
        ->assertFormSet(['wallet_encrypted' => '0933333333']);
});
