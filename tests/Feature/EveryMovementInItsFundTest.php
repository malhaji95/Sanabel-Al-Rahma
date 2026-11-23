<?php

use App\Filament\Pages\FundLedgerPage;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Fund;
use App\Services\DisbursementService;
use App\Services\DonationService;
use App\Services\FundLedger;
use App\Services\GeneralMoneyService;
use App\Services\PeriodClose;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 | T-324 — phase two, item 9: «السجلّ المالي».
 |
 | The money was all recorded and none of it was in one place: the transfer in
 | `donations`, what it was earmarked for in `donation_allocations`, the
 | payment in `disbursements`, the link between them in `disbursement_fundings`.
 |
 | So «how much is in the zakat fund today» was answered with a calculator, and
 | «how did it get to that» was answered by nobody.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->ledger = app(FundLedger::class);
    $this->verifier = userWithRole('finance');
    $this->operational = Fund::byKey(Fund::OPERATIONAL);
    $this->zakat = Fund::byKey(Fund::ZAKAT);
});

/** Money that arrived and was believed. */
function verifiedInto(Fund $fund, int $amount, string $ref): Donation
{
    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => $amount,
        'currency' => config('sanabel.currency'),
        'transaction_ref' => $ref,
        'fund_id' => $fund->id,
    ]);

    return app(DonationService::class)->verify($donation, test()->verifier->id);
}

it('lists what came in, oldest first, with the balance after each', function () {
    verifiedInto($this->operational, 100000, 'LED-001');
    $this->travel(1)->day();
    verifiedInto($this->operational, 250000, 'LED-002');

    $rows = $this->ledger->for($this->operational);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['kind'])->toBe('donation')
        ->and($rows[0]['amount'])->toBe(100000)
        ->and($rows[0]['balance'])->toBe(100000)
        ->and($rows[1]['amount'])->toBe(250000)
        ->and($rows[1]['balance'])->toBe(350000)
        ->and($rows[1]['ref'])->toBe('LED-002');

    expect($this->ledger->balance($this->operational))->toBe(350000);
});

it('keeps each fund to itself', function () {
    verifiedInto($this->operational, 100000, 'LED-010');
    verifiedInto($this->zakat, 400000, 'LED-011');

    expect($this->ledger->balance($this->operational))->toBe(100000)
        ->and($this->ledger->balance($this->zakat))->toBe(400000);

    expect(collect($this->ledger->for($this->zakat))->pluck('ref')->all())->toBe(['LED-011']);
});

it('counts a reversal as money going back out, not as an edit', function () {
    /*
     | Rule 5 — verified money is never edited. A correction is a donation of
     | its own pointing at the one it reverses, so the ledger shows both: the
     | money that arrived and the money that went back.
     */
    $donation = verifiedInto($this->operational, 100000, 'LED-020');

    $this->travel(1)->day();

    app(DonationService::class)->reverse($donation, $this->verifier->id, 'حوالة مكرّرة.');

    $rows = $this->ledger->for($this->operational);

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['kind'])->toBe('reversal')
        ->and($rows[1]['amount'])->toBe(-100000)
        ->and($rows[1]['balance'])->toBe(0)
        // And the row it reverses is still there, exactly as it was.
        ->and($rows[0]['amount'])->toBe(100000);

    expect($this->ledger->balance($this->operational))->toBe(0);
});

it('shows nothing for money that was never believed', function () {
    app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => 500000,
        'currency' => config('sanabel.currency'),
        'transaction_ref' => 'LED-030',
        'fund_id' => $this->operational->id,
    ]);

    expect($this->ledger->for($this->operational))->toBe([])
        ->and($this->ledger->balance($this->operational))->toBe(0);
});

it('marks a movement that falls inside a closed month', function () {
    /*
     | Marked and not hidden: a movement inside a closed month is exactly what
     | somebody checking a closed month came to read.
     */
    $lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();

    $this->travelTo($lastMonth->copy()->addDays(3));
    verifiedInto($this->operational, 100000, 'LED-040');

    $this->travelBack();
    verifiedInto($this->operational, 100000, 'LED-041');

    app(PeriodClose::class)->close($lastMonth, $this->verifier);

    $rows = collect($this->ledger->for($this->operational))->keyBy('ref');

    expect($rows['LED-040']['closed'])->toBeTrue()
        ->and($rows['LED-041']['closed'])->toBeFalse();
});

