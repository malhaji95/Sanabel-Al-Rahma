<?php

use App\Models\AuditLog;
use App\Models\Disbursement;
use App\Models\DisbursementOrder;
use App\Services\DisbursementService;

/*
 | T-236 — «معالجة أوامر الصرف الجماعية المنفذة جزئيًا، بحيث لا تتأثر
 | الحوالات المنفذة عند إلغاء السطور المتبقية», 3 November.
 |
 | T-227 refuses to withdraw an order money has left, which protects the
 | transfers already made and leaves the association with nowhere to go:
 | forty families approved, three paid, and the thirty-seven that should
 | not be paid stay approved for ever.
 |
 | So the remainder is withdrawn and nothing else. The one thing they asked
 | to be sure of is the first test here.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->treasurer = userWithRole('treasurer');
    $this->director = userWithRole('executive_director');
    $this->service = app(DisbursementService::class);
});

/** An approved order of `$count` lines, one per family. */
function orderOf(int $count): DisbursementOrder
{
    $service = app(DisbursementService::class);

    $lines = collect(range(1, $count))->map(function () use ($service) {
        $case = publishedCase(test()->region);

        return $service->confirm($case, $service->remainingForPeriod($case, now()), test()->officer);
    });

    $order = $service->gather($lines->all(), test()->treasurer);
    $service->submit($order);
    $service->approve($order->refresh(), test()->director);

    return $order->refresh();
}

/** Everything about a paid line that must not move, as plain values. */
function snapshotOf(Disbursement $line): array
{
    $line->refresh();

    return [
        'status' => $line->status,
        'transfer_ref' => $line->transfer_ref,
        'executed_at' => $line->executed_at?->toIso8601String(),
        'proof_media_id' => $line->proof_media_id,
        'amount' => $line->amount,
        'order' => $line->disbursement_order_id,
        // The row's own clock: if this has not moved, nothing wrote to it.
        'updated_at' => $line->updated_at?->toIso8601String(),
    ];
}

it('leaves every executed transfer exactly as it was, down to its own clock', function () {
    $order = orderOf(3);
    [$paid, $a, $b] = $order->disbursements->all();

    $this->service->execute($paid, $this->treasurer, 'TRF-100', proof: transferProof());
    $before = snapshotOf($paid);

    $this->service->cancelRemaining($order->refresh(), $this->director, 'الباقي لم يعد مستحقًّا.');

    /*
     | The whole point of their question, and asserted on `updated_at` as
     | well as on the values: not merely that the transfer still reads the
     | same, but that nothing wrote to its row at all.
     */
    expect(snapshotOf($paid))->toBe($before)
        ->and($paid->refresh()->status)->toBe('executed')
        ->and($paid->transfer_ref)->toBe('TRF-100')
        // And it stays attached to the order it was paid under.
        ->and($paid->disbursement_order_id)->toBe($order->id);
});

it('sends the unpaid lines back to the confirmation queue', function () {
    $order = orderOf(3);
    [$paid, $a, $b] = $order->disbursements->all();

    $this->service->execute($paid, $this->treasurer, 'TRF-101', proof: transferProof());
    $this->service->cancelRemaining($order->refresh(), $this->director, 'تصحيح الدفعة.');

    foreach ([$a, $b] as $line) {
        $line->refresh();

        expect($line->status)->toBe('confirmed')
            ->and($line->disbursement_order_id)->toBeNull()
            ->and($line->approved_by)->toBeNull()
            ->and($line->approved_at)->toBeNull();
    }
});

it('closes the order as settled, not as cancelled, because money did leave it', function () {
    $order = orderOf(2);
    [$paid, $rest] = $order->disbursements->all();

    $this->service->execute($paid, $this->treasurer, 'TRF-102', proof: transferProof());
    $closed = $this->service->cancelRemaining($order->refresh(), $this->director, 'الباقي يُراجَع.');

    expect($closed->status)->toBe('settled')
        ->and($closed->settled_at)->not->toBeNull();
});

