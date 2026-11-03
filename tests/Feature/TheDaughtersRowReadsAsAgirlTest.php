<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;

/*
 | T-235 — «لازم لما تكون أنثى، يتم وضع تاء التأنيث: يتيمة تدرس وغيرها»,
 | 3 November, with a photograph of a daughter's row on a family page
 | reading «ابنة … أقل من 15 سنة … يتيم … طفلة … يدرس».
 |
 | T-185 and T-193 feminised the lists a word is chosen from — «طالبة»,
 | «بالغة», «متزوجة» — and three words were missed, all three for the same
 | reason: none of them comes from a list. They are printed from a flag, so
 | the gender never reached them. «طفلة» was right on the very same row,
 | which is what made the rest read as carelessness rather than convention.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** A widow's household holding one child of this gender. */
function widowWithAchild(string $gender, array $member = []): Beneficiary
{
    $case = publishedCase(test()->region);
    $case->forceFill(['marital_status' => 'widowed'])->save();
    $case->members()->delete();

    $case->members()->create(array_merge([
        // Synthetic, like every family in this suite (rule 11).
        'name_ar' => 'اسم تجريبي',
        'relation' => $gender === 'female' ? 'daughter' : 'son',
        'gender' => $gender,
        'birth_year' => now()->year - 9,
        'dependent' => true,
        'is_orphan' => true,
        'is_student' => true,
        'person_class' => 'child',
    ], $member));

    return $case->refresh();
}

/** The one dependant's row, as a donor is served it. */
function theRow(Beneficiary $case): array
{
    return (new MaskedCaseResource($case))->resolve()['dependents'][0];
}

it('says «يتيمة» of a daughter, not «يتيم»', function () {
    expect(theRow(widowWithAchild('female'))['orphan_label'])->toBe('يتيمة');
});

it('still says «يتيم» of a son', function () {
    expect(theRow(widowWithAchild('male'))['orphan_label'])->toBe('يتيم');
});

it('says «تدرس» of a daughter who studies, not «يدرس»', function () {
    expect(theRow(widowWithAchild('female'))['occupation_label'])->toBe('تدرس');
});

it('still says «يدرس» of a son who studies', function () {
    expect(theRow(widowWithAchild('male'))['occupation_label'])->toBe('يدرس');
});

it('says «تعمل» of a daughter who works', function () {
    $row = theRow(widowWithAchild('female', ['is_student' => false, 'works' => true]));

    expect($row['occupation_label'])->toBe('تعمل');
});

it('says «حافظة للقرآن» of a daughter who has memorised it', function () {
    $row = theRow(widowWithAchild('female', ['is_quran_memorizer' => true]));

    expect($row['quran_memorizer_label'])->toBe('حافظة للقرآن');
});

it('still says «حافظ للقرآن» of a son', function () {
    $row = theRow(widowWithAchild('male', ['is_quran_memorizer' => true]));

    expect($row['quran_memorizer_label'])->toBe('حافظ للقرآن');
});

it('leaves the whole row agreeing with itself, which is what they photographed', function () {
    $row = theRow(widowWithAchild('female'));

    // «ابنة … طفلة … يتيمة … تدرس»: one row, one gender, no masculine word
    // standing in the middle of it.
    expect($row['age_band_label'])->toBe('طفلة')
        ->and($row['orphan_label'])->toBe('يتيمة')
        ->and($row['occupation_label'])->toBe('تدرس');
});

it('keeps the orphan fact itself, which other screens read', function () {
    // The word is for the eye; the flag is for the system, and it stays.
    expect(theRow(widowWithAchild('female'))['is_orphan'])->toBeTrue();
});

it('says nothing of orphanhood where the father is alive on the file', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['marital_status' => 'married'])->save();
    $case->members()->delete();
    $case->members()->create([
        'name_ar' => 'اسم تجريبي',
        'relation' => 'daughter', 'gender' => 'female', 'birth_year' => now()->year - 9,
        'dependent' => true, 'is_orphan' => true, 'person_class' => 'child',
    ]);

    // T-188 — a child is not described as orphaned on a file whose father
    // is alive and on it, whatever the column says.
    expect(theRow($case->refresh())['orphan_label'])->toBeNull();
});
