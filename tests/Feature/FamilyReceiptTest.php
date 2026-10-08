<?php

use App\Models\Complaint;
use App\Models\Disbursement;
use App\Services\DisbursementService;
use Livewire\Livewire;

/*
 | The last signature on the money is the family's own (decision of
 | 6 October). The person who moved it never confirms its receipt.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->service = app(DisbursementService::class);
    $this->treasurer = userWithRole('treasurer');

    $this->family = publishedCase($this->region);
    $this->account = userWithRole('beneficiary');
    $this->family->forceFill(['user_id' => $this->account->id])->save();

    $this->payment = function (int $amount = 9_000) {
        $line = $this->service->confirm($this->family, $amount, userWithRole('case_officer'));
        $order = $this->service->gather([$line], $this->treasurer);
        $this->service->submit($order);
        $this->service->approve($order, userWithRole('executive_director'));

        return $this->service->execute($line->refresh(), $this->treasurer, 'TRF-FAM-'.$amount);
    };
});

it('waits on the family once the money has moved', function () {
    $payment = ($this->payment)();

    expect($payment->status)->toBe('executed')
        ->and($payment->awaitsBeneficiary())->toBeTrue()
        // Their window opens the moment the transfer leaves.
        ->and($payment->confirm_due_at)->not->toBeNull()
        ->and($payment->confirm_due_at->isFuture())->toBeTrue();
});

it('closes the payment when the family says it arrived', function () {
    $payment = ($this->payment)();

    $this->service->confirmReceipt($payment, $this->account);

    expect($payment->refresh()->status)->toBe('received')
        ->and($payment->beneficiary_responded_at)->not->toBeNull();
});

it('opens a numbered complaint when the family says it did not', function () {
    $payment = ($this->payment)();

    $this->service->disputeReceipt($payment, $this->account, 'راجعت المحفظة ولم يصلني شيء');

    $payment->refresh();

    expect($payment->status)->toBe('disputed')
        ->and($payment->dispute_reason_ar)->not->toBeEmpty()
        ->and($payment->complaint_id)->not->toBeNull()
        ->and(Complaint::find($payment->complaint_id)->reference_no)->toStartWith('CMP-');
});

it('lets nobody else answer for a family', function () {
    $payment = ($this->payment)();

    foreach ([$this->treasurer, userWithRole('case_officer'), userWithRole('beneficiary')] as $other) {
        expect(fn () => $this->service->confirmReceipt($payment->refresh(), $other))
            ->toThrow(RuntimeException::class);
    }

    expect($payment->refresh()->status)->toBe('executed');
});

it('follows a silent window up without closing the payment', function () {
    $payment = ($this->payment)();

    expect($this->service->chaseUnconfirmed())->toBe(0);

    $payment->forceFill(['confirm_due_at' => now()->subDay()])->save();

    expect($this->service->chaseUnconfirmed())->toBe(1);

    $payment->refresh();

    // The association was explicit: running the window out is not an answer.
    expect($payment->status)->toBe('executed')
        ->and($payment->awaitsBeneficiary())->toBeTrue()
        ->and($payment->awaitsAndIsOverdue())->toBeTrue()
        ->and($payment->escalated_at)->not->toBeNull()
        // Not read as an objection the family never made.
        ->and($payment->complaint_id)->toBeNull()
        ->and($payment->dispute_reason_ar)->toBeNull();

    // Raised once, not once a day.
    expect($this->service->chaseUnconfirmed())->toBe(0);
});

it('still lets the family answer after the window has run out', function () {
    $payment = ($this->payment)();
    $payment->forceFill(['confirm_due_at' => now()->subDay()])->save();
    $this->service->chaseUnconfirmed();

    $this->service->confirmReceipt($payment->refresh(), $this->account);

    expect($payment->refresh()->status)->toBe('received');

    $second = ($this->payment)(2_000);
    $second->forceFill(['confirm_due_at' => now()->subDay()])->save();
    $this->service->chaseUnconfirmed();

    $this->service->disputeReceipt($second->refresh(), $this->account, 'لم يصلني شيء');

    expect($second->refresh()->status)->toBe('disputed');
});

it('raises no replacement payment when the family objects', function () {
    $payment = ($this->payment)();
    $before = Disbursement::count();

    $this->service->disputeReceipt($payment, $this->account, 'لم يصلني المبلغ');

    // The objection answers the payment that was made. What is owed instead
    // is the association's call after it looks, not a button's.
    expect(Disbursement::count())->toBe($before)
        ->and($payment->refresh()->complaint_id)->not->toBeNull();
});

it('shows a family their own payments and nobody else', function () {
    $mine = ($this->payment)();

    $other = publishedCase($this->region);
    $theirs = $this->service->confirm($other, 4_000, userWithRole('case_officer'));

    Livewire::actingAs($this->account)
        ->test(App\Livewire\FamilyPortal::class)
        ->assertSee($this->family->file_number)
        ->assertSee($mine->transfer_ref)
        ->assertDontSee($other->file_number);

    // And an id typed into the page reaches nothing that is not theirs.
    Livewire::actingAs($this->account)
        ->test(App\Livewire\FamilyPortal::class)
        ->call('confirm', $theirs->id)
        ->assertSet('error', __('sanabel.disbursement.not_your_payment'));

    expect($theirs->refresh()->status)->toBe('confirmed');
});

it('answers from the page, both ways', function () {
    $payment = ($this->payment)();

    Livewire::actingAs($this->account)
        ->test(App\Livewire\FamilyPortal::class)
        ->call('confirm', $payment->id)
        ->assertSet('notice', __('sanabel.disbursement.thanks_confirmed'));

    expect($payment->refresh()->status)->toBe('received');

    $second = ($this->payment)(3_000);

    Livewire::actingAs($this->account)
        ->test(App\Livewire\FamilyPortal::class)
        ->set('reason', 'لم يصلني شيء حتى اليوم')
        ->call('dispute', $second->id)
        ->assertSet('notice', __('sanabel.disbursement.dispute_recorded'));

    expect($second->refresh()->status)->toBe('disputed');
});

it('lets the accountant reconcile what the family confirmed', function () {
    $payment = ($this->payment)();

    $this->service->confirmReceipt($payment, $this->account);
    $this->service->reconcile($payment->refresh(), userWithRole('finance'));

    expect($payment->refresh()->status)->toBe('reconciled');
});
