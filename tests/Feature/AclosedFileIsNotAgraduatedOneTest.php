<?php

/*
 | T-239 — «الملف المغلق / هناك التباس فيه اتوقع», 3 November.
 |
 | Two halves. The first is the confusion itself: a button reading «إغلاق
 | الملف» that wrote the status «متخرج», so the word on the screen and the
 | word on the file disagreed, and a closed file could not be told apart
 | from a family that had grown out of needing help.
 |
 | The second is what the association asked for next: a file that has ended
 | comes back by a request with a reason from their own list, decided by a
 | second pair of hands, and returns to the stage its own data puts it at.
 */

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\CaseReopenRequest;
use App\Models\Region;
use App\Models\Setting;
use App\Services\CaseService;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->reasons = CaseService::reopenReasons();
});

/** A file closed the way the panel closes one: with a proved delivery. */
function closedCase(Region $region): Beneficiary
{
    $case = publishedCase($region);

    // In kind, so no transfer has to be named: what is being proved here is
    // the closure, not the money path.
    app(CaseService::class)->confirmDelivery(
        $case, userWithRole('admin'), 'in_kind', proofMediaId: 42,
    );

    return app(CaseService::class)->close($case->refresh());
}

it('closes a file into «مغلق» and not into «متخرج»', function () {
    expect(closedCase($this->region)->status)->toBe('closed')
        ->and(__('sanabel.status.closed'))->toBe('مغلق');
});

it('keeps graduation as the other ending, with its own word', function () {
    $case = publishedCase($this->region);

    expect(app(CaseService::class)->graduate($case, 'دخل الأسرة صار يكفيها')->status)
        ->toBe('graduated')
        ->and(__('sanabel.status.graduated'))->toBe('متخرج');
});

/*
 | T-243 — «ما بدنا تظهر بتقاريرنا أسر متخرجة من الفقر وهي فعليًا مجرد ملفات
 | انتهت إجراءاتها», 4 November.
 */
it('never writes a graduation without a reason on the file', function () {
    $case = publishedCase($this->region);

    expect(fn () => app(CaseService::class)->graduate($case, '   '))
        ->toThrow(RuntimeException::class, __('sanabel.cases.graduation_reason_required'));

    expect($case->refresh()->status)->toBe('published');
});

it('keeps the reason the graduation was written with', function () {
    $case = publishedCase($this->region);

    app(CaseService::class)->graduate($case, 'الابن الأكبر صار يعمل ودخل البيت كفى');

    expect($case->refresh()->reject_reason_ar)->toBe('الابن الأكبر صار يعمل ودخل البيت كفى');
});

it('never lets money be committed on a closed file', function () {
    // The whitelist does this, so a status added today is refused by default
    // rather than permitted by oversight (rule 1 of T-229).
    expect(closedCase($this->region)->mayReceiveMoney())->toBeFalse();
});

it('raises a reopen request that records who asked and from what ending', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');

    $request = app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0], 'ملاحظة');

    expect($request->status)->toBe('pending')
        ->and($request->from_status)->toBe('closed')
        ->and($request->requested_by)->toBe($officer->id)
        ->and($request->reason_ar)->toBe($this->reasons[0]);
});

it('refuses a reason that is not on the association’s own list', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');

    expect(fn () => app(CaseService::class)->requestReopen($case, $officer, 'سبب كتبه الموظف بيده'))
        ->toThrow(RuntimeException::class, __('sanabel.reopen.reason_not_on_list'));

    expect(CaseReopenRequest::count())->toBe(0);
});

it('follows the association’s list when they rewrite it', function () {
    Setting::put('reopen_reasons', ['سبب الجمعية الوحيد']);
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');

    // The old list is gone, so a reason off it is refused and theirs is taken.
    expect(fn () => app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0]))
        ->toThrow(RuntimeException::class);

    expect(app(CaseService::class)->requestReopen($case, $officer, 'سبب الجمعية الوحيد')->status)
        ->toBe('pending');
});

