<?php

use App\Models\Setting;
use App\Services\DonorCaseList;
use Illuminate\Support\Facades\Cache;

/*
 | T-208 — «وموجود فلتر للكفلات», 1 November.
 |
 | T-205 wrote the kind of sponsorship on the card; this is the other half of
 | it. A donor who came to sponsor an orphan was reading the whole list to
 | find one.
 |
 | The doors offered are data rather than code: the classifications actually
 | written on published files, in the association's own order. So a door the
 | association stops using leaves the row on its own, and a door a donor is
 | shown always has somebody behind it.
 |
 | And the filter is the badge's disclosure, not a lesser one: to narrow the
 | list to «يتيم» and be shown a family is to be told that family is an orphan
 | file. Rule 2, so one switch closes both.
 |
 | T-228 moved the row itself off the list of cases and onto a page of its
 | own. What is tested here is the doors and what they narrow; the page is
 | tested in SponsorshipsOnTheirOwnPageTest.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Cache::flush();
});

/** A published file carrying the association's classifications. */
function classifiedCase($region, array $classifications)
{
    $case = publishedCase($region);
    $case->forceFill(['case_classifications' => $classifications])->save();

    return $case->refresh();
}

/** The file numbers a page of the donor's list shows for this door. */
function fileNumbersForKind(?string $kind): array
{
    Cache::flush();

    return collect(app(DonorCaseList::class)
        ->page('all', null, 1, 50, withCovered: true, kinds: $kind === null ? [] : [$kind])['cards'])
        ->pluck('file_number')
        ->all();
}

it('offers only the doors somebody is actually behind', function () {
    classifiedCase($this->region, ['orphan']);
    classifiedCase($this->region, ['widowed', 'student']);

    // Of the association's eight, these three are written on a file. The rest
    // are not offered, so no chip leads to an empty grid.
    expect(array_keys(app(DonorCaseList::class)->kinds()))
        ->toBe(['orphan', 'widowed', 'student']);
});

it('offers them in the association’s own order, not the order files were written', function () {
    classifiedCase($this->region, ['student']);
    classifiedCase($this->region, ['poor']);
    classifiedCase($this->region, ['orphan']);

    expect(array_keys(app(DonorCaseList::class)->kinds()))
        ->toBe(['poor', 'orphan', 'student']);
});

it('offers nothing when no published file is classified at all', function () {
    publishedCase($this->region);

    expect(app(DonorCaseList::class)->kinds())->toBe([]);
});

it('narrows the list to the door a donor chose', function () {
    $orphan = classifiedCase($this->region, ['orphan']);
    $widow = classifiedCase($this->region, ['widowed']);

    expect(fileNumbersForKind('orphan'))->toBe([$orphan->file_number])
        ->and(fileNumbersForKind('widowed'))->toBe([$widow->file_number]);
});

it('keeps a family that carries the door among several', function () {
    $both = classifiedCase($this->region, ['widowed', 'orphan', 'student']);
    classifiedCase($this->region, ['poor']);

    expect(fileNumbersForKind('orphan'))->toBe([$both->file_number]);
});

it('shows every family when no door is chosen', function () {
    $a = classifiedCase($this->region, ['orphan']);
    $b = classifiedCase($this->region, ['poor']);

    expect(fileNumbersForKind(null))->toHaveCount(2)
        ->and(fileNumbersForKind(null))->toContain($a->file_number, $b->file_number);
});

it('ignores a door typed into the query string that the association never declared', function () {
    $case = classifiedCase($this->region, ['orphan']);

    // Not «no families match»: the question was never a question, so the
    // list answers the one it was asked before the filter.
    expect(fileNumbersForKind('nonsense_key'))->toBe([$case->file_number]);
});

it('ignores a real classification that no published file carries', function () {
    $case = classifiedCase($this->region, ['orphan']);

    expect(fileNumbersForKind('debtor'))->toBe([$case->file_number]);
});

it('closes the filter with the same switch that closes the words on the card', function () {
    classifiedCase($this->region, ['orphan']);
    $widow = classifiedCase($this->region, ['widowed']);

    Setting::updateOrCreate(['key' => 'show_sponsorship_kind'], ['value_json' => false]);
    Setting::forgetLoaded();

    // No chips, and no filtering either: a donor cannot narrow their way to a
    // classification the association has stopped publishing.
    expect(app(DonorCaseList::class)->kinds())->toBe([])
        ->and(fileNumbersForKind('widowed'))->toHaveCount(2)
        ->and(fileNumbersForKind('widowed'))->toContain($widow->file_number);
});

it('keeps every door it is given, so a family behind two is listed once', function () {
    $both = classifiedCase($this->region, ['orphan', 'widowed']);
    $student = classifiedCase($this->region, ['student']);

    // T-228 — the sponsorships page asks about all the doors at once.
    $numbers = collect(app(DonorCaseList::class)
        ->page('all', null, 1, 50, withCovered: true, kinds: ['orphan', 'widowed', 'student'])['cards'])
        ->pluck('file_number');

    expect($numbers)->toHaveCount(2)
        ->and($numbers)->toContain($both->file_number, $student->file_number);
});

it('offers only the classifications the association called sponsorships', function () {
    classifiedCase($this->region, ['orphan']);
    classifiedCase($this->region, ['poor', 'needy']);

    // Every door is a classification; not every classification is a door.
    expect(array_keys(app(DonorCaseList::class)->kinds()))
        ->toBe(['poor', 'needy', 'orphan'])
        ->and(array_keys(app(DonorCaseList::class)->sponsorshipDoors()))
        ->toBe(['orphan']);
});
