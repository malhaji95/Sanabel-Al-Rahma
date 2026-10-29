<?php

use App\Http\Resources\MaskedCaseResource;

/*
 | T-192 — «ظهر على الحالة المضافة جديد: حالة طارئة. هذا شلون ظهرت على أي
 | أساس؟», 29 October, over a card carrying the red marker.
 |
 | The answer is «الموعد النهائي للحاجة العاجلة» on the file, and the basis
 | was being misreported: the card printed «حالة طارئة» for anything inside a
 | week, because the word was written into the view instead of being taken
 | from the band the resource already computes.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** The words a donor reads on a file whose deadline is this far off. */
function markOn(\App\Models\Region $region, ?string $deadline): array
{
    $case = publishedCase($region);
    $case->forceFill(['urgency_deadline_at' => $deadline])->save();

    return (new MaskedCaseResource($case->fresh()))->resolve();
}

it('says nothing at all when no deadline was recorded', function () {
    expect(markOn($this->region, null)['is_urgent'])->toBeFalse();
});

it('calls the next two days «حالة طارئة»', function () {
    $mark = markOn($this->region, now()->addHours(10)->toDateTimeString());

    expect($mark['is_urgent'])->toBeTrue()
        ->and($mark['urgency_label'])->toBe(__('sanabel.masked.urgency.critical'));
});

it('calls the week after it «أولوية عالية», which is what it is', function () {
    $mark = markOn($this->region, now()->addDays(4)->toDateTimeString());

    expect($mark['is_urgent'])->toBeTrue()
        // The marker still shows — a week out is worth marking — but it no
        // longer calls a week an emergency.
        ->and($mark['urgency_label'])->toBe(__('sanabel.masked.urgency.high'));
});

it('leaves a month off the marker entirely', function () {
    expect(markOn($this->region, now()->addDays(20)->toDateTimeString())['is_urgent'])->toBeFalse();
});

it('writes on the card the word the band gives it, not a word of its own', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['urgency_deadline_at' => now()->addDays(4)->toDateTimeString()])->save();

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.masked.urgency.high'))
        ->assertDontSee(__('sanabel.masked.urgency.critical'));
});