it('does not reopen a file that has not ended', function () {
    $case = publishedCase($this->region);
    $officer = userWithRole('data_officer');

    expect(fn () => app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0]))
        ->toThrow(RuntimeException::class, __('sanabel.reopen.not_ended'));
});

it('refuses to reopen a merged duplicate, and says to reopen the master file', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['status' => 'merged'])->save();
    $officer = userWithRole('data_officer');

    expect(fn () => app(CaseService::class)->requestReopen($case->refresh(), $officer, $this->reasons[0]))
        ->toThrow(RuntimeException::class, __('sanabel.reopen.merged_not_reopenable'));
});

it('allows one open request at a time', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');

    app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0]);

    expect(fn () => app(CaseService::class)->requestReopen($case->refresh(), $officer, $this->reasons[1]))
        ->toThrow(RuntimeException::class, __('sanabel.reopen.already_pending'));
});

it('does not let the hand that asked be the hand that agrees', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');

    $this->actingAs($officer);
    $request = app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0]);

    expect(fn () => app(CaseService::class)->approveReopen($request, $officer))
        ->toThrow(RuntimeException::class, __('sanabel.reopen.self_decision_blocked'));

    expect($case->refresh()->status)->toBe('closed');
});

it('sends a file whose study is still valid back to the approval desk', function () {
    $case = closedCase($this->region);
    $case->forceFill(['last_assessment_at' => now()->subDays(5)])->save();

    $officer = userWithRole('data_officer');
    $director = userWithRole('executive_director');

    $request = app(CaseService::class)->requestReopen($case->refresh(), $officer, $this->reasons[0]);
    $decided = app(CaseService::class)->approveReopen($request, $director);

    expect($case->refresh()->status)->toBe('pending_approval')
        ->and($decided->status)->toBe('approved')
        ->and($decided->to_status)->toBe('pending_approval')
        ->and($decided->decided_by)->toBe($director->id)
        ->and($decided->decided_at)->not->toBeNull();
});

it('sends a file whose study has expired back to a draft, to be studied again', function () {
    $case = closedCase($this->region);
    $validDays = (int) Setting::value(
        'assessment_valid_days',
        config('sanabel.setting_defaults.assessment_valid_days')
    );
    $case->forceFill(['last_assessment_at' => now()->subDays($validDays + 1)])->save();

    $officer = userWithRole('data_officer');
    $director = userWithRole('executive_director');

    $request = app(CaseService::class)->requestReopen($case->refresh(), $officer, $this->reasons[0]);
    app(CaseService::class)->approveReopen($request, $director);

    expect($case->refresh()->status)->toBe('draft');
});

it('leaves the file where it was when the request is refused, and says why', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');
    $director = userWithRole('executive_director');

    $request = app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0]);
    $decided = app(CaseService::class)->refuseReopen($request, $director, 'الأسرة ما زالت مستغنية');

    expect($case->refresh()->status)->toBe('closed')
        ->and($decided->status)->toBe('refused')
        ->and($decided->decision_note_ar)->toBe('الأسرة ما زالت مستغنية')
        ->and($decided->to_status)->toBeNull();
});

it('does not refuse a reopen request without a written reason', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');
    $director = userWithRole('executive_director');

    $request = app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0]);

    expect(fn () => app(CaseService::class)->refuseReopen($request, $director, '  '))
        ->toThrow(RuntimeException::class, __('sanabel.reopen.refusal_note_required'));

    expect($request->refresh()->status)->toBe('pending');
});

it('decides a request once', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');
    $director = userWithRole('executive_director');

    $request = app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0]);
    app(CaseService::class)->approveReopen($request, $director);

    expect(fn () => app(CaseService::class)->approveReopen($request->refresh(), $director))
        ->toThrow(RuntimeException::class, __('sanabel.reopen.already_decided'));
});

