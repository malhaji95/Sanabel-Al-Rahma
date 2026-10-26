<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;

/*
 | T-154 — «في حال اختيار حالة تمنع العمل لازم يتم تفعيل خيار ارفاق تقرير
 | طبي», 29 October.
 |
 | Saying that somebody cannot work is the heaviest single answer on the form:
 | it moves a person out of the earning class and into the need. The paper
 | behind it used to be asked for in a sentence under the member's row and
 | uploaded from another screen on another day, if at all.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** One member row, with the toggles a new row carries in the browser. */
function memberRow(array $overrides = []): array
{
    return array_merge([
        'name_ar' => 'فرد',
        'relation' => 'brother',
        'gender' => 'male',
        'birth_year' => 1990,
        'person_class' => 'adult',
        'smokes' => false,
        'is_quran_memorizer' => false,
        'has_documented_condition' => false,
        'has_certificate' => false,
        'can_teach_quran' => false,
        'is_orphan' => false,
    ], $overrides);
}

it('offers the report the moment the household says somebody cannot work', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    $page = Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['members' => [memberRow()]]);

    $page->assertDontSee(__('sanabel.beneficiary.medical_report'));

    $page->fillForm(['members' => [memberRow(['occupation' => 'unable'])]])
        ->assertSee(__('sanabel.beneficiary.medical_report'));
});

it('offers it for a documented condition too, whatever the status says', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['members' => [memberRow(['has_documented_condition' => true])]])
        ->assertSee(__('sanabel.beneficiary.medical_report'));
});

it('files the report as a medical paper on the family’s own documents', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [memberRow(['occupation' => 'unable'])],
            'medical_report' => UploadedFile::fake()->image('report.jpg'),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $case = Beneficiary::withoutGlobalScopes()->find($case->getKey());

    expect($case->documents()->pluck('kind')->all())->toBe(['medical'])
        // Rule: never a donor's to see. The store keeps it internal.
        ->and($case->documents()->first()->visibility)->toBe('internal');
});

it('registers a family that has not been to a doctor yet', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['members' => [memberRow(['occupation' => 'unable'])]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->documents()->count())->toBe(0);
});
