<?php

use App\Models\Setting;

/*
 | T-160 — «يقبل الزيارة نعم لا ونقدر نوقفها او نفعلها», 30 October.
 |
 | The dossier asks the family directly and the answer is theirs. T-69 decided
 | that no answer is not a yes, so the row reads «لا» for a family that has
 | not agreed rather than going quiet about it. The switch takes the row off
 | every page at once.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('says yes only where the family said yes', function () {
    $case = publishedCase($this->region);

    $case->forceFill(['donor_visit_consent' => true])->save();
    expect(maskedOf($case)['accepts_visit'])->toBeTrue();

    $case->forceFill(['donor_visit_consent' => false])->save();
    expect(maskedOf($case)['accepts_visit'])->toBeFalse();

    // No answer is not a yes.
    $case->forceFill(['donor_visit_consent' => null])->save();
    expect(maskedOf($case)['accepts_visit'])->toBeFalse();
});

it('takes the row off the page when the association closes the switch', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['donor_visit_consent' => true])->save();

    Setting::put('show_visit_consent', false);

    expect(maskedOf($case)['accepts_visit'])->toBeNull();

    $body = $this->get(route('opportunity', $case->file_number))->assertOk()->getContent();

    expect($body)->not->toContain(__('sanabel.masked.accepts_visit'));
});

it('draws the row on the family’s own page', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['donor_visit_consent' => true])->save();

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.masked.accepts_visit'));
});

it('says nothing about when the visit was agreed', function () {
    $case = publishedCase($this->region);
    $case->forceFill([
        'donor_visit_consent' => true,
        'donor_visit_consent_on' => '2026-03-04',
    ])->save();

    expect(json_encode(maskedOf($case), JSON_UNESCAPED_UNICODE))->not->toContain('2026-03-04')
        ->and(array_keys(maskedOf($case)))->not->toContain('donor_visit_consent_on');
});
