<?php

use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Services\CaseService;
use App\Services\DuplicateService;

/*
 | T-61 — AUTH-03 of the security roadmap.
 |
 | The change-request payload was any array at all, and an approved one is
 | filled straight into the model. Beneficiary's fillable list carries the
 | columns that record who decided what and when, so a request whose stated
 | reason read "a phone number" could carry status, approved_by and
 | published_at and take a draft file straight to published — past the
 | delegate, past the area supervisor, past management. Two people were needed
 | to do it (T-59), but the second was reading a reason, not a JSON payload.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->admin = userWithRole('admin', ['region_id' => $this->region->id]);
    $this->cases = app(CaseService::class);
});

it('refuses a payload that would publish a file past the approval chain', function () {
    $case = familyOf($this->region, adults: 1, children: 1);

    expect(fn () => $this->cases->requestChange(
        $case,
        $this->officer,
        ['status' => 'published', 'approved_by' => $this->officer->id, 'published_at' => now()],
        'تعديل رقم الهاتف',
    ))->toThrow(RuntimeException::class);

    // Refused where it was raised: no row to review, nothing for a second
    // person to wave through.
    expect(ChangeRequest::count())->toBe(0)
        ->and($case->fresh()->status)->toBe('draft');
});

it('names the fields it refused, so a legitimate one is a one-line fix', function () {
    $case = familyOf($this->region, adults: 1, children: 1);

    try {
        $this->cases->requestChange($case, $this->officer, ['endorsed_by' => 1], 'سبب');
        $this->fail('the request was not refused');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('endorsed_by');
    }
});

it('refuses each decision column on its own', function () {
    $case = familyOf($this->region, adults: 1, children: 1);

    foreach ([
        'status', 'approved_by', 'approved_at', 'published_at', 'field_verified_by',
        'field_verified_at', 'endorsed_by', 'endorsed_at', 'reject_reason_ar',
        'merged_into_id', 'duplicate_review_flag', 'national_id_hash', 'user_id',
        'created_by', 'file_number',
    ] as $column) {
        expect(fn () => $this->cases->requestChange($case, $this->officer, [$column => 'x'], 'سبب'))
            ->toThrow(RuntimeException::class);
    }
});

it('refuses a mixed payload outright rather than keeping the safe half', function () {
    $case = familyOf($this->region, adults: 1, children: 1);

    expect(fn () => $this->cases->requestChange(
        $case,
        $this->officer,
        ['phone_encrypted' => '0911111111', 'status' => 'published'],
        'سبب',
    ))->toThrow(RuntimeException::class);

    expect(ChangeRequest::count())->toBe(0);
});

it('still carries every change the association actually makes', function () {
    $case = publishedCase($this->region);

    foreach ([
        ['phone_encrypted' => '0911111111'],
        ['support_type' => 'one_time'],
        ['region_id' => $this->region->id],
        ['wallet_encrypted' => '0999000111'],
        ['town_ar' => 'حي المنشية'],
        ['urgency_deadline_at' => now()->addDays(3)->toDateTimeString()],
    ] as $payload) {
        $request = $this->cases->requestChange($case, $this->officer, $payload, 'سبب');

        expect($request->status)->toBe('pending');
    }
});

it('refuses an empty payload', function () {
    $case = familyOf($this->region, adults: 1, children: 1);

    expect(fn () => $this->cases->requestChange($case, $this->officer, [], 'سبب'))
        ->toThrow(RuntimeException::class);
});

it('keeps every decision column off the allowlist', function () {
    // The allowlist is data. This is what stops a column that records a
    // decision from being added to it by hand later.
    foreach ([
        'status', 'approved_by', 'approved_at', 'published_at', 'field_verified_by',
        'field_verified_at', 'endorsed_by', 'endorsed_at', 'reject_reason_ar',
        'merged_into_id', 'duplicate_review_flag', 'national_id_hash', 'user_id',
        'created_by', 'file_number', 'last_assessment_at', 'next_assessment_due_at', 'source',
    ] as $column) {
        expect(ChangeRequest::CHANGEABLE_FIELDS)->not->toContain($column);
    }
});

it('rehashes the national ID when a change request moves it', function () {
    // The hash is what rule 11 matches a duplicate on, and it is not a field a
    // request may name. Changing the number without it left duplicate
    // detection silently pointing at the old one.
    $case = publishedCase($this->region);

    $this->cases->approveChange(
        $this->cases->requestChange($case, $this->officer, ['national_id_encrypted' => '09998887776'], 'تصحيح الرقم'),
        $this->admin,
    );

    $case->refresh();

    expect($case->national_id_encrypted)->toBe('09998887776')
        ->and($case->national_id_hash)->toBe(Beneficiary::hashNationalId('09998887776'))
        // And the file is findable by the new number, which is the point.
        ->and(app(DuplicateService::class)->existingFile('09998887776')?->getKey())
        ->toBe($case->getKey());
});

it('refuses the same payload through the API, not only the service', function () {
    // The service is the chokepoint both paths go through, so the API route
    // inherits the gate rather than carrying its own copy of the list.
    $case = familyOf($this->region, adults: 1, children: 1);

    $this->actingAs($this->officer)
        ->postJson("/api/cases/{$case->id}/change-requests", [
            'payload' => ['status' => 'published'],
            'reason_ar' => 'تعديل رقم الهاتف',
        ])
        ->assertStatus(422);

    expect(ChangeRequest::count())->toBe(0)
        ->and($case->fresh()->status)->toBe('draft');
});