it('records who withdrew the remainder, when, and why', function () {
    $order = orderOf(2);
    [$paid] = $order->disbursements->all();

    $this->service->execute($paid, $this->treasurer, 'TRF-103', proof: transferProof());
    $closed = $this->service->cancelRemaining($order->refresh(), $this->director, 'خطأ في المبالغ الباقية.');

    expect($closed->cancelled_by)->toBe($this->director->id)
        ->and($closed->cancelled_at)->not->toBeNull()
        ->and($closed->cancel_reason_ar)->toBe('خطأ في المبالغ الباقية.');
});

it('lets the returned lines be gathered into a fresh order and approved again', function () {
    $order = orderOf(3);
    [$paid, $a, $b] = $order->disbursements->all();

    $this->service->execute($paid, $this->treasurer, 'TRF-104', proof: transferProof());
    $this->service->cancelRemaining($order->refresh(), $this->director, 'يُعاد رفعها.');

    $again = $this->service->gather([$a->refresh(), $b->refresh()], $this->treasurer);
    $this->service->submit($again);
    $this->service->approve($again->refresh(), $this->director);

    expect($again->refresh()->status)->toBe('approved')
        ->and($again->disbursements()->count())->toBe(2)
        ->and($again->id)->not->toBe($order->id);
});

it('refuses it on an order nothing was paid out of, which is the other act', function () {
    $order = orderOf(2);

    // Nothing executed: that is T-227's whole-order withdrawal, and the two
    // are kept apart because they end in different states.
    expect(fn () => $this->service->cancelRemaining($order, $this->director, 'سبب.'))
        ->toThrow(RuntimeException::class);

    expect($order->refresh()->status)->toBe('approved');
});

it('refuses a withdrawal with no reason written', function () {
    $order = orderOf(2);
    [$paid] = $order->disbursements->all();
    $this->service->execute($paid, $this->treasurer, 'TRF-105', proof: transferProof());

    expect(fn () => $this->service->cancelRemaining($order->refresh(), $this->director, '   '))
        ->toThrow(RuntimeException::class);
});

it('refuses it twice, so no second reason overwrites the first', function () {
    $order = orderOf(2);
    [$paid] = $order->disbursements->all();
    $this->service->execute($paid, $this->treasurer, 'TRF-106', proof: transferProof());
    $this->service->cancelRemaining($order->refresh(), $this->director, 'السبب الأول.');

    expect(fn () => $this->service->cancelRemaining($order->refresh(), $this->director, 'سبب آخر.'))
        ->toThrow(RuntimeException::class);

    expect($order->refresh()->cancel_reason_ar)->toBe('السبب الأول.');
});

it('writes a refused attempt to the oversight log', function () {
    $order = orderOf(2);

    try {
        $this->service->cancelRemaining($order, $this->director, 'سبب.');
    } catch (RuntimeException) {
    }

    expect(AuditLog::where('action', 'disbursement_refused')->get()->last()->after_json['reason'])
        ->toBe('nothing_paid_use_cancel');
});

it('leaves a line the household already answered for alone as well', function () {
    $order = orderOf(3);
    [$paid, $answered, $rest] = $order->disbursements->all();

    $this->service->execute($paid, $this->treasurer, 'TRF-107', proof: transferProof());
    $this->service->execute($answered, $this->treasurer, 'TRF-108', proof: transferProof());
    $family = $answered->beneficiary;
    $family->forceFill(['user_id' => userWithRole('beneficiary')->id])->save();

    $this->service->confirmReceipt($answered->refresh(), $family->refresh()->user);

    $this->service->cancelRemaining($order->refresh(), $this->director, 'الباقي يُراجَع.');

    expect($answered->refresh()->status)->toBe('received')
        ->and($rest->refresh()->status)->toBe('confirmed');
});
