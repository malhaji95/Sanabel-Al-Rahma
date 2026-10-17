<?php

use App\Models\Beneficiary;
use App\Services\CaseService;

/*
 | Verification passes through the delegate and then the area supervisor before
 | it reaches the admin. The three statuses existed from the start and nothing
 | ever moved a file into them; these are the steps that do.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $this->supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);
    $this->admin = userWithRole('admin');
    $this->cases = app(CaseService::class);
});

function draftCase($region, array $attributes = []): Beneficiary
{
    return familyOf($region, attributes: array_merge(['status' => 'draft'], $attributes));
}

it('walks a file from draft to approved through both sign-offs', function () {
    $case = draftCase($this->region);

    expect($this->cases->verifyInField($case, $this->delegate)->status)->toBe('verified');
    expect($this->cases->endorse($case->refresh(), $this->supervisor)->status)->toBe('pending_approval');
    expect($this->cases->approve($case->refresh(), $this->admin)->status)->toBe('approved');
})->group('APP-01');

it('records who verified and who endorsed, with the time of each', function () {
    $case = draftCase($this->region);

    $this->cases->verifyInField($case, $this->delegate);
    $case = $this->cases->endorse($case->refresh(), $this->supervisor);

    expect($case->field_verified_by)->toBe($this->delegate->id)
        ->and($case->field_verified_at)->not->toBeNull()
        ->and($case->endorsed_by)->toBe($this->supervisor->id)
        ->and($case->endorsed_at)->not->toBeNull();
})->group('OPS-01');

it('refuses the admin a file the field has not signed off', function () {
    $case = draftCase($this->region);

    expect(fn () => $this->cases->approve($case, $this->admin))
        ->toThrow(RuntimeException::class);

    // And still refuses it after only the first of the two steps.
    $this->cases->verifyInField($case, $this->delegate);

    expect(fn () => $this->cases->approve($case->refresh(), $this->admin))
        ->toThrow(RuntimeException::class);
});

it('refuses an endorsement from whoever verified the file in the field', function () {
    $case = draftCase($this->region);
    // A supervisor may do the field visit; they may not then endorse their own.
    $this->cases->verifyInField($case, $this->supervisor);

    expect(fn () => $this->cases->endorse($case->refresh(), $this->supervisor))
        ->toThrow(RuntimeException::class);
})->group('APP-01');

it('refuses the steps out of order', function () {
    $case = draftCase($this->region);

    expect(fn () => $this->cases->endorse($case, $this->supervisor))
        ->toThrow(RuntimeException::class);

    $this->cases->verifyInField($case, $this->delegate);

    expect(fn () => $this->cases->verifyInField($case->refresh(), $this->delegate))
        ->toThrow(RuntimeException::class);
})->group('APP-01');

it('offers neither step to a user outside the file region', function () {
    $elsewhere = regionWithRates();
    $case = draftCase($elsewhere);

    expect($this->delegate->can('verifyInField', $case))->toBeFalse();

    $this->cases->verifyInField($case, userWithRole('delegate', ['region_id' => $elsewhere->id]));

    expect($this->supervisor->can('endorse', $case->refresh()))->toBeFalse();
})->group('AUTH-02');

it('does not let a delegate stand in for the area supervisor', function () {
    $case = draftCase($this->region);
    $other = userWithRole('delegate', ['region_id' => $this->region->id]);

    $this->cases->verifyInField($case, $this->delegate);

    // A second delegate is still a delegate: the step exists so that someone
    // supervising looks at the file.
    expect($other->can('endorse', $case->refresh()))->toBeFalse()
        ->and($this->supervisor->can('endorse', $case->refresh()))->toBeTrue();
})->group('APP-01');

it('sends a file back through the field after a reassessment is asked for', function () {
    $case = draftCase($this->region, ['status' => 'needs_reassessment']);

    expect($this->cases->verifyInField($case, $this->delegate)->status)->toBe('verified');
});

it('refuses a partner association the field sign-off on the file it raised itself', function () {
    /*
     | 16 Oct 2026 — «الجمعيات الخيرية بالأصل ما عندها صلاحية اعتماد بصفة محقق
     | ميداني. هي صفة شريك بتدخل البيانات، وراح تكون من مسؤولية المندوب
     | الميداني هو اللي يحقق».
     |
     | It used to stand in for the delegate on its own files, which made the
     | partner that wrote the file the only person who had checked it. The
     | step exists to say somebody went to the house.
     */
    $association = userWithRole('association');
    $case = draftCase($this->region, ['created_by' => $association->id, 'source' => 'association']);

    expect($association->can('verifyInField', $case))->toBeFalse();

    expect(fn () => $this->cases->verifyInField($case, $association))
        ->toThrow(RuntimeException::class, __('sanabel.cases.field_verifier_only'));

    // The delegate does it, and the rest of the chain is unchanged: the
    // supervisor endorses, the executive director approves.
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $case = $this->cases->verifyInField($case->refresh(), $delegate);

    expect($case->status)->toBe('verified')
        ->and($case->field_verified_by)->toBe($delegate->id);

    expect($association->can('endorse', $case))->toBeFalse()
        ->and($this->supervisor->can('endorse', $case))->toBeTrue();

    $case = $this->cases->endorse($case, $this->supervisor);

    expect($this->cases->approve($case, userWithRole('executive_director'))->status)->toBe('approved');
});

it('does not let an association sign off a file it did not raise', function () {
    $association = userWithRole('association');
    $case = draftCase($this->region);

    expect($association->can('verifyInField', $case))->toBeFalse();
});
