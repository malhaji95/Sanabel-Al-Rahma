<?php

use App\Http\Resources\MaskedCaseResource;
use App\Services\ScoreService;
use Carbon\Carbon;

/*
 | T-219 — «تصبح حالة عادية غير عاجلة», 1 November.
 |
 | A passed deadline used to score the top band for ever, and that was
 | deliberate: a need nobody answered does not stop being a need. But the
 | association reads the badge as a date rather than as a grievance, and a
 | file whose date went by two months ago was still flying the red warning
 | on the donor's page — which empties the word for the files that are
 | urgent today.
 |
 | So the date decides and nothing else. A researcher who still thinks the
 | case urgent writes a new date on the file, which is a judgement somebody
 | makes rather than one the calendar makes for them.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('bands a deadline that has gone by as not urgent at all', function () {
    expect(ScoreService::urgencyBand(Carbon::now()->subHour()))->toBe(0)
        ->and(ScoreService::urgencyBand(Carbon::now()->subDays(60)))->toBe(0);
});

it('still gives the top band to the two days before it', function () {
    expect(ScoreService::urgencyBand(Carbon::now()->addHours(10)))->toBe(100)
        ->and(ScoreService::urgencyBand(Carbon::now()->addHours(47)))->toBe(100);
});

it('leaves the bands between untouched', function () {
    expect(ScoreService::urgencyBand(Carbon::now()->addDays(5)))->toBe(75)
        ->and(ScoreService::urgencyBand(Carbon::now()->addDays(20)))->toBe(50)
        ->and(ScoreService::urgencyBand(Carbon::now()->addDays(60)))->toBe(25)
        ->and(ScoreService::urgencyBand(Carbon::now()->addDays(200)))->toBe(0);
});

it('says nothing on the card once the date is behind the family', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['urgency_deadline_at' => Carbon::now()->subDays(45)])->save();

    expect((new MaskedCaseResource($case->refresh()))->resolve()['urgency_label'])
        ->toBe(__('sanabel.masked.urgency.none'));
});

it('still shouts on the card while the date is ahead of them', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['urgency_deadline_at' => Carbon::now()->addHours(12)])->save();

    expect((new MaskedCaseResource($case->refresh()))->resolve()['urgency_label'])
        ->toBe(__('sanabel.masked.urgency.critical'));
});

it('shouts again the moment a researcher writes a new date on the file', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['urgency_deadline_at' => Carbon::now()->subDays(10)])->save();

    expect((new MaskedCaseResource($case->refresh()))->resolve()['urgency_label'])
        ->toBe(__('sanabel.masked.urgency.none'));

    // The way back is a decision somebody makes, not one the calendar makes.
    $case->forceFill(['urgency_deadline_at' => Carbon::now()->addDay()])->save();

    expect((new MaskedCaseResource($case->refresh()))->resolve()['urgency_label'])
        ->toBe(__('sanabel.masked.urgency.critical'));
});
