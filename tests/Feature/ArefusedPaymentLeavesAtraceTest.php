<?php

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Disbursement;
use App\Services\DisbursementService;
use Illuminate\Support\Collection;

/*
 | T-233 — «وتوثقوا محاولات الصرف المرفوضة في سجل الرقابة», 3 November.
 |
 | Every guard on the way out used to throw and vanish. The screen showed
 | the sentence, and the attempt left nothing behind — so an account trying
 | the same refused payment again and again, or trying to approve the order
 | it had raised itself, was invisible to whoever reads the log. And the log
 | is the one place a pattern like that would ever show.
 |
 | Rule 10 — the entry carries the reason, the file number, the reference
 | and the amount asked for, which is what the notifications already carry.
 | No name, no telephone, no wallet, no identity number.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->treasurer = userWithRole('treasurer');
    $this->director = userWithRole('executive_director');
    $this->service = app(DisbursementService::class);
});

/** The refusals written since this moment. */
function refusals(): Collection
{
    return AuditLog::where('action', 'disbursement_refused')->get();
}

it('writes a refusal when a payment is attempted on a file the association refused', function () {
    $case = publishedCase($this->region);
    $amount = $this->service->remainingForPeriod($case, now());
    $case->forceFill(['status' => 'rejected'])->save();

    expect(fn () => $this->service->confirm($case->refresh(), $amount, $this->officer))
        ->toThrow(RuntimeException::class);

    $entry = refusals()->last();

    expect(refusals())->toHaveCount(1)
        ->and($entry->after_json['reason'])->toBe('file_may_not_receive')
        ->and($entry->after_json['file_status'])->toBe('rejected')
        ->and($entry->after_json['file_number'])->toBe($case->file_number)
        ->and($entry->entity_type)->toBe(Beneficiary::class)
        ->and($entry->entity_id)->toBe($case->id);
});

it('names the hand that tried, which is the whole point of the record', function () {
    $case = publishedCase($this->region);
    $amount = $this->service->remainingForPeriod($case, now());
    $case->forceFill(['status' => 'rejected'])->save();

    try {
        $this->service->confirm($case->refresh(), $amount, $this->officer);
    } catch (RuntimeException) {
    }

    expect(refusals()->last()->actor_id)->toBe($this->officer->id)
        ->and(refusals()->last()->actor_role)->toBe('case_officer');
});

it('counts every attempt, so trying again and again is visible', function () {
    $case = publishedCase($this->region);
    $amount = $this->service->remainingForPeriod($case, now());
    $case->forceFill(['status' => 'rejected'])->save();

    foreach (range(1, 3) as $ignored) {
        try {
            $this->service->confirm($case->refresh(), $amount, $this->officer);
        } catch (RuntimeException) {
        }
    }

    expect(refusals())->toHaveCount(3);
});

it('writes one when somebody tries to approve the order they raised', function () {
    $case = publishedCase($this->region);
    $line = $this->service->confirm($case, $this->service->remainingForPeriod($case, now()), $this->officer);
    $order = $this->service->gather([$line], $this->treasurer);
    $this->service->submit($order);

    // T-105 — the hand that raised it does not approve it.
    expect(fn () => $this->service->approve($order->refresh(), $this->treasurer))
        ->toThrow(RuntimeException::class);

    expect(refusals()->last()->after_json['reason'])->toBe('no_self_approval');
});

it('writes one when a transfer is attempted without its receipt', function () {
    $case = publishedCase($this->region);
    $line = $this->service->confirm($case, $this->service->remainingForPeriod($case, now()), $this->officer);
    $order = $this->service->gather([$line], $this->treasurer);
    $this->service->submit($order);
    $this->service->approve($order->refresh(), $this->director);

    expect(fn () => $this->service->execute($line->refresh(), $this->treasurer, 'TRF-1'))
        ->toThrow(RuntimeException::class);

    expect(refusals()->last()->after_json['reason'])->toBe('proof_required');
});

it('writes one when a payment past the month’s need is attempted', function () {
    $case = publishedCase($this->region);
    $ceiling = $this->service->remainingForPeriod($case, now());

    expect(fn () => $this->service->confirm($case, $ceiling + 1, $this->officer))
        ->toThrow(RuntimeException::class);

    $entry = refusals()->last();

    expect($entry->after_json['reason'])->toBe('over_monthly_need')
        ->and($entry->after_json['ceiling'])->toBe($ceiling);
});

it('writes one when an order money has already left is asked to be withdrawn', function () {
    /*
     | Two lines, and only one of them paid. Paying the single line of a
     | one-line order settles the order, and a settled order is refused as
     | «already closed» before the question of payment is even reached —
     | which is correct, and is not the refusal this test is about.
     */
    $first = $this->service->confirm(
        $a = publishedCase($this->region),
        $this->service->remainingForPeriod($a, now()),
        $this->officer,
    );
    $second = $this->service->confirm(
        $b = publishedCase($this->region),
        $this->service->remainingForPeriod($b, now()),
        $this->officer,
    );

    $order = $this->service->gather([$first, $second], $this->treasurer);
    $this->service->submit($order);
    $this->service->approve($order->refresh(), $this->director);
    $this->service->execute($first->refresh(), $this->treasurer, 'TRF-2', proof: transferProof());

    expect(fn () => $this->service->cancelOrder($order->refresh(), $this->director, 'تصحيح.'))
        ->toThrow(RuntimeException::class);

    expect(refusals()->last()->after_json['reason'])->toBe('cannot_cancel_paid');
});

it('carries no personal data into the log', function () {
    $case = publishedCase($this->region);
    $case->forceFill([
        'first_name' => 'عبدالله', 'phone_encrypted' => '0999123456',
        'status' => 'rejected',
    ])->save();

    try {
        $this->service->confirm($case->refresh(), 1_000, $this->officer);
    } catch (RuntimeException) {
    }

    $written = json_encode(refusals()->last()->after_json, JSON_UNESCAPED_UNICODE);

    // Rule 10 — the file number identifies the file to somebody who may
    // open it, and nothing here identifies the household to somebody who
    // may not.
    expect($written)->not->toContain('عبدالله')
        ->and($written)->not->toContain('0999123456');
});

it('keeps the refusal even though nothing else was written', function () {
    $case = publishedCase($this->region);
    $amount = $this->service->remainingForPeriod($case, now());
    $case->forceFill(['status' => 'rejected'])->save();

    try {
        $this->service->confirm($case->refresh(), $amount, $this->officer);
    } catch (RuntimeException) {
    }

    // The attempt is on the record; the payment is not.
    expect(refusals())->toHaveCount(1)
        ->and(Disbursement::where('beneficiary_id', $case->id)->count())->toBe(0);
});
