<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * General money arrives with no family named: the donor left it to Sanabel
 * Al-Wafa to spend where the need is. This is how it is spent — one
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

    /*
     | T-263 — the money a door holds, and how it leaves.
     |
     | «مثلا كفالة حلقة القرآن وين راح يروح المبلغ ... المبالغ تروح على رقم
     | ملف الباب, تكون مقيدة له», 5 November. The answer the association's own
     | words give: it stays on the door until they place it on a family, and
     | what is placed may never exceed what was given.
     |
     | An allocation that names a campaign and no family is money sitting on
     | the door. Placing it does not write a second row against the same
     | donation — that would make the door's total read twice — it splits the
     | row it draws from: the part that is placed gains a family, the rest
     | stays where it was. So `campaignConfirmed()` never moves, the family's
     | coverage counts the part it was given, and the invariant the general
     | money screen rests on — a donation's allocations sum to the donation —
     | holds throughout.
     */

    /** What this door holds and has not placed on anybody yet. */
    public function doorBalance(Campaign $door): int
    {
        return (int) $this->unplaced($door)->sum('donation_allocations.amount');
    }

    /**
     * Places part of a door's money on one family for one month, drawing from
     * the oldest gift first.
     *
     * The rows are read inside the transaction and locked, so two people
     * placing the last of the same door cannot both succeed — rule 6, the
     * same guarantee the basket reservation gives.
     */
    public function allocateFromDoor(Campaign $door, Beneficiary $case, int $amount, Carbon $month, User $by): DonationAllocation
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('sanabel.general.amount_required'));
        }

        return DB::transaction(function () use ($door, $case, $amount, $month) {
            $rows = $this->unplaced($door)
                ->select('donation_allocations.*')
                ->orderBy('donation_allocations.id')
                ->lockForUpdate()
                ->get();

            if ($amount > (int) $rows->sum('amount')) {
                throw new \RuntimeException(__('sanabel.general.exceeds_door'));
            }

            $month = $month->copy()->startOfMonth();
            $left = $amount;
            $placed = null;

            foreach ($rows as $row) {
                if ($left <= 0) {
                    break;
                }

                $take = min($left, (int) $row->amount);
                $left -= $take;

                /*
                 | The whole of this gift goes to the family, so the row it is
                 | already on becomes the family's. Nothing is created and
                 | nothing is destroyed, which keeps the donation's own sum
                 | exact however many times a door is drawn on.
                 */
                if ($take === (int) $row->amount) {
                    $row->forceFill([
                        'beneficiary_id' => $case->getKey(),
                        'coverage_month' => $month,
                    ])->save();

                    $placed = $row;

                    continue;
                }

                $row->forceFill(['amount' => (int) $row->amount - $take])->save();

                $placed = DonationAllocation::create([
                    'donation_id' => $row->donation_id,
                    'beneficiary_id' => $case->getKey(),
                    'campaign_id' => $door->getKey(),
                    'coverage_month' => $month,
                    'amount' => $take,
                    'currency' => $row->currency,
                ]);
            }

            app(NotificationService::class)->send(
                $case->created_by,
                'coverage_updated',
                ['file_number' => $case->file_number],
            );

            return $placed;
        });
    }

    /** Verified, unreversed money on this door that names no family yet. */
    private function unplaced(Campaign $door)
    {
        return DonationAllocation::query()
            ->where('donation_allocations.campaign_id', $door->getKey())
            ->whereNull('donation_allocations.beneficiary_id')
            ->join('donations', 'donations.id', '=', 'donation_allocations.donation_id')
            ->where('donations.status', 'verified')
            ->whereNull('donations.reversal_of_id')
            ->whereNull('donations.deleted_at');
    }
}
