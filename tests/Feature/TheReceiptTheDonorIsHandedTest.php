<?php

use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Services\DonationService;

/*
 | T-262 — «عرض للمتبرع ايصال عند التبرع», 8 November.
 |
 | Until now «إيصال» meant the slip the donor uploads to us. This is the one
 | we hand back, and the thing worth holding is that it tells the truth about
 | money that has not been checked yet: a receipt printed at the moment of
 | giving says it is waiting, and the same address says it was verified once
 | finance has matched it.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

function givenDonation(array $attributes = []): Donation
{
    static $ref = 0;

    return app(DonationService::class)->record(array_merge([
        'donor_id' => Donor::factory()->create(['name_ar' => 'أحمد خالد'])->id,
        'amount' => 100000,
        'transaction_ref' => 'TRX-RCPT-'.(++$ref),
    ], $attributes));
}

it('gives every donation an address of its own, unguessable and not an id', function () {
    $one = givenDonation();
    $two = givenDonation();

    expect($one->receipt_token)->not->toBeNull()
        ->and(strlen($one->receipt_token))->toBe(32)
        ->and($one->receipt_token)->not->toBe($two->receipt_token)
        // The number on the receipt is the one T-203 already issues.
        ->and($one->reference_no)->toStartWith('DN-');
});

it('says the money is waiting before anybody has checked it', function () {
    $donation = givenDonation();

    $body = $this->get(route('donation.receipt', ['token' => $donation->receipt_token]))
        ->assertOk()->getContent();

    expect($body)->toContain(__('sanabel.donation_receipt.statuses.pending'))
        ->and($body)->not->toContain(__('sanabel.donation_receipt.statuses.verified'));
});

it('says it was received and verified once finance has matched it', function () {
    $donation = givenDonation();

    app(DonationService::class)->verify($donation, userWithRole('executive_director')->id);

    $body = $this->get(route('donation.receipt', ['token' => $donation->receipt_token]))
        ->assertOk()->getContent();

    expect($body)->toContain(__('sanabel.donation_receipt.statuses.verified'));
});

it('carries the eight lines the association designed', function () {
    $donation = givenDonation();

    $body = $this->get(route('donation.receipt', ['token' => $donation->receipt_token]))
        ->assertOk()->getContent();

    expect($body)->toContain(__('sanabel.donation_receipt.heading'))
        ->and($body)->toContain($donation->reference_no)
        ->and($body)->toContain($donation->created_at->format('Y-m-d'))
        ->and($body)->toContain('أحمد خالد')
        ->and($body)->toContain(__('sanabel.donation_receipt.kinds.general'))
        ->and($body)->toContain(number_format($donation->amount))
        ->and($body)->toContain($donation->transaction_ref)
        // And the code that points back at this very page.
        ->and($body)->toContain('<svg')
        ->and($body)->toContain(__('sanabel.donation_receipt.verify'));
});

it('is opened without an account, because a guest gave too', function () {
    $donation = givenDonation();

    // No actingAs anywhere above: this is the guest's own reading of it.
    $this->get(route('donation.receipt', ['token' => $donation->receipt_token]))->assertOk();
});

it('refuses an address that was guessed', function () {
    givenDonation();

    $this->get(route('donation.receipt', ['token' => str_repeat('a', 32)]))->assertNotFound();
});

it('gives a reversing entry no receipt of its own', function () {
    $donation = givenDonation();
    $admin = userWithRole('executive_director');

    app(DonationService::class)->verify($donation, $admin->id);
    app(DonationService::class)->reverse($donation->refresh(), $admin->id, 'خطأ في المبلغ');

    $reversal = Donation::where('reversal_of_id', $donation->getKey())->firstOrFail();

    // The correcting entry has no page: money handed over once is not two
    // receipts. The original says so on its own.
    $this->get(route('donation.receipt', ['token' => $reversal->receipt_token]))->assertNotFound();

    $this->get(route('donation.receipt', ['token' => $donation->refresh()->receipt_token]))
        ->assertOk()
        ->assertSee(__('sanabel.donation_receipt.statuses.reversed'));
});

it('names no family on a page that gets forwarded', function () {
    $case = publishedCase($this->region);
    $donation = givenDonation();

    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $case->id,
        'amount' => $donation->amount,
        'currency' => config('sanabel.currency'),
        'coverage_month' => now()->startOfMonth(),
    ]);

    $body = $this->get(route('donation.receipt', ['token' => $donation->receipt_token]))
        ->assertOk()->getContent();

    // Rule 2 has no exception for a receipt: the purpose, never the family.
    expect($body)->not->toContain($case->file_number)
        ->and($body)->not->toContain($case->first_name)
        ->and($body)->not->toContain($case->family_name);
});
