<?php

use App\Models\Disbursement;
use App\Services\CoverageService;
use App\Services\DisbursementService;

/*
 | The path money takes on its way out, as the association set it out on
 | 6 and 8 October: the case officer confirms, the treasurer gathers, the
 | executive director approves, the treasurer executes, the accountant
 | reconciles. The order freezes on approval and nobody approves their own
 | work.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer');
    $this->treasurer = userWithRole('treasurer');
    $this->director = userWithRole('executive_director');
    $this->accountant = userWithRole('finance');
    $this->service = app(DisbursementService::class);
});

it('walks one payment from confirmation to reconciliation', function () {
    $family = publishedCase($this->region);

    $line = $this->service->confirm($family, 10_000, $this->officer);

    expect($line->status)->toBe('confirmed')
        ->and($line->confirmed_by)->toBe($this->officer->id);

    $order = $this->service->gather([$line], $this->treasurer);

    expect($order->status)->toBe('draft')
        ->and($order->reference_no)->toStartWith('DO-')
        ->and($line->refresh()->status)->toBe('in_order');

    $this->service->submit($order);
    expect($order->refresh()->status)->toBe('pending_approval');

    $this->service->approve($order, $this->director);

    expect($order->refresh()->status)->toBe('approved')
        // The association's own wording, recorded rather than implied.
        ->and($order->approved_duly)->toBeTrue()
        ->and($order->approved_by)->toBe($this->director->id)
        ->and($line->refresh()->status)->toBe('approved');

    $this->service->execute($line, $this->treasurer, 'TRF-900');

    expect($line->refresh()->status)->toBe('executed')
        ->and($line->transfer_ref)->toBe('TRF-900')
        // Nothing is left waiting, so the order has settled.
        ->and($order->refresh()->status)->toBe('settled');

    $this->service->reconcile($line, $this->accountant);

    expect($line->refresh()->status)->toBe('reconciled')
        ->and($line->reconciled_by)->toBe($this->accountant->id);
});

it('will not pay a family more than their month still needs', function () {
    $family = publishedCase($this->region);
    $need = app(CoverageService::class)->needAmount($family);

    expect(fn () => $this->service->confirm($family, $need + 1, $this->officer))
        ->toThrow(RuntimeException::class);

    // Up to the need is fine, and what is left shrinks by what was taken.
    $this->service->confirm($family, $need - 1_000, $this->officer);

    expect($this->service->remainingForPeriod($family, now()->startOfMonth()))->toBe(1_000)
        ->and(fn () => $this->service->confirm($family, 2_000, $this->officer))
        ->toThrow(RuntimeException::class);
});

it('refuses an order raised and approved by the same account', function () {
    $family = publishedCase($this->region);
    $line = $this->service->confirm($family, 5_000, $this->officer);

    // The treasurer holds raise and execute, never approve.
    $order = $this->service->gather([$line], $this->treasurer);
    $this->service->submit($order);

    expect(fn () => $this->service->approve($order, $this->treasurer))
        ->toThrow(RuntimeException::class);

    expect($this->treasurer->can_('approve_disbursement'))->toBeFalse()
        ->and($this->director->can_('approve_disbursement'))->toBeTrue()
        ->and($this->director->can_('execute_disbursement'))->toBeFalse();
});

it('freezes the order the moment it is approved', function () {
    $family = publishedCase($this->region);
    $other = publishedCase($this->region);

    $line = $this->service->confirm($family, 5_000, $this->officer);
    $spare = $this->service->confirm($other, 5_000, $this->officer);

    $order = $this->service->gather([$line], $this->treasurer);
    $this->service->submit($order);
    $this->service->approve($order, $this->director);

    expect(fn () => $this->service->addTo($order->refresh(), $spare))
        ->toThrow(RuntimeException::class);

    expect(fn () => $this->service->remove($line->refresh()))
        ->toThrow(RuntimeException::class);
});

it('lets one line be set aside while the rest of the order goes through', function () {
    $keep = publishedCase($this->region);
    $drop = publishedCase($this->region);

    $kept = $this->service->confirm($keep, 5_000, $this->officer);
    $dropped = $this->service->confirm($drop, 5_000, $this->officer);

    $order = $this->service->gather([$kept, $dropped], $this->treasurer);
    $this->service->submit($order);

    $this->service->reject($dropped, $this->director, 'الأسرة تلقت دعما من جهة أخرى');
    $this->service->approve($order, $this->director);

    expect($dropped->refresh()->status)->toBe('rejected')
        ->and($dropped->reject_reason_ar)->not->toBeEmpty()
        ->and($kept->refresh()->status)->toBe('approved')
        // A set-aside line is not part of what the order pays out.
        ->and($order->refresh()->total())->toBe(5_000);
});

it('does not stop the order when one payment fails to land', function () {
    $first = publishedCase($this->region);
    $second = publishedCase($this->region);

    $a = $this->service->confirm($first, 5_000, $this->officer);
    $b = $this->service->confirm($second, 5_000, $this->officer);

    $order = $this->service->gather([$a, $b], $this->treasurer);
    $this->service->submit($order);
    $this->service->approve($order, $this->director);

    $this->service->fail($a->refresh(), 'رقم المحفظة غير صحيح');

    expect($a->refresh()->status)->toBe('failed')
        ->and($a->failure_reason_ar)->not->toBeEmpty()
        // The other line is still waiting, so the order is still running.
        ->and($order->refresh()->status)->toBe('executing');

    $this->service->execute($b->refresh(), $this->treasurer, 'TRF-901');

    expect($order->refresh()->status)->toBe('settled');
});

it('keeps every step in its own order', function () {
    $family = publishedCase($this->region);
    $line = $this->service->confirm($family, 5_000, $this->officer);

    // Not executed before it is approved.
    expect(fn () => $this->service->execute($line, $this->treasurer, 'TRF-902'))
        ->toThrow(RuntimeException::class);

    $order = $this->service->gather([$line], $this->treasurer);

    // Not approved before it is submitted.
    expect(fn () => $this->service->approve($order, $this->director))
        ->toThrow(RuntimeException::class);

    $this->service->submit($order);
    $this->service->approve($order, $this->director);

    // Not reconciled before it is executed.
    expect(fn () => $this->service->reconcile($line->refresh(), $this->accountant))
        ->toThrow(RuntimeException::class);
});

it('writes every signature into the audit log', function () {
    $family = publishedCase($this->region);

    $line = $this->service->confirm($family, 5_000, $this->officer);
    $order = $this->service->gather([$line], $this->treasurer);
    $this->service->submit($order);
    $this->service->approve($order, $this->director);

    $entries = Disbursement::find($line->id)->auditEntries()->get();

    expect($entries)->not->toBeEmpty()
        ->and($entries->pluck('action')->unique()->values()->all())->toContain('created', 'updated');
});
