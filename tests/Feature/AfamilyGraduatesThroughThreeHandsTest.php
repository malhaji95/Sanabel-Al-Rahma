<?php

/*
 | T-249 — the association's graduation policy, 4 November, clause by
 | clause:
 |
 |   المندوب الميداني بيعمل دراسة حديثة … مشرف المنطقة بيراجع … المدير
 |   التنفيذي هو صاحب صلاحية اعتماد التخرج النهائي … ما بيتم التخرج إلا إذا
 |   ثبت أن الأسرة صار عندها دخل كافٍ ومستقر … استقرار الدخل مثبت لمدة
 |   ثلاثة أشهر على الأقل، وتكون المدة قابلة للتعديل من الإعدادات … السبب
 |   والدراسة والتوصية والقرار كلها بتنحفظ بسجل التدقيق … مجرد تسليم مشروع
 |   تنموي أو إغلاق معاملة ما بيعني أن الأسرة تخرجت من الفقر.
 */

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\GraduationRequest;
use App\Models\Setting;
use App\Services\CaseService;
use App\Services\CoverageService;
use App\Services\GraduationService;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->family = publishedCase($this->region);
    $this->need = app(CoverageService::class)->needAmount($this->family);

    $this->delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $this->supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);
    $this->director = userWithRole('executive_director');
});

/** A study that clears both bars: enough income, steady long enough. */
function soundStudy(array $overrides = []): GraduationRequest
{
    return app(GraduationService::class)->study(
        test()->family,
        $overrides['delegate'] ?? test()->delegate,
        $overrides['income'] ?? test()->need + 50_000,
        $overrides['since'] ?? now()->subMonths(4),
        $overrides['note'] ?? 'الابن الأكبر صار موظفاً بعقد دائم, والدخل ثابت منذ أربعة أشهر.',
    );
}

it('starts with the delegate’s study and waits on the supervisor', function () {
    $request = soundStudy();

    expect($request->status)->toBe('pending_supervisor')
        ->and($request->requested_by)->toBe($this->delegate->id)
        // The family's own need is written on the row, so the judgement can
        // be read again years later against the figure it was taken on.
        ->and($request->need_snapshot)->toBe($this->need)
        ->and($request->incomeIsSufficient())->toBeTrue()
        // And the file has not moved: a study is not a decision.
        ->and($this->family->refresh()->status)->toBe('published');
});

it('refuses a study whose income does not cover the family’s need', function () {
    expect(fn () => soundStudy(['income' => max(1, $this->need - 1)]))
        ->toThrow(RuntimeException::class);

    expect(GraduationRequest::count())->toBe(0);
});

it('refuses a study whose income has not been steady long enough', function () {
    // Two months, where the association asks for three.
    expect(fn () => soundStudy(['since' => now()->subMonths(2)]))
        ->toThrow(RuntimeException::class, __('sanabel.graduation.not_stable_long_enough', ['months' => 3]));

    expect(GraduationRequest::count())->toBe(0);
});

it('follows the settings when the association changes the months', function () {
    Setting::put('graduation_stable_months', 6);

    expect(GraduationService::stableMonths())->toBe(6)
        ->and(fn () => soundStudy(['since' => now()->subMonths(4)]))
        ->toThrow(RuntimeException::class);

    Setting::put('graduation_stable_months', 3);

    expect(soundStudy(['since' => now()->subMonths(4)])->status)->toBe('pending_supervisor');
});

it('will not let the hand that studied be the hand that recommends', function () {
    /*
     | With the association's own matrix the delegate is stopped by the
     | first rule — recommending is the supervisor's key, not theirs. The
     | second test below reaches the self-review rule itself, by giving one
     | account both keys the way the permission screen allows.
     */
    $request = soundStudy();

    expect(fn () => app(GraduationService::class)
        ->recommend($request, $this->delegate, 'support', 'أوافق'))
        ->toThrow(RuntimeException::class);

    expect($request->refresh()->status)->toBe('pending_supervisor');
});

it('refuses a supervisor recommending on a study they raised themselves', function () {
    $both = userWithRole('area_supervisor', ['region_id' => $this->region->id]);

    $request = app(GraduationService::class)->study(
        $this->family, $both, $this->need + 50_000, now()->subMonths(4), 'درستُها بنفسي.',
    );

    expect(fn () => app(GraduationService::class)->recommend($request, $both, 'support', 'أوافق'))
        ->toThrow(RuntimeException::class, __('sanabel.graduation.self_review_blocked'));

    expect($request->refresh()->status)->toBe('pending_supervisor');
});

