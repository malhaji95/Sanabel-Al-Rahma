<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use App\Services\FamilyAges;
use Filament\Facades\Filament;

/*
 | T-153 — «مثلا سجلنا الابن مواليد 1996 والام 1997 لازم النظام يرفض هيك
 | حالات لأنه الابن هو اصغر من الاب والام … وفي حال كان عمره اكبر من العمر
 | المفروض لخيار يتيم لازم يختفي خيار يتيم بحيث تحت 15 يعني شرط تفعيل اليتيم
 | تكون الام ارملة وعمره تحت 15», 29 October.
 */

/**
 * A new row in the browser carries the toggles' own default, which is false.
 * fillForm() replaces the state wholesale, so the defaults are written here.
 *
 * @return array<string,bool>
 */
function memberToggles(): array
{
    return [
        'smokes' => false,
        'is_quran_memorizer' => false,
        'has_documented_condition' => false,
        'has_certificate' => false,
        'can_teach_quran' => false,
        'is_orphan' => false,
    ];
}

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('refuses a son born before the head of the household', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    $case->forceFill(['birth_date' => '1997-04-01'])->save();

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [[
                'name_ar' => 'ابن',
                'relation' => 'son',
                'gender' => 'male',
                'birth_year' => 1996,
                'person_class' => 'adult',
            ]],
        ])
        ->call('save')
        ->assertHasFormErrors();
});

it('takes the same son once his year is after his mother’s', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    $case->forceFill(['birth_date' => '1977-04-01'])->save();

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [[
                'name_ar' => 'ابن',
                'relation' => 'son',
                'gender' => 'male',
                'birth_year' => 1996,
                'person_class' => 'adult',
                ...memberToggles(),
            ]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->count())->toBe(1);
});

it('refuses a mother younger than the head she is the mother of', function () {
    $case = familyOf($this->region, adults: 1, children: 0);
    $case->forceFill(['birth_date' => '1960-04-01'])->save();

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [[
                'name_ar' => 'أم',
                'relation' => 'mother',
                'gender' => 'female',
                'birth_year' => 1990,
                'person_class' => 'elderly',
            ]],
        ])
        ->call('save')
        ->assertHasFormErrors();
});

it('says nothing about a brother, whichever of them is older', function () {
    expect(FamilyAges::birthYearFits('brother', 1960, 1990))->toBeTrue()
        ->and(FamilyAges::birthYearFits('other', 1960, 1990))->toBeTrue()
        // And an unanswered year is not a refusal: a file is filled over weeks.
        ->and(FamilyAges::birthYearFits('son', null, 1990))->toBeTrue()
        ->and(FamilyAges::birthYearFits('son', 1980, null))->toBeTrue();
});

it('asks «يتيم» only of a child under fifteen in a widowed household', function () {
    expect(FamilyAges::orphanMayBeAsked(9, 'widowed'))->toBeTrue()
        // Old enough to answer for himself.
        ->and(FamilyAges::orphanMayBeAsked(15, 'widowed'))->toBeFalse()
        ->and(FamilyAges::orphanMayBeAsked(22, 'widowed'))->toBeFalse()
        // Both parents living.
        ->and(FamilyAges::orphanMayBeAsked(9, 'married'))->toBeFalse()
        ->and(FamilyAges::orphanMayBeAsked(9, 'divorced'))->toBeFalse()
        ->and(FamilyAges::orphanMayBeAsked(9, null))->toBeFalse()
        // And an age nobody has answered yet is not a yes.
        ->and(FamilyAges::orphanMayBeAsked(null, 'widowed'))->toBeFalse();
});
