<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\HouseholdMember;

/*
 | T-193 — «هذي أنثى, لازم بالغة موظفة», 29 October, over a photograph of a
 | sister's row reading «بالغ» and «موظف».
 |
 | The class the household is priced in was drawn from the plural list the
 | counts are made of — «بالغون» for a household, printed whole on one
 | person. One person takes the singular, and a woman takes the feminine one.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** The row a donor reads for one dependant of this gender and class. */
function rowOfDependant($region, string $gender, string $personClass, string $occupation): array
{
    $case = publishedCase($region, adults: 1, children: 0);

    HouseholdMember::factory()->create([
        'beneficiary_id' => $case->id,
        'dependent' => true,
        'gender' => $gender,
        'person_class' => $personClass,
        'occupation' => $occupation,
    ]);

    return (new MaskedCaseResource($case->fresh()))->resolve()['dependents'][0];
}

it('calls a grown sister «بالغة» and «موظفة»', function () {
    $row = rowOfDependant($this->region, 'female', 'adult', 'employee');

    expect($row['age_band_label'])->toBe('بالغة')
        ->and($row['occupation_label'])->toBe('موظفة');
});

it('calls a grown brother «بالغ» and «موظف»', function () {
    $row = rowOfDependant($this->region, 'male', 'adult', 'employee');

    expect($row['age_band_label'])->toBe('بالغ')
        ->and($row['occupation_label'])->toBe('موظف');
});

it('says one child rather than «أطفال», whichever the child is', function () {
    expect(rowOfDependant($this->region, 'female', 'child', 'student')['age_band_label'])->toBe('طفلة')
        ->and(rowOfDependant($this->region, 'male', 'child', 'student')['age_band_label'])->toBe('طفل');
});

it('keeps counting a household in the plural, where it is counting', function () {
    // The household line is a count and stays «أطفال», «بالغون»: the two
    // lists answer two different questions and neither borrows the other.
    expect(__('sanabel.masked.age_band.child'))->toBe('أطفال')
        ->and(__('sanabel.masked.age_band.adult'))->toBe('بالغون');
});

it('declares the feminine list with the same keys as the masculine one', function () {
    expect(array_keys(__('sanabel.person_class_f')))
        ->toBe(array_keys(__('sanabel.person_class')));
});
