<?php

use App\Models\AuditLog;
use App\Models\ConflictDisclosure;
use App\Models\ConflictWaiver;
use App\Services\CaseService;
use App\Services\ConflictOfInterest;

/*
 | T-305 — «عدم وجود آلية لمنع تضارب المصالح», 12 November, which the
 | association marked as the gravest of the six.
 |
 | The file already refused two things: a supervisor may not endorse a visit
 | they made themselves, and an approver may not approve a file they created.
 | Both guard a person against their own hand. Neither says anything about
 | the hand of a cousin.
 |
 | Their acceptance test: «تسجيل حالة فيها صلة قرابة بين الباحث والأسرة،
 | والتأكد من تفعيل إجراءات الإفصاح والتحويل ومنع الاعتماد غير المستقل».
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->case = familyOf($this->region, adults: 2, children: 1);
    $this->conflict = app(ConflictOfInterest::class);
    $this->cases = app(CaseService::class);
});

it('asks before it lets anybody decide, and refuses while unanswered', function () {
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);

    expect($this->conflict->mustDeclare($this->case, $officer))->toBeTrue();

    expect(fn () => $this->conflict->guard($this->case, $officer, 'approve'))
        ->toThrow(RuntimeException::class, __('sanabel.conflict.must_declare'));
});

it('lets a person with no tie get on with it, once they have said so', function () {
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);

    $this->conflict->declare($this->case, $officer, hasRelationship: false);

    expect($this->conflict->isBarred($this->case, $officer))->toBeFalse()
        ->and(fn () => $this->conflict->guard($this->case, $officer, 'approve'))
        ->not->toThrow(RuntimeException::class);
});

it('bars the cousin from deciding, and names the act it bars them from', function () {
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);

    $this->conflict->declare(
        $this->case, $officer,
        hasRelationship: true, relationType: 'close_kin', note: 'ابن خالتي',
    );

    expect($this->conflict->isBarred($this->case, $officer))->toBeTrue()
        ->and(fn () => $this->conflict->guard($this->case, $officer, 'approve'))
        ->toThrow(RuntimeException::class, __('sanabel.conflict.acts.approve'));
});

it('still lets the cousin record what they saw, because collecting is not deciding', function () {
    /*
     | «فصل جمع المعلومات عن التحقق والاعتماد». A stated tie takes a person
     | off the deciding of a file and off nothing else: refusing them the
     | recording as well would simply mean nobody declares.
     */
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $this->case->forceFill(['created_by' => $delegate->getKey(), 'status' => 'draft'])->save();

    // What they could do with the file before saying anything.
    $before = $delegate->can('update', $this->case->refresh());

    $this->conflict->declare($this->case, $delegate, hasRelationship: true, relationType: 'other_kin');

    expect($before)->toBeTrue()
        // Declaring a tie changes nothing about recording.
        ->and($delegate->can('update', $this->case->refresh()))->toBe($before)
        // The guarded acts are the three decisions, and no more than those.
        ->and(ConflictOfInterest::GUARDED_ACTS)->toBe(['verify', 'endorse', 'approve']);
});

it('refuses a declared tie that does not say what kind of tie it is', function () {
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);

    expect(fn () => $this->conflict->declare($this->case, $officer, hasRelationship: true))
        ->toThrow(RuntimeException::class, __('sanabel.conflict.relation_required'));
});

it('stops the file at the approval gate itself, not only in the service', function () {
    /*
     | The guard has to sit where the decision is made. A rule that only the
     | screen honours is a rule a script walks past.
     */
    $this->case->forceFill(['status' => 'pending_approval'])->save();

    $approver = userWithRole('executive_director', ['region_id' => $this->region->id]);
    $this->conflict->declare($this->case, $approver, hasRelationship: true, relationType: 'business');

    expect(fn () => $this->cases->approve($this->case->refresh(), $approver))
        ->toThrow(RuntimeException::class);

    expect($this->case->refresh()->status)->toBe('pending_approval');
});

