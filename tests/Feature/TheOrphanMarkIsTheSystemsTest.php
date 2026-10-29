<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use App\Services\FamilyAges;
use Filament\Facades\Filament;

/*
 | T-173 — «بخصوص خيار يتيم, هل هو مخفي غير ظاهر حتى يتم تطبيق الشرط؟ الأفضل
 | يكون ظاهر لكن يتم اختياره من النظام بشكل تلقائي حسب تحقق الشرط, أي فقط
 | للعرض», 1 November.
 |
 | T-153 hid the switch wherever it could not be true, which answered a
 | different question: a box that vanishes reads like a box nobody thought
 | about. It is drawn on every member now, nobody can press it, and what is
 | saved is the rule's own answer rather than whatever the switch said.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('marks a child of a widow under fifteen, and nobody else', function () {
    expect(FamilyAges::countsAsOrphan(9, 'widowed', 'female'))->toBeTrue()
        ->and(FamilyAges::countsAsOrphan(14, 'widowed', 'female'))->toBeTrue()
        // Fifteen is the age this form starts answering as an adult.
        ->and(FamilyAges::countsAsOrphan(15, 'widowed', 'female'))->toBeFalse()
        ->and(FamilyAges::countsAsOrphan(9, 'married', 'female'))->toBeFalse()
        ->and(FamilyAges::countsAsOrphan(9, 'divorced', 'female'))->toBeFalse()
        ->and(FamilyAges::countsAsOrphan(9, null, 'female'))->toBeFalse()
        // An age nobody has typed yet is not an orphan either.
        ->and(FamilyAges::countsAsOrphan(null, 'widowed', 'female'))->toBeFalse();
});

it('marks nobody on a widower’s file, because an orphan has lost a father', function () {
    /*
     | T-188 — «اليتيم فقط من فقد الأب», 29 October. A widowed head has lost a
     | spouse; which spouse is what decides. A widow has lost these children's
     | father. A widower has lost their mother, and by the association's own
     | definition they are not orphans — which is why their rule has always
     | been written in the feminine, «لما تكون أرملة».
     */
    expect(FamilyAges::countsAsOrphan(9, 'widowed', 'male'))->toBeFalse()
        // And a file whose head has not said is not guessed at either way.
        ->and(FamilyAges::countsAsOrphan(9, 'widowed', null))->toBeFalse();
});

it('fills the mark in itself, whatever the form was sent', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'marital_status' => 'widowed',
        'gender' => 'female',
        'spouse_name_ar' => 'المرحوم خالد',
        'birth_date' => '1990-01-01',
    ]);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [memberRow([
                'name_ar' => 'طفل',
                'relation' => 'son',
                'gender' => 'male',
                'birth_year' => (int) date('Y') - 8,
                'person_class' => 'child',
                // The screen cannot send this, and a crafted request that does
                // is answered by the rule rather than obeyed.
                'is_orphan' => false,
            ])],
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->first()->is_orphan)
        ->toBeTrue();
});

it('refuses the mark where the rule does not hold, however the form was sent', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'marital_status' => 'married',
        'gender' => 'male',
        'spouse_name_ar' => 'سعاد',
        'birth_date' => '1990-01-01',
    ]);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [memberRow([
                'name_ar' => 'طفل',
                'relation' => 'son',
                'gender' => 'male',
                'birth_year' => (int) date('Y') - 8,
                'person_class' => 'child',
                'is_orphan' => true,
            ])],
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->first()->is_orphan)
        ->toBeFalse();
});

it('recomputes every mark on a household whose head stops being a widow', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'marital_status' => 'widowed',
        'gender' => 'female',
        'spouse_name_ar' => 'المرحوم خالد',
        'birth_date' => '1990-01-01',
    ]);

    $row = memberRow([
        'name_ar' => 'طفل',
        'relation' => 'son',
        'gender' => 'male',
        'birth_year' => (int) date('Y') - 8,
        'person_class' => 'child',
    ]);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['members' => [$row], ...gatesAnsweredNo()])
        ->call('save');

    $member = Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->first();
    expect($member->is_orphan)->toBeTrue();

    /*
     | She remarries, and the file is saved again. The page is opened fresh so
     | the repeater holds the row that is already there — filling `members`
     | from a test array would add a second child rather than correct the
     | first. Only the two boxes the rule reads are touched.
     */
    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'marital_status' => 'married',
            'spouse_name_ar' => 'سمير',
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->count())->toBe(1)
        ->and($member->fresh()->is_orphan)->toBeFalse();
});
