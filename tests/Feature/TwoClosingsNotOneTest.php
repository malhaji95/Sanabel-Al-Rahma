<?php

use App\Models\AppNotification;
use App\Models\Disbursement;
use App\Services\DisbursementService;

/*
 | T-214 — «ردود المستفيدين بترجع عنده بصفحة التبرعات، وبيشوف مين استلم
 | ومين ما استلم. اللي أكد الاستلام وكان عنده إثبات تحويل صحيح، بيقفل
 | متابعة الطلب. واللي ما استلم أو عنده مشكلة، بيرجعه لأمين الصندوق
 | للمعالجة، وبيظل مفتوح لحد ما تنحل المشكلة», 1 November — and, in the
 | same message, «إغلاق متابعة المستفيد غير إغلاق التدقيق المالي».
 |
 | Those two closings existed as one. A line went `received` when the
 | household answered and `reconciled` when the accountant matched it to
 | the bank, and between them there was nothing the data desk could mark:
 | no way to say «I have followed this one up», and no way to hand a
 | disputed one back and see that it had been handed back.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->treasurer = userWithRole('treasurer');
    $this->director = userWithRole('executive_director');
    $this->accountant = userWithRole('finance');
    $this->service = app(DisbursementService::class);
});

/** A line the household has already been paid and asked about. */
function paidLine($region, $officer, $treasurer, $director): Disbursement
{
    $case = publishedCase($region);
    $case->forceFill(['user_id' => userWithRole('beneficiary')->id])->save();

    $service = app(DisbursementService::class);

    $line = $service->confirm($case->refresh(), $service->remainingForPeriod($case, now()), $officer);
    $order = $service->gather([$line], $treasurer);
    $service->submit($order);
    $service->approve($order->refresh(), $director);
    $service->execute($line->refresh(), $treasurer, 'TRF-7001');

    return $line->refresh();
}

it('lets the desk close its follow-up once the household has confirmed', function () {
    $line = paidLine($this->region, $this->officer, $this->treasurer, $this->director);
    $this->service->confirmReceipt($line, $line->beneficiary->user);

    $closed = $this->service->closeFollowUp($line->refresh(), $this->officer);

    expect($closed->followup_closed_at)->not->toBeNull()
        ->and($closed->followup_closed_by)->toBe($this->officer->id);
});

it('closes nothing about the money by closing the follow-up', function () {
    $line = paidLine($this->region, $this->officer, $this->treasurer, $this->director);
    $this->service->confirmReceipt($line, $line->beneficiary->user);
    $this->service->closeFollowUp($line->refresh(), $this->officer);

    $line->refresh();

    // Still the household's answer, still unreconciled, still the
    // accountant's to match against the bank.
    expect($line->status)->toBe('received')
        ->and($line->reconciled_at)->toBeNull();

    $this->service->reconcile($line, $this->accountant);

    expect($line->refresh()->status)->toBe('reconciled')
        // And the desk's own mark survives the accountant's.
        ->and($line->followup_closed_at)->not->toBeNull();
});

it('refuses to close a follow-up on a payment nobody answered for', function () {
    $line = paidLine($this->region, $this->officer, $this->treasurer, $this->director);

    // Silence is neither a yes nor a no (8 October). Closing here would be
    // the desk answering on the household's behalf.
    expect(fn () => $this->service->closeFollowUp($line, $this->officer))
        ->toThrow(RuntimeException::class);

    expect($line->refresh()->followup_closed_at)->toBeNull();
});

it('refuses to close one the household says never arrived', function () {
    $line = paidLine($this->region, $this->officer, $this->treasurer, $this->director);
    $this->service->disputeReceipt($line, $line->beneficiary->user, 'ما وصلني شيء.');

    expect(fn () => $this->service->closeFollowUp($line->refresh(), $this->officer))
        ->toThrow(RuntimeException::class);
});

it('hands a disputed line back to the treasurer and tells them', function () {
    $line = paidLine($this->region, $this->officer, $this->treasurer, $this->director);
    $this->service->disputeReceipt($line, $line->beneficiary->user, 'ما وصلني شيء.');

    $before = AppNotification::where('recipient_id', $this->treasurer->id)
        ->where('template_key', 'disbursement_disputed')->count();

    $returned = $this->service->returnToTreasurer($line->refresh(), $this->officer);

    expect($returned->returned_to_treasurer_at)->not->toBeNull()
        // Still open: handing it on settles nothing.
        ->and($returned->status)->toBe('disputed')
        ->and($returned->followup_closed_at)->toBeNull()
        ->and(AppNotification::where('recipient_id', $this->treasurer->id)
            ->where('template_key', 'disbursement_disputed')->count())
        ->toBeGreaterThan($before);
});

it('refuses to hand back a line the household confirmed', function () {
    $line = paidLine($this->region, $this->officer, $this->treasurer, $this->director);
    $this->service->confirmReceipt($line, $line->beneficiary->user);

    expect(fn () => $this->service->returnToTreasurer($line->refresh(), $this->officer))
        ->toThrow(RuntimeException::class);
});

it('names the account that closed it, so the step has a hand behind it', function () {
    $line = paidLine($this->region, $this->officer, $this->treasurer, $this->director);
    $this->service->confirmReceipt($line, $line->beneficiary->user);
    $this->service->closeFollowUp($line->refresh(), $this->officer);

    expect($line->refresh()->followup_closed_by)->toBe($this->officer->id);
});
