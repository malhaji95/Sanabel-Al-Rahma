<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\HealthRecord;
use App\Models\Setting;

/*
 | T-222 — «اعمل خيار غير ذلك بحيث يفتح نص حر ينكتب فيه», 1 November.
 |
 | The six kinds of medicine were our wording, not the association's, and
 | they were asked which to add or drop. Their answer was not a seventh
 | word: a closed list of six cannot hold what a field researcher meets,
 | and the way out of a list is a box.
 |
 | The same shape housing already carries for «غيرها» (T-169), so whoever
 | learns one has learnt both.
 |
 | And the box stops at the file. The card prints labels out of the
 | association's own list and nothing else, which is rule 2: no free text
 | ever reaches a donor, however harmless the sentence looks.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** A published family with one health record of the given kinds. */
function caseWithMedicines(array $kinds, ?string $other = null)
{
    $case = publishedCase(test()->region);

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'condition' => 'chronic',
        'medicine_kinds' => $kinds,
        'medicine_kinds_other_ar' => $other,
    ]);

    return $case->refresh();
}

it('offers «غير ذلك» among the kinds the association may tick', function () {
    expect(__('sanabel.medicine_kinds'))->toHaveKey('other')
        ->and(__('sanabel.medicine_kinds.other'))->toBe('غير ذلك');
});

it('keeps the six that were already there', function () {
    expect(array_keys(__('sanabel.medicine_kinds')))
        ->toBe(['chronic', 'painkiller', 'antibiotic', 'imported', 'infant_formula', 'supplies', 'other']);
});

it('saves what the researcher typed in the box', function () {
    $case = caseWithMedicines(['other'], 'إبر أنسولين طويلة المفعول');

    expect($case->healthRecords->first()->medicine_kinds_other_ar)
        ->toBe('إبر أنسولين طويلة المفعول');
});

it('never lets that sentence reach the donor’s card', function () {
    $case = caseWithMedicines(['other'], 'إبر أنسولين طويلة المفعول');

    $card = (new MaskedCaseResource($case))->resolve();

    expect(json_encode($card, JSON_UNESCAPED_UNICODE))
        ->not->toContain('إبر أنسولين')
        ->and($card)->not->toHaveKey('medicine_kinds_other_ar');
});

it('prints the list’s own word for it instead, which says nothing about anyone', function () {
    $case = caseWithMedicines(['other'], 'إبر أنسولين طويلة المفعول');

    expect((new MaskedCaseResource($case))->resolve()['medicine_kinds'])
        ->toBe(['غير ذلك']);
});

it('still prints the ticked kinds beside it', function () {
    $case = caseWithMedicines(['chronic', 'other'], 'إبر أنسولين');

    expect((new MaskedCaseResource($case))->resolve()['medicine_kinds'])
        ->toContain('أدوية مزمنة دائمة')
        ->toContain('غير ذلك');
});

it('stays behind the health switch, like every other word about an illness', function () {
    $case = caseWithMedicines(['other'], 'إبر أنسولين');

    Setting::updateOrCreate(['key' => 'show_health_condition'], ['value_json' => false]);
    Setting::forgetLoaded();

    expect((new MaskedCaseResource($case))->resolve()['medicine_kinds'])->toBe([]);
});
