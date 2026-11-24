<?php

use App\Models\AppNotification;
use App\Services\CaseService;
use App\Services\ConflictOfInterest;

/*
 | T-325 — «لما المندوب يحدد وجود تضارب مصالح أثناء تسجيل عائلة, التسجيل
 | بيتوقف. السؤال: وين بيروح الطلب بعدها؟», the association, 24 November.
 |
 | The rule already worked the way they asked for: the family is registered
 | and the file is saved, and it is the delegate who may not sign it off. The
 | way out existed too — the area's supervisor, their deputy and any other
 | delegate in scope all hold the field sign-off.
 |
 | What was missing is that nobody was told. The file sat in «بانتظار زيارة
 | المندوب» under the name of the very person who may not touch it, looking
 | like every other file waiting for a visit. A way out nobody is told about
 | is, to the person waiting, no way out at all.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->area = placeIn($this->region, 'area');
    $this->village = placeIn($this->area, 'village');

    $this->delegate = userWithRole('delegate', ['region_id' => $this->village->id]);
    $this->supervisor = userWithRole('area_supervisor', ['region_id' => $this->area->id]);

    $this->case = familyOf($this->village, adults: 1, children: 0);
    $this->case->forceFill(['created_by' => $this->delegate->id, 'status' => 'pending_visit'])->save();
});

it('registers the family all the same: the bar is on the hand, not on the household', function () {
    /*
     | Their own sentence: «تضارب المصالح لازم يمنع المندوب من معالجة الملف,
     | وليس العائلة من التسجيل». This is the test that says so.
     */
    app(ConflictOfInterest::class)->declare($this->case, $this->delegate, true, 'other_kin', 'ابن عمي.');

    $this->case->refresh();

    expect($this->case->exists)->toBeTrue()
        ->and($this->case->status)->toBe('pending_visit')
        ->and($this->case->deleted_at)->toBeNull()
        // And it is still in the queue it was in, waiting for a visit.
        ->and(in_array($this->case->status, CaseService::AWAITING_FIELD, true))->toBeTrue();
});

it('refuses the sign-off to the hand that declared the tie', function () {
    app(ConflictOfInterest::class)->declare($this->case, $this->delegate, true, 'other_kin', 'ابن عمي.');

    expect(fn () => app(CaseService::class)->verifyInField($this->case->refresh(), $this->delegate, 'زرتها.'))
        ->toThrow(RuntimeException::class);

    expect($this->case->refresh()->field_verified_by)->toBeNull();
});

it('tells the supervisors of that area that the file needs another hand', function () {
    $deputy = userWithRole('deputy_area_supervisor', ['region_id' => $this->area->id]);

    // A supervisor of some other area hears nothing: this is not everyone's
    // business, it is the business of whoever covers this file.
    $elsewhere = userWithRole('area_supervisor', [
        'region_id' => placeIn(regionWithRates(), 'area')->id,
    ]);

    app(ConflictOfInterest::class)->declare($this->case, $this->delegate, true, 'other_kin', 'ابن عمي.');

    foreach ([$this->supervisor, $deputy] as $told) {
        expect(AppNotification::where('recipient_id', $told->id)
            ->where('template_key', 'case_needs_another_hand')->exists())
            ->toBeTrue("{$told->name} was not told");
    }

    expect(AppNotification::where('recipient_id', $elsewhere->id)
        ->where('template_key', 'case_needs_another_hand')->exists())->toBeFalse();
});

it('carries the file number and nothing of the family or the tie', function () {
    /*
     | Rule 10. That a file needs another hand is what the supervisor acts on;
     | why is the oversight committee's to read on the file itself.
     */
    app(ConflictOfInterest::class)->declare(
        $this->case,
        $this->delegate,
        true,
        'other_kin',
        'ابن عمي وأعرف بيتهم.',
    );

    $notice = AppNotification::where('recipient_id', $this->supervisor->id)
        ->where('template_key', 'case_needs_another_hand')->first();

    $written = json_encode($notice->payload_json, JSON_UNESCAPED_UNICODE);

    expect($written)->toContain($this->case->file_number)
        ->and($written)->not->toContain('ابن عمي')
        ->and($written)->not->toContain('other_kin')
        ->and($written)->not->toContain($this->case->first_name);
});

it('says nothing when the answer is that there is no tie', function () {
    app(ConflictOfInterest::class)->declare($this->case, $this->delegate, false);

    expect(AppNotification::where('template_key', 'case_needs_another_hand')->count())->toBe(0);
});

it('lets the supervisor take the file, once they have answered for themselves', function () {
    /*
     | The whole way out, end to end. The supervisor is asked the same
     | question first: T-305 put it before every decision, not after a
     | suspicion.
     */
    app(ConflictOfInterest::class)->declare($this->case, $this->delegate, true, 'other_kin', 'ابن عمي.');

    // Before answering, even the supervisor is stopped.
    expect(fn () => app(CaseService::class)->verifyInField($this->case->refresh(), $this->supervisor, 'زرتها.'))
        ->toThrow(RuntimeException::class, __('sanabel.conflict.must_declare'));

    app(ConflictOfInterest::class)->declare($this->case->refresh(), $this->supervisor, false);

    app(CaseService::class)->verifyInField($this->case->refresh(), $this->supervisor, 'زرتها بنفسي.');

    $this->case->refresh();

    expect($this->case->status)->toBe('verified')
        ->and($this->case->field_verified_by)->toBe($this->supervisor->id);
});