it('bars refusing a file as well as approving one', function () {
    // Saying «no» on a stranger's file for a cousin's sake is the same
    // conflict read the other way round.
    $this->case->forceFill(['status' => 'pending_approval'])->save();

    $approver = userWithRole('executive_director', ['region_id' => $this->region->id]);
    $this->conflict->declare($this->case, $approver, hasRelationship: true, relationType: 'financial');

    expect(fn () => $this->cases->reject($this->case->refresh(), $approver, 'سبب'))
        ->toThrow(RuntimeException::class);
});

it('lifts the bar only for a key of its own, held by somebody else, with a reason', function () {
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->conflict->declare($this->case, $officer, hasRelationship: true, relationType: 'close_kin');

    // Somebody who merely approves files does not hold the key.
    expect(fn () => $this->conflict->waive($this->case, $officer, $officer, 'لا يوجد غيري'))
        ->toThrow(RuntimeException::class, __('sanabel.conflict.waiver_not_permitted'));

    $director = userWithRole('executive_director', ['region_id' => $this->region->id]);

    // Nor does anybody lift it for themselves.
    $this->conflict->declare($this->case, $director, hasRelationship: true, relationType: 'other_kin');
    expect(fn () => $this->conflict->waive($this->case, $director, $director, 'سبب'))
        ->toThrow(RuntimeException::class, __('sanabel.conflict.waiver_not_independent'));

    // Nor without saying why.
    expect(fn () => $this->conflict->waive($this->case, $officer, $director, '   '))
        ->toThrow(RuntimeException::class, __('sanabel.conflict.waiver_reason_required'));

    // And with all three, it lifts.
    $waiver = $this->conflict->waive($this->case, $officer, $director, 'القرية كلّها أقارب, ولا باحث آخر');

    expect($this->conflict->isBarred($this->case, $officer))->toBeFalse()
        ->and($waiver->granted_by)->toBe($director->getKey())
        ->and($waiver->reason_ar)->not->toBeEmpty();
});

it('lifts it for the one person named, and for nobody else', function () {
    $first = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $second = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $director = userWithRole('executive_director', ['region_id' => $this->region->id]);

    foreach ([$first, $second] as $person) {
        $this->conflict->declare($this->case, $person, hasRelationship: true, relationType: 'neighbour');
    }

    $this->conflict->waive($this->case, $first, $director, 'لا بديل في القرية');

    expect($this->conflict->isBarred($this->case, $first))->toBeFalse()
        ->and($this->conflict->isBarred($this->case, $second))->toBeTrue();
});

it('writes all of it down, which is what the oversight committee reads', function () {
    /*
     | «توثيق جميع الإجراءات في سجل التدقيق» and «مراجعة رقابية». The
     | committee is read-only by T-257, so the record is what they get — and
     | it has to exist.
     */
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $director = userWithRole('executive_director', ['region_id' => $this->region->id]);

    $this->actingAs($officer);
    $this->conflict->declare($this->case, $officer, hasRelationship: true, relationType: 'close_kin');

    $this->actingAs($director);
    $this->conflict->waive($this->case, $officer, $director, 'لا باحث مستقلّ في المنطقة');

    $entities = AuditLog::query()->pluck('entity_type')->unique()->all();

    expect($entities)->toContain(ConflictDisclosure::class)
        ->and($entities)->toContain(ConflictWaiver::class);
});

it('keeps the standing answer as one answer, rewritten rather than piled up', function () {
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);

    $this->conflict->declare($this->case, $officer, hasRelationship: true, relationType: 'other_kin');
    $this->conflict->declare($this->case, $officer, hasRelationship: false);

    expect(ConflictDisclosure::where('beneficiary_id', $this->case->getKey())->count())->toBe(1)
        ->and($this->conflict->isBarred($this->case, $officer))->toBeFalse()
        // And the correction itself is in the log, so the earlier answer is
        // not lost by being overwritten.
        ->and(AuditLog::where('entity_type', ConflictDisclosure::class)->count())
        ->toBeGreaterThan(1);
});
