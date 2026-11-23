<?php

use App\Models\Disbursement;
use App\Models\FinancialPeriod;
use App\Services\DisbursementService;
use App\Services\PeriodClose;
use Illuminate\Support\Carbon;

/*
 | T-323 — phase two, item 9: «الإقفال».
 |
 | Nothing stopped a payment being raised for a month whose books had been
 | gone over and reported. So a figure the association had put its name to
 | could move after it had been read, and the report and the ledger would not
 | agree, with no row anywhere saying which came first.
 |
 | Closing is not a lock on the past: what a closed month refuses is the new.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->accountant = userWithRole('finance');
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->periods = app(PeriodClose::class);
    $this->lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();
});

it('closes a month that has ended, and records who closed it', function () {
    $row = $this->periods->close($this->lastMonth, $this->accountant);

    expect($row->isClosed())->toBeTrue()
        ->and($row->closed_by)->toBe($this->accountant->id)
        ->and($row->period->toDateString())->toBe($this->lastMonth->toDateString())
        ->and(FinancialPeriod::isClosedFor($this->lastMonth))->toBeTrue()
        // And the months either side are untouched.
        ->and(FinancialPeriod::isClosedFor(Carbon::today()->startOfMonth()))->toBeFalse();
});

it('refuses a new payment for a closed month, from the service and not from a screen', function () {
    $case = publishedCase($this->region);

    $this->periods->close($this->lastMonth, $this->accountant);

    expect(fn () => app(DisbursementService::class)
        ->confirm($case, 10000, $this->officer, $this->lastMonth->copy()))
        ->toThrow(RuntimeException::class);

    expect(Disbursement::where('beneficiary_id', $case->id)->count())->toBe(0);

    // The open month still takes one.
    $disbursement = app(DisbursementService::class)
        ->confirm($case, 10000, $this->officer, Carbon::today()->startOfMonth());

    expect($disbursement->status)->not->toBeEmpty();
});

it('leaves every payment already raised exactly as it was', function () {
    /*
     | «الإقفال يمنع الجديد ولا يُبطل القديم». A close that changed what was
     | already raised would be a rewrite of the month it claims to be sealing.
     */
    $case = publishedCase($this->region);

    $raised = app(DisbursementService::class)
        ->confirm($case, 10000, $this->officer, $this->lastMonth->copy());

    $before = $raised->fresh()->toArray();

    $this->periods->close($this->lastMonth, $this->accountant);

    expect($raised->fresh()->toArray())->toBe($before);
});

it('will not close a month that has not ended', function () {
    expect(fn () => $this->periods->close(Carbon::today(), $this->accountant))
        ->toThrow(RuntimeException::class, __('sanabel.period.not_ended'));

    expect(FinancialPeriod::count())->toBe(0);
});

it('will not close the same month twice', function () {
    $this->periods->close($this->lastMonth, $this->accountant);

    expect(fn () => $this->periods->close($this->lastMonth, $this->accountant))
        ->toThrow(RuntimeException::class, __('sanabel.period.already_closed'));

    expect(FinancialPeriod::count())->toBe(1);
});

it('reopens with the reason on the row, and keeps the closing written', function () {
    $this->periods->close($this->lastMonth, $this->accountant);

    $row = $this->periods->reopen($this->lastMonth, $this->accountant, 'وصل إيصالٌ متأخّر عن الشهر.');

    expect($row->isClosed())->toBeFalse()
        ->and($row->reopen_reason_ar)->toBe('وصل إيصالٌ متأخّر عن الشهر.')
        ->and($row->reopened_by)->toBe($this->accountant->id)
        // The closing is not erased: an auditor sees it was closed, when it
        // was reopened, and why.
        ->and($row->closed_at)->not->toBeNull()
        ->and($row->closed_by)->toBe($this->accountant->id);

    // And money flows into it again.
    $case = publishedCase($this->region);

    expect(app(DisbursementService::class)
        ->confirm($case, 10000, $this->officer, $this->lastMonth->copy())->id)
        ->toBeGreaterThan(0);
});

it('closes again after a reopening, and the later stamp decides', function () {
    $this->periods->close($this->lastMonth, $this->accountant);
    $this->periods->reopen($this->lastMonth, $this->accountant, 'إيصال متأخّر.');

    $this->travel(1)->minute();

    $row = $this->periods->close($this->lastMonth, $this->accountant);

    expect($row->isClosed())->toBeTrue()
        ->and($row->reopened_at)->not->toBeNull();
});

it('refuses a reopening with nothing written in it, and one of an open month', function () {
    $this->periods->close($this->lastMonth, $this->accountant);

    expect(fn () => $this->periods->reopen($this->lastMonth, $this->accountant, '  '))
        ->toThrow(RuntimeException::class, __('sanabel.period.reopen_reason_required'));

    expect(FinancialPeriod::isClosedFor($this->lastMonth))->toBeTrue();

    $other = Carbon::today()->subMonthsNoOverflow(3)->startOfMonth();

    expect(fn () => $this->periods->reopen($other, $this->accountant, 'سبب.'))
        ->toThrow(RuntimeException::class, __('sanabel.period.not_closed'));
});

it('keeps both decisions in the hand that reconciles the books', function () {
    expect(fn () => $this->periods->close($this->lastMonth, $this->officer))
        ->toThrow(RuntimeException::class, __('sanabel.period.not_permitted'));

    $this->periods->close($this->lastMonth, $this->accountant);

    expect(fn () => $this->periods->reopen($this->lastMonth, $this->officer, 'سبب.'))
        ->toThrow(RuntimeException::class, __('sanabel.period.not_permitted'));

    expect(FinancialPeriod::isClosedFor($this->lastMonth))->toBeTrue();
})->group('AUTH-01');
