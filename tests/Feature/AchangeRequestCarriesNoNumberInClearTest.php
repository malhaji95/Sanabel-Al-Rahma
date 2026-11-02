<?php

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Services\CaseService;
use Illuminate\Support\Facades\DB;

/*
 | T-230 — a change request stops carrying a telephone number in clear, and
 | stops putting one in the audit log.
 |
 | Nobody reported this. It came out of reading our own comment: the T-218
 | block on `CHANGEABLE_FIELDS` said of the spouse's identity number that it
 | «is encrypted at rest like the file-holder's own, so the request carries a
 | value nobody reads off the database». It was not. `payload_json` was cast
 | as a plain array, so the proposed new telephone, identity number or wallet
 | sat in clear in a second place while the file encrypted the same value.
 |
 | And rule 10 was breached outright. The audit trait redacts by column name
 | — `phone_encrypted`, `wallet_encrypted`, and so on — but on this model the
 | column is called `payload_json`, so every change request ever written put
 | its whole payload into `after_json`, personal value and all. The column
 | name told the redaction nothing.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->director = userWithRole('executive_director');
    $this->cases = app(CaseService::class);
});

/** The raw column, as the database holds it. */
function rawColumn(ChangeRequest $request, string $column): ?string
{
    return DB::table('change_requests')->where('id', $request->getKey())->value($column);
}

it('keeps a proposed telephone number out of the table', function () {
    $case = publishedCase($this->region);

    $request = $this->cases->requestChange(
        $case, $this->officer, ['phone_encrypted' => '0999123456'], 'الرقم القديم مغلق.'
    );

    expect(rawColumn($request, 'payload_json'))->not->toContain('0999123456');
});

it('still reads it back as the number, for the hand that has to approve it', function () {
    $case = publishedCase($this->region);

    $request = $this->cases->requestChange(
        $case, $this->officer, ['phone_encrypted' => '0999123456'], 'الرقم القديم مغلق.'
    );

    expect($request->fresh()->payload_json)->toBe(['phone_encrypted' => '0999123456']);
});

it('keeps a proposed identity number and a proposed wallet out of it too', function () {
    $case = publishedCase($this->region);

    $request = $this->cases->requestChange(
        $case, $this->officer,
        ['spouse_national_id_encrypted' => '09010203040', 'wallet_encrypted' => '55667788'],
        'تصحيح بيانات.'
    );

    $raw = rawColumn($request, 'payload_json');

    expect($raw)->not->toContain('09010203040')
        ->and($raw)->not->toContain('55667788');
});

it('shows the previous value as a number and not as a block of ciphertext', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['phone_encrypted' => '0911111111'])->save();

    $request = $this->cases->requestChange(
        $case->refresh(), $this->officer, ['phone_encrypted' => '0999123456'], 'تبديل الرقم.'
    );

    // The approver compares «before» with «after». Before T-230 the before
    // column was the raw row, so one side was a telephone number and the
    // other was base64.
    expect($request->fresh()->old_json['phone_encrypted'])->toBe('0911111111');
});

it('keeps the previous value out of the table as well', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['phone_encrypted' => '0911111111'])->save();

    $request = $this->cases->requestChange(
        $case->refresh(), $this->officer, ['phone_encrypted' => '0999123456'], 'تبديل الرقم.'
    );

    expect(rawColumn($request, 'old_json'))->not->toContain('0911111111');
});

it('writes no part of the payload into the audit log', function () {
    $case = publishedCase($this->region);

    $request = $this->cases->requestChange(
        $case, $this->officer, ['phone_encrypted' => '0999123456'], 'الرقم القديم مغلق.'
    );

    $entries = AuditLog::where('entity_type', ChangeRequest::class)
        ->where('entity_id', $request->getKey())
        ->get();

    expect($entries)->not->toBeEmpty();

    foreach ($entries as $entry) {
        $both = json_encode([$entry->before_json, $entry->after_json], JSON_UNESCAPED_UNICODE);

        expect($both)->not->toContain('0999123456')
            // Rule 10, and the log is read by more people than the row is.
            ->and($entry->after_json['payload_json'] ?? '[redacted]')->toBe('[redacted]');
    }
});

it('still logs that a request was written, which is what rule 4 is for', function () {
    $case = publishedCase($this->region);

    $request = $this->cases->requestChange(
        $case, $this->officer, ['phone_encrypted' => '0999123456'], 'الرقم القديم مغلق.'
    );

    // Redacted is not absent: the entry, the actor and the entity remain.
    expect(AuditLog::where('entity_type', ChangeRequest::class)
        ->where('entity_id', $request->getKey())->count())->toBeGreaterThan(0);
});

it('keeps the spouse’s identity number out of the log on a direct correction too', function () {
    $case = publishedCase($this->region);

    $this->actingAs($this->director);
    $case->forceFill(['spouse_national_id_encrypted' => '09010203040'])->save();

    $entries = AuditLog::where('entity_type', Beneficiary::class)
        ->where('entity_id', $case->getKey())->get();

    // T-218 added this field to the file and nobody added it to the
    // redaction list, so the number went into the log in clear.
    foreach ($entries as $entry) {
        expect(json_encode([$entry->before_json, $entry->after_json], JSON_UNESCAPED_UNICODE))
            ->not->toContain('09010203040');
    }
})->group('FILE-04');

it('applies the change to the file exactly as before', function () {
    $case = publishedCase($this->region);

    $request = $this->cases->requestChange(
        $case, $this->officer, ['phone_encrypted' => '0999123456'], 'الرقم القديم مغلق.'
    );

    $this->cases->approveChange($request->fresh(), $this->director);

    // The encryption is at rest and nothing above it changed: the approval
    // path reads the payload as the array it always was.
    expect($case->fresh()->phone_encrypted)->toBe('0999123456');
});
