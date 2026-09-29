<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * General money arrives with no family named: the donor left it to Sanabel
 * Al-Rahma to spend where the need is. This is how it is spent — one
 * allocation at a time, against a balance that cannot be overdrawn.
 *
 * Earmarked money never passes through here; it is split across the basket's
 * files the moment the transfer is verified.
 */
class GeneralMoneyService
{
    /** What remains of one general donation after what has been allocated from it. */
    public function remaining(Donation $donation): int
    {
        return max(0, $donation->amount - (int) $donation->allocations()->sum('amount'));
    }

    /** Every verified general donation that still has money left to place. */
    public function undistributed()
    {
        return Donation::query()
            ->where('designation', 'general')
            ->where('status', 'verified')
            ->whereNull('reversal_of_id')
            ->with('donor')
            ->get()
            ->filter(fn (Donation $d) => $this->remaining($d) > 0)
            ->values();
    }

    /** The whole balance still waiting to be placed, across all general money. */
    public function balance(): int
    {
        return $this->undistributed()->sum(fn (Donation $d) => $this->remaining($d));
    }

    /**
     * Places part of a general donation on one family for one month.
     *
     * The balance is read inside the transaction with the row locked, so two
     * people placing the last of the same donation cannot both succeed — the
     * same guarantee the basket reservation gives (rule 6).
     */
    public function allocate(Donation $donation, Beneficiary $case, int $amount, Carbon $month, User $by): DonationAllocation
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('sanabel.general.amount_required'));
        }

        return DB::transaction(function () use ($donation, $case, $amount, $month) {
            $locked = Donation::whereKey($donation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->designation !== 'general' || $locked->status !== 'verified') {
                throw new \RuntimeException(__('sanabel.general.not_general'));
            }

            if ($amount > $this->remaining($locked)) {
                throw new \RuntimeException(__('sanabel.general.exceeds_remaining'));
            }

            $allocation = DonationAllocation::create([
                'donation_id' => $locked->getKey(),
                'beneficiary_id' => $case->getKey(),
                'coverage_month' => $month->copy()->startOfMonth(),
                'amount' => $amount,
                'currency' => $locked->currency,
            ]);

            app(NotificationService::class)->send(
                $case->created_by,
                'coverage_updated',
                ['file_number' => $case->file_number],
            );

            return $allocation;
        });
    }
}
