<?php

use App\Models\AppNotification;
use App\Models\Disbursement;
use App\Services\DisbursementService;
use App\Services\NotificationService;

/*
 | T-215 — «رسالة المستفيد تنرسل بعد تسجيل تنفيذ التحويل وإثباته, مو بمجرد
 | اعتماد أمر الصرف», 1 November.
 |
 | Half of that was already true and half was not. Approval told the
 | treasurer and nobody else, so no household was ever told about money that
 | had not moved. But execution told the accountant and the officer who
 | raised the line — and not the household, which learned of the transfer
 | only by opening its own page unprompted.
 |
 | The message carries the file number and no figure: rule 10 holds
 | notification bodies to the allowlist, and the amount sits on their own
 | page behind their own login, which is where the answer is given anyway.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->treasurer = userWithRole('treasurer');
    $this->director = userWithRole('executive_director');
});

/** A family with an account, and an approved line waiting to be paid. */
function lineReadyToPay($region, $officer, $treasurer, $director): Disbursement
{
    $case = publishedCase($region);
    $case->forceFill(['user_id' => userWithRole('beneficiary')->id])->save();

    $service = app(DisbursementService::class);

    // Whatever this family is actually short this month: the ceiling is the
    // assessment's, and a figure typed by hand drifts past it.
    $case = $case->refresh();
    $owed = $service->remainingForPeriod($case, now());

    $line = $service->confirm($case, $owed, $officer);
    $order = $service->gather([$line], $treasurer);
    $service->submit($order);
    $service->approve($order->refresh(), $director);

    return $line->refresh();
}

/** Messages of one template sitting for one account. */
function messagesFor(?int $userId, string $template)
{
    return AppNotification::where('recipient_id', $userId)
        ->where('template_key', $template)
        ->get();
}

it('tells nobody in the household when the order is merely approved', function () {
    $line = lineReadyToPay($this->region, $this->officer, $this->treasurer, $this->director);

    expect(messagesFor($line->beneficiary->user_id, 'disbursement_to_confirm'))->toHaveCount(0);
});

it('tells them once the transfer is recorded with its reference', function () {
    $line = lineReadyToPay($this->region, $this->officer, $this->treasurer, $this->director);

    app(DisbursementService::class)->execute($line, $this->treasurer, 'TRF-99001', proof: transferProof());

    $messages = messagesFor($line->beneficiary->user_id, 'disbursement_to_confirm');

    expect($messages)->not->toBeEmpty()
        ->and($messages->first()->payload_json['file_number'])->toBe($line->beneficiary->file_number);
});

/*
 | T-224 — the first draft of this message carried no figure, on the
 | reasoning that T-141 keeps every amount out of a notification body. That
 | reasoning holds for the five messages T-141 was written for, which go to
 | staff about other people's money. It does not hold for this one.
 |
 | The association said so in their own recording of 28 October: «تم تحويل
 | لك مثلا مبلغ, كل فقير حسب المبلغ اللي له». A household cannot confirm a
 | sum nobody told them.
 */
it('puts the figure in the message, which is what the household confirms', function () {
    $line = lineReadyToPay($this->region, $this->officer, $this->treasurer, $this->director);

    app(DisbursementService::class)->execute($line, $this->treasurer, 'TRF-99002', proof: transferProof());

    $message = messagesFor($line->beneficiary->user_id, 'disbursement_to_confirm')->first();

    expect($message->payload_json)->toHaveKey('amount')
        ->and($message->payload_json['amount'])->toContain(number_format($line->amount))
        // And it reads as a sentence, not as a token left unreplaced.
        ->and(app(NotificationService::class)->render($message)['body'])
        ->toContain(number_format($line->amount))
        ->not->toContain(':amount');
});

it('still puts no figure in the messages that go to the desks', function () {
    $line = lineReadyToPay($this->region, $this->officer, $this->treasurer, $this->director);

    app(DisbursementService::class)->execute($line, $this->treasurer, 'TRF-99005', proof: transferProof());

    // T-141 stands where it was written: a desk is told which order moved,
    // never how much somebody else was paid.
    $staff = messagesFor($this->officer->id, 'disbursement_executed')->first();

    expect($staff->payload_json)->not->toHaveKey('amount')
        ->and(json_encode($staff->payload_json))->not->toContain((string) $line->amount);
});

it('sends nothing to a household that has no account yet', function () {
    $case = publishedCase($this->region);
    $service = app(DisbursementService::class);

    $line = $service->confirm($case, $service->remainingForPeriod($case, now()), $this->officer);
    $order = $service->gather([$line], $this->treasurer);
    $service->submit($order);
    $service->approve($order->refresh(), $this->director);

    // No account, no recipient, and no crash on the way past it.
    $service->execute($line->refresh(), $this->treasurer, 'TRF-99003', proof: transferProof());

    expect($line->refresh()->status)->toBe('executed')
        ->and(AppNotification::where('template_key', 'disbursement_to_confirm')->count())->toBe(0);
});

it('still tells the accountant and the officer who asked, as it did before', function () {
    $line = lineReadyToPay($this->region, $this->officer, $this->treasurer, $this->director);

    app(DisbursementService::class)->execute($line, $this->treasurer, 'TRF-99004', proof: transferProof());

    expect(messagesFor($this->officer->id, 'disbursement_executed'))->not->toBeEmpty();
});
