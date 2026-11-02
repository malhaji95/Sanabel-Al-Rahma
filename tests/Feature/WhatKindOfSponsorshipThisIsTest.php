<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use App\Models\Setting;

/*
 | T-205 — «بخصوص الكفالات لازم يتم إظهار نوع الكفالة على الواجهة: يعني كفالة
 | يتيم أو أرملة أو طالب علم», 1 November.
 |
 | The kind is the association's own classification out of their dossier, set
 | on the file by whoever may approve a case (T-177). Two of the three were
 | already in that list; «طالب علم» joined it here. Nothing is inferred from
 | the household: a widow whose children are orphans and in school is all
 | three at once, and which of them a donor is asked to sponsor is a
 | judgement rather than a query.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** The words a donor reads on a file classified this way. */
function kindsOn($region, array $classifications): array
{
    $case = publishedCase($region);
    $case->forceFill(['case_classifications' => $classifications])->save();

    // T-210 — the resource carries the association's own key beside each
    // word now, so the sponsorships page can find that door's price. What is
    // read on the screen is still the word, which is what these assert.
    return array_values((new MaskedCaseResource($case->fresh()))->resolve()['sponsorship_kinds']);
}

it('says «يتيم» on a file the association classified as one', function () {
    expect(kindsOn($this->region, ['orphan']))->toBe(['يتيم']);
});

it('says «أرملة» and «طالب علم», which are the other two they named', function () {
    // T-217 — the door is «أرملة» even where the file's own classification
    // reads «أرمل», because the door is not a description of a person.
    expect(kindsOn($this->region, ['widowed']))->toBe(['أرملة'])
        ->and(kindsOn($this->region, ['student']))->toBe(['طالب علم']);
});

it('says all of them when a file carries more than one', function () {
    expect(kindsOn($this->region, ['widowed', 'orphan', 'student']))
        ->toBe(['أرملة', 'يتيم', 'طالب علم']);
});

it('says nothing at all on a file nobody has classified', function () {
    expect(kindsOn($this->region, []))->toBe([])
        ->and(kindsOn($this->region, []))->toBeArray();
});

it('never prints a value that is not one of the association’s own keys', function () {
    // A key written straight into the column by hand, or left behind by an
    // older list, does not reach a donor's screen as a raw word.
    expect(kindsOn($this->region, ['orphan', 'nonsense_key']))->toBe(['يتيم']);
});

it('is closed by its own switch, like every other block a donor sees', function () {
    Setting::updateOrCreate(['key' => 'show_sponsorship_kind'], ['value_json' => false]);
    Setting::forgetLoaded();

    expect(kindsOn($this->region, ['orphan', 'widowed']))->toBe([]);
});

it('draws them on the card the donor actually reads', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['case_classifications' => ['orphan', 'student']])->save();

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee('يتيم')
        ->assertSee('طالب علم');
});

it('carries the association’s own key beside the word, so a price can be found', function () {
    // T-210 — «كل كفالة يظهر فيها مبلغها الشهري المحدد». A translated label
    // is not a key, and the price is held per key.
    $case = publishedCase($this->region);
    $case->forceFill(['case_classifications' => ['orphan', 'widowed']])->save();

    expect((new MaskedCaseResource($case->fresh()))->resolve()['sponsorship_kinds'])
        ->toBe(['orphan' => 'يتيم', 'widowed' => 'أرملة']);
});

it('keeps «طالب علم» in the list the association fills on the file', function () {
    expect(Beneficiary::CASE_CLASSIFICATIONS)->toContain('student')
        ->and(__('sanabel.case_classification.student'))->toBe('طالب علم');
});