it('takes the supervisor’s recommendation and passes it to the director', function () {
    $request = soundStudy();

    $reviewed = app(GraduationService::class)
        ->recommend($request, $this->supervisor, 'support', 'زرتُ الأسرة والدخل كما وُصف.');

    expect($reviewed->status)->toBe('pending_director')
        ->and($reviewed->reviewed_by)->toBe($this->supervisor->id)
        ->and($reviewed->recommendation)->toBe('support')
        ->and($reviewed->reviewed_at)->not->toBeNull();
});

it('carries an objection to the director rather than ending there', function () {
    $request = soundStudy();

    $reviewed = app(GraduationService::class)
        ->recommend($request, $this->supervisor, 'object', 'العمل موسمي ولا أراه مستقراً.');

    // The objection does not decide; it is in front of the director when
    // they do.
    expect($reviewed->status)->toBe('pending_director')
        ->and($reviewed->recommendation)->toBe('object');
});

it('refuses a recommendation with no reasoning behind it', function () {
    $request = soundStudy();

    expect(fn () => app(GraduationService::class)
        ->recommend($request, $this->supervisor, 'support', '  '))
        ->toThrow(RuntimeException::class, __('sanabel.graduation.recommendation_note_required'));
});

it('will not let the director decide before the supervisor has read it', function () {
    $request = soundStudy();

    expect(fn () => app(GraduationService::class)->approve($request, $this->director))
        ->toThrow(RuntimeException::class, __('sanabel.graduation.not_awaiting_decision'));

    expect($this->family->refresh()->status)->toBe('published');
});

it('will not let either earlier hand be the deciding one', function () {
    $request = soundStudy();
    app(GraduationService::class)->recommend($request, $this->supervisor, 'support', 'موافق');

    /*
     | Two rules stand between them and the decision, and with the
     | association's own matrix the first one answers: neither the delegate
     | nor the supervisor holds the director's key, so «ليست خطوتك» is what
     | they are told. `self_decision_blocked` is the second line, for a
     | matrix that was edited to give somebody two of the three steps — the
     | permission screen allows exactly that, which is why the rule stays.
     */
    foreach ([$this->delegate, $this->supervisor] as $earlier) {
        expect(fn () => app(GraduationService::class)->approve($request->refresh(), $earlier))
            ->toThrow(RuntimeException::class);
    }

    expect($this->family->refresh()->status)->toBe('published')
        ->and($request->refresh()->status)->toBe('pending_director');
});

it('refuses a director who had already acted on the same study', function () {
    // The matrix edited so that one account holds two of the three steps.
    $both = userWithRole('executive_director');
    grantPermission($both, 'record_visit');

    $request = app(GraduationService::class)->study(
        $this->family, $both, $this->need + 50_000, now()->subMonths(4), 'درستُها بنفسي.',
    );

    app(GraduationService::class)->recommend($request, $this->supervisor, 'support', 'موافق');

    expect(fn () => app(GraduationService::class)->approve($request->refresh(), $both))
        ->toThrow(RuntimeException::class, __('sanabel.graduation.self_decision_blocked'));

    expect($this->family->refresh()->status)->toBe('published');
});

it('will not take a study from an account that does not work in the field', function () {
    $director = userWithRole('executive_director');

    expect(fn () => app(GraduationService::class)->study(
        $this->family, $director, $this->need + 50_000, now()->subMonths(4), 'دراسة',
    ))->toThrow(RuntimeException::class, __('sanabel.graduation.not_the_fields_step'));
});

it('graduates the family when the director approves, and only then', function () {
    $request = soundStudy();
    app(GraduationService::class)->recommend($request, $this->supervisor, 'support', 'موافق');

    $decided = app(GraduationService::class)->approve($request->refresh(), $this->director);

    expect($decided->status)->toBe('approved')
        ->and($decided->decided_by)->toBe($this->director->id)
        ->and($this->family->refresh()->status)->toBe('graduated')
        // The reason is on the file, carrying the two figures it rested on.
        ->and($this->family->reject_reason_ar)->toContain('دخل شهري');
});

it('checks the bars again at the decision, not only at the study', function () {
    $request = soundStudy();
    app(GraduationService::class)->recommend($request, $this->supervisor, 'support', 'موافق');

    // The association raises the bar between the study and the decision.
    Setting::put('graduation_stable_months', 12);

    expect(fn () => app(GraduationService::class)->approve($request->refresh(), $this->director))
        ->toThrow(RuntimeException::class);

    expect($this->family->refresh()->status)->toBe('published');
});

