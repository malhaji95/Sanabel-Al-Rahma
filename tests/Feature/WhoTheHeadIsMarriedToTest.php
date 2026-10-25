<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use App\Services\AnonymiseService;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;

/*
 | T-128 — «ضمن ملف المستفيد: مافي بيانات الزوجة, او بيانات الزوج, يجب تصحيح
 | الامر وتضمينهم حتى لو متوفية يتم ذكرهم», 27 October.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

function headsFile(Beneficiary $case): Testable
{
    return Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()]);
}

it('asks about the spouse of anyone who is not single', function () {
    $case = familyOf($this->region);

    headsFile($case)
        ->fillForm(['marital_status' => 'single'])
        ->assertFormFieldIsHidden('spouse_name_ar')
        ->fillForm(['marital_status' => 'married'])
        ->assertFormFieldIsVisible('spouse_name_ar')
        ->assertFormFieldIsVisible('spouse_national_id_encrypted')
        // And of a widow too — that is the whole point of the note.
        ->fillForm(['marital_status' => 'widowed'])
        ->assertFormFieldIsVisible('spouse_name_ar');
});

it('names a widow her husband and a married man his wife', function () {
    expect(BeneficiaryResource::spouseLabel('female'))->toBe(__('sanabel.beneficiary.spouse_husband'))
        ->and(BeneficiaryResource::spouseLabel('male'))->toBe(__('sanabel.beneficiary.spouse_wife'))
        // Before the gender is answered, neither word is guessed.
        ->and(BeneficiaryResource::spouseLabel(null))->toBe(__('sanabel.beneficiary.spouse_either'))
        ->and(BeneficiaryResource::spouseNameLabel('female'))->toBe(__('sanabel.beneficiary.spouse_husband_name'));
});

it('keeps the name and the number of a spouse who has died', function () {
    $case = familyOf($this->region);

    headsFile($case)
        ->fillForm([
            'gender' => 'female',
            'marital_status' => 'widowed',
            'spouse_name_ar' => 'خالد أحمد العلي',
            'spouse_national_id_encrypted' => '١٢٣٤٥٦٧٨٩٠١',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $case->refresh();

    expect($case->spouse_name_ar)->toBe('خالد أحمد العلي')
        // Arabic-Indic digits in, eleven ASCII digits stored, encrypted at rest.
        ->and($case->spouse_national_id_encrypted)->toBe('12345678901')
        ->and($case->getRawOriginal('spouse_national_id_encrypted'))->not->toContain('12345678901');
});

it('refuses an identity number that is not eleven digits', function () {
    $case = familyOf($this->region);

    headsFile($case)
        ->fillForm([
            'marital_status' => 'married',
            'spouse_name_ar' => 'سارة',
            'spouse_national_id_encrypted' => '1234',
        ])
        ->call('save')
        ->assertHasFormErrors(['spouse_national_id_encrypted']);
});

it('reads the spouse back onto the screen instead of blanking her', function () {
    // The same trap the family's own phone and wallet fell into: $hidden keeps
    // the column out of the array Filament fills the form from.
    $case = familyOf($this->region);
    $case->forceFill([
        'marital_status' => 'married',
        'spouse_name_ar' => 'سارة محمد',
        'spouse_national_id_encrypted' => '12345678901',
    ])->save();

    headsFile($case)
        ->assertFormSet([
            'spouse_name_ar' => 'سارة محمد',
            'spouse_national_id_encrypted' => '12345678901',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($case->refresh()->spouse_national_id_encrypted)->toBe('12345678901');
});

it('destroys the spouse with the rest of the family when a file is anonymised', function () {
    expect(AnonymiseService::CLEARED[Beneficiary::class])
        ->toContain('spouse_name_ar')
        ->toContain('spouse_national_id_encrypted');
})->group('GOV-01');

it('never puts the spouse in a donor-facing card', function () {
    $case = publishedCase($this->region);
    $case->forceFill([
        'marital_status' => 'married',
        'spouse_name_ar' => 'سارة محمد',
        'spouse_national_id_encrypted' => '12345678901',
    ])->save();

    $masked = json_encode(
        (new MaskedCaseResource($case->fresh()))->resolve(),
        JSON_UNESCAPED_UNICODE,
    );

    expect($masked)->not->toContain('سارة محمد')
        ->and($masked)->not->toContain('12345678901');
})->group('AUTH-02');