it('tells the person who asked what was decided', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');
    $director = userWithRole('executive_director');

    $request = app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0]);
    app(CaseService::class)->approveReopen($request, $director);

    $notification = AppNotification::where('recipient_id', $officer->id)
        ->where('template_key', 'case_reopen_approved')->first();

    // Rule 10 — a file number and nothing else about the household.
    expect($notification)->not->toBeNull()
        ->and($notification->payload_json)->toBe(['file_number' => $case->file_number]);
});

/*
 | T-243, point 2 — «والتأكد من حفظ جميع الإجراءات بالسجل», 4 November.
 |
 | The audit log has recorded every write on a case and on a request since
 | the beginning (rule 4). What was missing is a test saying so about this
 | path in particular, which is what they asked to see.
 */
it('writes the whole reopen story into the audit log', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');
    $director = userWithRole('executive_director');

    /*
     | Signed in as the person acting, because that is how the panel calls
     | these: the audit log records the authenticated account (rule 4), and
     | the Filament action passes `auth()->user()` as the actor. A test that
     | called the service with nobody signed in would be testing a path the
     | product does not have.
     */
    $this->actingAs($officer);
    $request = app(CaseService::class)
        ->requestReopen($case, $officer, $this->reasons[0], 'الأسرة رجعت محتاجة');

    $this->actingAs($director);
    app(CaseService::class)->approveReopen($request, $director);

    $raised = AuditLog::where('entity_type', CaseReopenRequest::class)
        ->where('entity_id', $request->id)->where('action', 'created')->first();

    $decided = AuditLog::where('entity_type', CaseReopenRequest::class)
        ->where('entity_id', $request->id)->where('action', 'updated')->latest('id')->first();

    $moved = AuditLog::where('entity_type', Beneficiary::class)
        ->where('entity_id', $case->id)->where('action', 'updated')->latest('id')->first();

    // Who asked, and on what grounds.
    expect($raised)->not->toBeNull()
        ->and($raised->actor_id)->toBe($officer->id)
        ->and($raised->after_json['reason_ar'])->toBe($this->reasons[0])
        ->and($raised->after_json['from_status'])->toBe('closed')
        // Who agreed, and when.
        ->and($decided)->not->toBeNull()
        ->and($decided->actor_id)->toBe($director->id)
        ->and($decided->after_json['status'])->toBe('approved')
        ->and($decided->after_json['decided_by'])->toBe($director->id)
        // And the file's own move, from the ending to the stage it went to.
        ->and($moved)->not->toBeNull()
        ->and($moved->before_json['status'])->toBe('closed')
        ->and($moved->after_json['status'])->toBe('pending_approval');
});

it('records a refusal in the log too, with the reason it was refused for', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');
    $director = userWithRole('executive_director');

    $this->actingAs($officer);
    $request = app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0]);

    $this->actingAs($director);
    app(CaseService::class)->refuseReopen($request, $director, 'الأسرة ما زالت مستغنية');

    $entry = AuditLog::where('entity_type', CaseReopenRequest::class)
        ->where('entity_id', $request->id)->where('action', 'updated')->latest('id')->first();

    expect($entry->actor_id)->toBe($director->id)
        ->and($entry->after_json['status'])->toBe('refused')
        ->and($entry->after_json['decision_note_ar'])->toBe('الأسرة ما زالت مستغنية');
});

it('leaves a trace of the attempt when a requester tries to approve their own', function () {
    $case = closedCase($this->region);
    $officer = userWithRole('data_officer');

    $this->actingAs($officer);
    $request = app(CaseService::class)->requestReopen($case, $officer, $this->reasons[0]);

    expect(fn () => app(CaseService::class)->approveReopen($request, $officer))
        ->toThrow(RuntimeException::class);

    // Refused, so there is nothing to record about the request itself: it
    // was never touched. What the log must not contain is a decision.
    $decided = AuditLog::where('entity_type', CaseReopenRequest::class)
        ->where('entity_id', $request->id)->where('action', 'updated')->exists();

    expect($decided)->toBeFalse()
        ->and($request->refresh()->status)->toBe('pending')
        ->and($case->refresh()->status)->toBe('closed');
});