it('leaves the file alone when the director refuses, and says why', function () {
    $request = soundStudy();
    app(GraduationService::class)->recommend($request, $this->supervisor, 'support', 'موافق');

    $decided = app(GraduationService::class)
        ->refuse($request->refresh(), $this->director, 'أريد دراسة بعد ستة أشهر.');

    expect($decided->status)->toBe('refused')
        ->and($decided->decision_note_ar)->toBe('أريد دراسة بعد ستة أشهر.')
        ->and($this->family->refresh()->status)->toBe('published');
});

it('allows one study at a time on a file', function () {
    soundStudy();

    expect(fn () => soundStudy())
        ->toThrow(RuntimeException::class, __('sanabel.graduation.already_pending'));
});

it('writes the study, the recommendation and the decision into the audit log', function () {
    $this->actingAs($this->delegate);
    $request = soundStudy();

    $this->actingAs($this->supervisor);
    app(GraduationService::class)->recommend($request, $this->supervisor, 'support', 'زرتُ الأسرة.');

    $this->actingAs($this->director);
    app(GraduationService::class)->approve($request->refresh(), $this->director);

    $entries = AuditLog::where('entity_type', GraduationRequest::class)
        ->where('entity_id', $request->id)->orderBy('id')->get();

    $study = $entries->firstWhere('action', 'created');
    $decision = $entries->where('action', 'updated')->last();

    expect($study->actor_id)->toBe($this->delegate->id)
        ->and($study->after_json['study_note_ar'])->toContain('الابن الأكبر')
        ->and($study->after_json['monthly_income'])->toBe($this->need + 50_000)
        ->and($decision->actor_id)->toBe($this->director->id)
        ->and($decision->after_json['status'])->toBe('approved')
        // And the file's own move, with the reason on it.
        ->and(AuditLog::where('entity_type', Beneficiary::class)
            ->where('entity_id', $this->family->id)
            ->where('action', 'updated')->latest('id')->first()
            ->after_json['status'])->toBe('graduated');
});

it('does not graduate a family because its file was closed', function () {
    // «مجرد تسليم مشروع تنموي أو إغلاق معاملة ما بيعني أن الأسرة تخرجت من
    // الفقر». Closing is the other ending, and it says nothing about need.
    app(CaseService::class)->confirmDelivery(
        $this->family, userWithRole('admin'), 'in_kind', proofMediaId: 42,
    );

    expect(app(CaseService::class)->close($this->family->refresh())->status)
        ->toBe('closed')
        ->and(GraduationRequest::count())->toBe(0);
});

it('lets a graduated family be looked at again through the reopen path', function () {
    $request = soundStudy();
    app(GraduationService::class)->recommend($request, $this->supervisor, 'support', 'موافق');
    app(GraduationService::class)->approve($request->refresh(), $this->director);

    // «إذا تغير وضع الأسرة بعد التخرج … إمكانية لطلب إعادة دراسة
    // استحقاقها», and «اكتشاف أشياء … تم أخفاءها» is one of the reasons.
    $reasons = CaseService::reopenReasons();

    expect(in_array('اكتشاف معلومات أُخفيت أثناء الدراسة الميدانية', $reasons, true))->toBeTrue();

    $reopen = app(CaseService::class)->requestReopen(
        $this->family->refresh(), $this->delegate, $reasons[0],
    );

    expect($reopen->from_status)->toBe('graduated')
        ->and($reopen->status)->toBe('pending');
});

/*
 | T-250 — the step belongs to the role the association named, and the
 | service says so as well as the screen.
 */
it('will not take a recommendation from somebody who is not the supervisor', function () {
    $request = soundStudy();
    $anotherDelegate = userWithRole('delegate', ['region_id' => $this->region->id]);

    expect(fn () => app(GraduationService::class)
        ->recommend($request, $anotherDelegate, 'support', 'أرى أنها تخرجت'))
        ->toThrow(RuntimeException::class, __('sanabel.graduation.not_the_supervisors_step'));

    expect($request->refresh()->status)->toBe('pending_supervisor');
});

it('will not take the decision from anybody but the executive director', function () {
    $request = soundStudy();
    app(GraduationService::class)->recommend($request, $this->supervisor, 'support', 'موافق');

    $otherSupervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);

    expect(fn () => app(GraduationService::class)->approve($request->refresh(), $otherSupervisor))
        ->toThrow(RuntimeException::class, __('sanabel.graduation.not_the_directors_step'));

    expect($this->family->refresh()->status)->toBe('published');
});