it('reads a window when it is given one', function () {
    $old = Carbon::today()->subMonthsNoOverflow(2);

    $this->travelTo($old);
    verifiedInto($this->operational, 100000, 'LED-050');

    $this->travelBack();
    verifiedInto($this->operational, 200000, 'LED-051');

    $window = $this->ledger->for($this->operational, Carbon::today()->subDays(2), Carbon::today());

    expect(collect($window)->pluck('ref')->all())->toBe(['LED-051']);
});

it('writes nothing and adds no table of its own', function () {
    verifiedInto($this->operational, 100000, 'LED-060');

    $donations = Donation::count();
    $audit = DB::table('audit_log')->count();

    $this->ledger->for($this->operational);
    $this->ledger->balance($this->zakat);

    expect(Donation::count())->toBe($donations)
        ->and(DB::table('audit_log')->count())->toBe($audit);
});

it('counts a payment out of the fund that funded it, by its own share', function () {
    /*
     | The other half of the ledger. A payment may draw on several donations,
     | so what leaves a fund is the funding line's amount and not the
     | payment's total: counting the total against every fund that touched it
     | is how a ledger comes to show more money leaving than ever arrived.
     */
    $family = publishedCase($this->region);

    // Earmarked money for this family, so a payment has something to draw on.
    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => 100000,
        'currency' => config('sanabel.currency'),
        'transaction_ref' => 'LED-070',
        'fund_id' => $this->operational->id,
    ]);
    app(DonationService::class)->verify($donation, $this->verifier->id);

    app(GeneralMoneyService::class)->allocate(
        $donation->refresh(),
        $family,
        10000,
        Carbon::today()->startOfMonth(),
        $this->verifier,
    );

    $service = app(DisbursementService::class);
    $line = $service->confirm($family, 10000, userWithRole('case_officer'));
    $order = $service->submit($service->gather([$line], userWithRole('treasurer')));
    $service->approve($order, userWithRole('executive_director'));
    $service->execute($order->disbursements()->firstOrFail(), userWithRole('treasurer'), 'LED-TRF-1', proof: transferProof());

    $rows = $this->ledger->for($this->operational);
    $out = collect($rows)->firstWhere('kind', 'disbursement');

    expect($out)->not->toBeNull()
        ->and($out['amount'])->toBe(-10000)
        ->and($out['ref'])->toBe('LED-TRF-1');

    // A hundred thousand in, ten thousand out.
    expect($this->ledger->balance($this->operational))->toBe(90000);
});

it('draws the fund and its balance on the screen', function () {
    verifiedInto($this->operational, 100000, 'LED-080');
    verifiedInto($this->zakat, 400000, 'LED-081');

    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    passTwoFactor();
    $this->actingAs($this->verifier);

    $page = Livewire\Livewire::test(FundLedgerPage::class);

    expect($page->html())
        ->toContain('LED-080')
        ->toContain(__('sanabel.ledger.kinds.donation'))
        ->toContain(number_format(100000))
        // The operational fund opens first, so the zakat movement is not on it.
        ->not->toContain('LED-081');

    // And the fund is changed from the screen.
    expect($page->call('showFund', Fund::ZAKAT)->html())
        ->toContain('LED-081')
        ->not->toContain('LED-080');
});

it('opens the ledger to the accounts that answer for the money, and not to a delegate', function () {
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    passTwoFactor();

    $this->actingAs($this->verifier);
    expect(FundLedgerPage::canAccess())->toBeTrue();

    $this->actingAs(userWithRole('treasurer'));
    expect(FundLedgerPage::canAccess())->toBeTrue();

    // It shows figures and no personal data, so oversight reads it too.
    $this->actingAs(userWithRole('oversight_director'));
    expect(FundLedgerPage::canAccess())->toBeTrue();

    $this->actingAs(userWithRole('donor'));
    expect(FundLedgerPage::canAccess())->toBeFalse();
})->group('AUTH-01');
