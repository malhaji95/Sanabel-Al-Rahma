<?php

use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\DisbursementFunding;
use App\Models\Donor;
use App\Models\Fund;
use App\Services\DisbursementService;
use Illuminate\Support\Facades\DB;

/*
 | T-213 — «لازم نمنع صرف نفس التبرع مرتين», 1 November.
 |
 | Money in and money out were two chains that never touched. A verified
 | donation earmarked for a family wrote an allocation row; a payment to
 | that family wrote a disbursement row; nothing joined them. The only
 | guard against paying twice was the family's own monthly need — enough
 | to stop two payments inside one month, and no help at all against one
 | donation of 100,000 answering November and then answering December.
 |
 | Now a payment draws its money from the family's earmarked donations,
 | oldest first, inside a locked transaction, and the database refuses a
 | draw past what the donor gave.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->service = app(DisbursementService::class);
    $this->case = publishedCase($this->region);
});

/** Verified money earmarked for this family. */
function earmark($case, int $amount): DonationAllocation
{
    $donation = Donation::factory()->create([
        'donor_id' => Donor::factory()->create()->id,
        'fund_id' => Fund::where('can_fund_families', true)->value('id'),
        'amount' => $amount,
        'status' => 'verified',
        'verified_at' => now(),
        'designation' => 'earmarked',
    ]);

    return DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $case->id,
        'amount' => $amount,
        'currency' => 'SYP',
    ]);
}

it('draws a payment out of the family’s earmarked money', function () {
    $allocation = earmark($this->case, 40_000);

    $line = $this->service->confirm($this->case, 10_000, $this->officer);

    expect($allocation->fresh()->drawn_amount)->toBe(10_000)
        ->and((int) DisbursementFunding::where('disbursement_id', $line->id)->sum('amount'))->toBe(10_000);
});

it('never lets two payments spend the same donation', function () {
    // One gift of 20,000, and two months of payments against it.
    $allocation = earmark($this->case, 20_000);

    $this->service->confirm($this->case, 12_000, $this->officer, now());
    $this->service->confirm($this->case, 12_000, $this->officer, now()->addMonth());

    // 24,000 of payments, and the donor gave 20,000: the second line takes
    // what is left and no more.
    expect($allocation->fresh()->drawn_amount)->toBe(20_000)
        ->and((int) DisbursementFunding::sum('amount'))->toBe(20_000);
});

it('takes the oldest gift first, which is the one that has waited', function () {
    $first = earmark($this->case, 5_000);
    $second = earmark($this->case, 5_000);

    $this->service->confirm($this->case, 7_000, $this->officer);

    expect($first->fresh()->drawn_amount)->toBe(5_000)
        ->and($second->fresh()->drawn_amount)->toBe(2_000);
});

it('pays a family with nothing earmarked, and says nothing was drawn', function () {
    $line = $this->service->confirm($this->case, 10_000, $this->officer);

    // The general fund has no row per donor to draw from. The payment is
    // real and simply unbacked by an earmarked gift.
    expect(DisbursementFunding::where('disbursement_id', $line->id)->count())->toBe(0)
        ->and($line->fresh()->status)->toBe('confirmed');
});

it('gives the money back when the line is set aside', function () {
    $allocation = earmark($this->case, 30_000);
    $line = $this->service->confirm($this->case, 10_000, $this->officer);

    expect($allocation->fresh()->drawn_amount)->toBe(10_000);

    // A line is only set aside out of an order it has joined.
    $order = $this->service->gather([$line], userWithRole('treasurer'));
    $this->service->submit($order);

    $this->service->reject($line->refresh(), userWithRole('executive_director'), 'أُجِّل هذا الشهر.');

    expect($allocation->fresh()->drawn_amount)->toBe(0)
        ->and(DisbursementFunding::where('disbursement_id', $line->id)->count())->toBe(0);
});

it('gives it back when the transfer bounces, so a bad account number costs nothing', function () {
    $allocation = earmark($this->case, 30_000);
    $director = userWithRole('executive_director');
    $treasurer = userWithRole('treasurer');

    $line = $this->service->confirm($this->case, 10_000, $this->officer);
    $order = $this->service->gather([$line], $treasurer);
    $this->service->submit($order);
    $this->service->approve($order->refresh(), $director);

    $this->service->fail($line->refresh(), 'رقم الحساب مغلق.');

    expect($allocation->fresh()->drawn_amount)->toBe(0);
});

it('refuses a draw past what the donor gave, at the database itself', function () {
    $allocation = earmark($this->case, 10_000);

    // Not through the service: straight at the row, which is where a bug
    // or a hand on the console would arrive.
    expect(fn () => DB::table('donation_allocations')
        ->where('id', $allocation->id)
        ->update(['drawn_amount' => 10_001]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('refuses a negative draw too', function () {
    $allocation = earmark($this->case, 10_000);

    expect(fn () => DB::table('donation_allocations')
        ->where('id', $allocation->id)
        ->update(['drawn_amount' => -1]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('ignores money that has not been verified yet', function () {
    $donation = Donation::factory()->create([
        'donor_id' => Donor::factory()->create()->id,
        'fund_id' => Fund::where('can_fund_families', true)->value('id'),
        'amount' => 50_000,
        'status' => 'pending',
        'designation' => 'earmarked',
    ]);

    $allocation = DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $this->case->id,
        'amount' => 50_000,
        'currency' => 'SYP',
    ]);

    $this->service->confirm($this->case, 10_000, $this->officer);

    // A pledge is not money. Nothing is drawn from it.
    expect($allocation->fresh()->drawn_amount)->toBe(0);
});
