<?php

use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Services\CaseService;

/*
 | T-59 — PAY-05 of the security roadmap: two people on a change to where a
 | family's money is sent.
 |
 | The change-request machinery was already there and nothing forced the
 | wallet through it, so one account with panel access could redirect a
 | family's payments and the only trace would be the audit row. And nothing
 | stopped whoever raised a change from approving it themselves.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->admin = userWithRole('admin', ['region_id' => $this->region->id]);
    $this->cases = app(CaseService::class);
});

it('refuses a direct wallet edit on a live file, on every path', function () {
    $case = publishedCase($this->region);

    expect(fn () => $case->update(['wallet_encrypted' => '0999000111']))
        ->toThrow(RuntimeException::class);

    // forceFill bypasses the fillable list, not the guard: it is on the
    // model's own update, so the panel, the API and a console command are all
    // held to it.
    expect(fn () => $case->forceFill(['wallet_encrypted' => '0999000111'])->save())
        ->toThrow(RuntimeException::class);

    expect($case->fresh()->wallet_encrypted)->not->toBe('0999000111');
})->group('PAY-05');

it('lets a file still being built carry a wallet edit freely', function () {
    $draft = familyOf($this->region, adults: 1, children: 1);

    expect($draft->status)->toBe('draft');

    $draft->update(['wallet_encrypted' => '0999000111']);

    expect($draft->fresh()->wallet_encrypted)->toBe('0999000111');
})->group('PAY-05');

it('applies the change only once somebody else approves it', function () {
    $case = publishedCase($this->region);
    $before = $case->wallet_encrypted;

    $request = $this->cases->requestChange(
        $case,
        $this->officer,
        ['wallet_encrypted' => '0999000111'],
        'الأسرة بدلت المحفظة',
    );

    expect($request->status)->toBe('pending')
        ->and($request->touchesPaymentDetails())->toBeTrue()
        // Nothing moved yet.
        ->and($case->fresh()->wallet_encrypted)->toBe($before);

    $this->cases->approveChange($request, $this->admin);

    expect($case->fresh()->wallet_encrypted)->toBe('0999000111');
})->group('PAY-05');

it('refuses to let the person who raised it approve it', function () {
    $case = publishedCase($this->region);

    $request = $this->cases->requestChange(
        $case,
        $this->officer,
        ['wallet_encrypted' => '0999000111'],
        'الأسرة بدلت المحفظة',
    );

    expect(fn () => $this->cases->approveChange($request, $this->officer))
        ->toThrow(RuntimeException::class);

    expect($request->fresh()->status)->toBe('pending')
        ->and($case->fresh()->wallet_encrypted)->not->toBe('0999000111');
})->group('PAY-05');

it('holds that rule on every change, not only on a payment one', function () {
    $case = publishedCase($this->region);

    $request = $this->cases->requestChange(
        $case,
        $this->officer,
        ['support_type' => 'one_time'],
        'تغير وضع الأسرة',
    );

    expect(fn () => $this->cases->approveChange($request, $this->officer))
        ->toThrow(RuntimeException::class);
})->group('PAY-05');

it('closes the window again after the approved change is written', function () {
    $case = publishedCase($this->region);

    $this->cases->approveChange(
        $this->cases->requestChange($case, $this->officer, ['wallet_encrypted' => '0999000111'], 'سبب'),
        $this->admin,
    );

    // The allowance is scoped to that one write; the next direct edit is
    // refused exactly as before.
    expect(fn () => $case->fresh()->update(['wallet_encrypted' => '0999000222']))
        ->toThrow(RuntimeException::class);
})->group('PAY-05');

it('names the wallet as the only payment detail the file carries', function () {
    // The association decided on 29 September that a donor never pays a family
    // directly, so there is no second account on the file to protect.
    expect(ChangeRequest::PAYMENT_FIELDS)->toBe(['wallet_encrypted'])
        ->and(ChangeRequest::isPaymentChange(['wallet_encrypted' => '1']))->toBeTrue()
        ->and(ChangeRequest::isPaymentChange(['family_name' => 'x']))->toBeFalse();
})->group('AUTH-03');

it('leaves every other field on a live file editable', function () {
    $case = publishedCase($this->region);

    $case->update(['family_name' => 'اسم معدَّل']);

    expect($case->fresh()->family_name)->toBe('اسم معدَّل');
})->group('AUTH-03');

it('locks the wallet field on the screen for a live file and not for a draft', function () {
    expect(\App\Filament\Resources\BeneficiaryResource::paymentDetailsLocked(publishedCase($this->region)))
        ->toBeTrue()
        ->and(\App\Filament\Resources\BeneficiaryResource::paymentDetailsLocked(
            familyOf($this->region, adults: 1, children: 1)
        ))->toBeFalse()
        ->and(\App\Filament\Resources\BeneficiaryResource::paymentDetailsLocked(null))->toBeFalse();
})->group('PAY-05');
