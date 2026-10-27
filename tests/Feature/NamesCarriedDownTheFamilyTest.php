<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use App\Services\FamilyNames;
use Filament\Facades\Filament;

/*
 | T-175 — «لازم يتم تعبئة المعلومات تباعًا, أي فقط الأب مطلوب الكنية والابن
 | ياخد كنية الأب, لكن بنفس الوقت يتم طلب كنية الزوجة التي هي الأم, لكن للابن
 | يتم تعبئة اسم الأم مباشر بعد تعبئة معلومات أمه وهكذا», 1 November.
 |
 | And its other half, T-176: «إذا كان المعال غير الابن والبنت نحتاج اسم الأب
 | والكنية».
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('reads a child’s two parents off the head’s own row', function () {
    // A household headed by a man: he is the father, the wife he named is
    // the mother.
    expect(FamilyNames::fatherFor('son', 'male', 'أحمد', 'سعاد'))->toBe('أحمد')
        ->and(FamilyNames::motherFor('son', 'male', 'أحمد', 'سعاد'))->toBe('سعاد')
        // And headed by a woman, the other way round.
        ->and(FamilyNames::fatherFor('daughter', 'female', 'سعاد', 'أحمد'))->toBe('أحمد')
        ->and(FamilyNames::motherFor('daughter', 'female', 'سعاد', 'أحمد'))->toBe('سعاد');
});

it('asks rather than guesses for everybody else', function () {
    // «إذا كان المعال غير الابن والبنت نحتاج اسم الأب والكنية» — a brother, a
    // mother, a wife and «أخرى» are nobody's child on this file.
    foreach (['brother', 'sister', 'mother', 'father', 'spouse', 'other', 'head'] as $relation) {
        expect(FamilyNames::inheritsFromHead($relation))->toBeFalse()
            ->and(FamilyNames::surnameFor($relation, 'الحلبي'))->toBeNull()
            ->and(FamilyNames::fatherFor($relation, 'male', 'أحمد', 'سعاد'))->toBeNull();
    }
});

it('guesses nothing from a head who has not said which parent they are', function () {
    expect(FamilyNames::fatherFor('son', null, 'أحمد', 'سعاد'))->toBeNull()
        ->and(FamilyNames::motherFor('son', null, 'أحمد', 'سعاد'))->toBeNull()
        // A spouse nobody has named yet is not an empty name on the row.
        ->and(FamilyNames::motherFor('son', 'male', 'أحمد', null))->toBeNull()
        ->and(FamilyNames::surnameFor('son', null))->toBeNull();
});

it('saves a son with the file’s surname and both his parents, unasked', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'first_name' => 'أحمد',
        'family_name' => 'الحلبي',
        'gender' => 'male',
        'marital_status' => 'married',
        'spouse_name_ar' => 'سعاد',
        'birth_date' => '1985-01-01',
    ]);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [memberRow([
                'name_ar' => 'بلال',
                'relation' => 'son',
                'gender' => 'male',
                'birth_year' => 2015,
                'person_class' => 'child',
                // Left blank on purpose: the screen locks these three boxes.
                'family_name_ar' => null,
                'father_name_ar' => null,
                'mother_name_ar' => null,
            ])],
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $son = Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->first();

    expect($son->family_name_ar)->toBe('الحلبي')
        ->and($son->father_name_ar)->toBe('أحمد')
        ->and($son->mother_name_ar)->toBe('سعاد');
});

it('keeps what was typed for a dependant who is nobody’s child here', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'first_name' => 'أحمد',
        'family_name' => 'الحلبي',
        'gender' => 'male',
        'marital_status' => 'married',
        'spouse_name_ar' => 'سعاد',
        'birth_date' => '1985-01-01',
    ]);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [memberRow([
                'name_ar' => 'ياسر',
                'relation' => 'brother',
                'gender' => 'male',
                'birth_year' => 1988,
                'person_class' => 'adult',
                'family_name_ar' => 'الشامي',
                'father_name_ar' => 'سليم',
                'mother_name_ar' => 'هالة',
            ])],
            ...gatesAnsweredNo(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $brother = Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->first();

    expect($brother->family_name_ar)->toBe('الشامي')
        ->and($brother->father_name_ar)->toBe('سليم')
        ->and($brother->mother_name_ar)->toBe('هالة');
});

it('renames the children when the file is renamed', function () {
    $case = familyOf($this->region, adults: 1, children: 0, attributes: [
        'first_name' => 'أحمد',
        'family_name' => 'الحلبي',
        'gender' => 'male',
        'marital_status' => 'married',
        'spouse_name_ar' => 'سعاد',
        'birth_date' => '1985-01-01',
    ]);

    $page = Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'members' => [memberRow([
                'name_ar' => 'بلال',
                'relation' => 'son',
                'gender' => 'male',
                'birth_year' => 2015,
                'person_class' => 'child',
            ])],
            ...gatesAnsweredNo(),
        ])
        ->call('save');

    // A surname spelled wrong on the file was spelled wrong on every child.
    $page->fillForm(['family_name' => 'الحلبيّ', 'spouse_name_ar' => 'سعاد الحلو'])
        ->call('save')
        ->assertHasNoFormErrors();

    $son = Beneficiary::withoutGlobalScopes()->find($case->getKey())->members()->first();

    expect($son->family_name_ar)->toBe('الحلبيّ')
        ->and($son->mother_name_ar)->toBe('سعاد الحلو');
});

it('never publishes a dependant’s name, however many boxes now hold one', function () {
    $case = publishedCase($this->region, adults: 1, children: 1);

    $case->members()->first()->forceFill([
        'name_ar' => 'بلال',
        'family_name_ar' => 'الحلبي',
        'father_name_ar' => 'أحمد',
        'mother_name_ar' => 'سعاد',
    ])->save();

    expect(json_encode(maskedOf($case->fresh()), JSON_UNESCAPED_UNICODE))
        ->not->toContain('بلال')
        ->not->toContain('الحلبي')
        ->not->toContain('أحمد')
        ->not->toContain('سعاد');
})->group('PRIV-01');
