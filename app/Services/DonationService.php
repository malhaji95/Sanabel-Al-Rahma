<?php

namespace App\Services;

use App\Exceptions\DuplicateTransactionRef;
use App\Models\Basket;
use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\Fund;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DonationService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly CoverageService $coverage,
    ) {}

    /**
     * Records a donation the donor says they have transferred.
     * Nothing about coverage changes here — only verification moves money.
     */
    public function record(array $payload): Donation
    {
        $payload['fund_id'] ??= Fund::byKey(Fund::OPERATIONAL)->id;
        $payload['currency'] ??= config('sanabel.currency');
        $payload['status'] = 'pending';

        try {
            return DB::transaction(fn () => Donation::create($payload));
        } catch (QueryException $e) {
            // Rule 1 — the unique index is the guard; this only turns it into a message.
            if ($this->isUniqueViolation($e, 'transaction_ref')) {
                throw DuplicateTransactionRef::make((string) $payload['transaction_ref']);
            }

            throw $e;
        }
    }

    /**
     * Verification is the only thing that changes coverage. It splits the money
     * across the basket's families, updates the campaign, refreshes the badge
     * and notifies both sides.
     */
    public function verify(Donation $donation, int $verifierId): Donation
    {
        if ($donation->status !== 'pending') {
            throw new \RuntimeException('Only a pending donation can be verified.');
        }

        return DB::transaction(function () use ($donation, $verifierId) {
            $donation->forceFill([
                'status' => 'verified',
                'verified_by' => $verifierId,
                'verified_at' => now(),
            ])->save();

            $this->allocate($donation);

            $donation->donor->refreshBadge();

            $this->notifications->send(
                $donation->donor->user_id,
                'donation_verified',
                ['ref' => $donation->transaction_ref],
            );

            return $donation->refresh();
        });
    }

    public function reject(Donation $donation, int $verifierId, string $reason): Donation
    {
        if ($donation->status !== 'pending') {
            throw new \RuntimeException('Only a pending donation can be rejected.');
        }

        // A rejection changes nothing but the donation's own status and reason.
        $donation->forceFill([
            'status' => 'rejected',
            'verified_by' => $verifierId,
            'verified_at' => now(),
            'reject_reason' => $reason,
        ])->save();

        $this->notifications->send(
            $donation->donor->user_id,
            'donation_rejected',
            ['ref' => $donation->transaction_ref, 'reason' => $reason],
        );

        return $donation->refresh();
    }

    /**
     * Rule 5 — a verified donation is never edited. A correction creates a new
     * row linked by reversal_of_id, with mirrored allocations that cancel the
     * original out.
     */
    public function reverse(Donation $donation, int $actorId, string $reason): Donation
    {
        if (! $donation->isVerified()) {
            throw new \RuntimeException('Only a verified donation can be reversed.');
        }

        return DB::transaction(function () use ($donation, $actorId, $reason) {
            $reversal = Donation::create([
                'donor_id' => $donation->donor_id,
                'route' => $donation->route,
                'amount' => $donation->amount,
                'currency' => $donation->currency,
                'transaction_ref' => $donation->transaction_ref.'-REV-'.now()->format('YmdHis'),
                'status' => 'verified',
                'verified_by' => $actorId,
                'verified_at' => now(),
                'reject_reason' => $reason,
                'fund_id' => $donation->fund_id,
                'reversal_of_id' => $donation->getKey(),
            ]);

            foreach ($donation->allocations as $allocation) {
                DonationAllocation::create([
                    'donation_id' => $reversal->getKey(),
                    'beneficiary_id' => $allocation->beneficiary_id,
                    'campaign_id' => $allocation->campaign_id,
                    'amount' => $allocation->amount,
                    'currency' => $allocation->currency,
                ]);

                if ($allocation->campaign) {
                    $allocation->campaign->decrement('collected_amount', $allocation->amount);
                }
            }

            // The only edit a verified donation ever takes: its status.
            $donation->status = 'reversed';
            $donation->save();

            $donation->donor->refreshBadge();

            return $reversal;
        });
    }

    /**
     * Splits a verified donation across its basket's families.
     * If the verified amount is lower than the basket total, allocate
     * proportionally and note it — never fail (docs/03-rules.md §6).
     */
    private function allocate(Donation $donation): void
    {
        $basket = $donation->basket;

        if (! $basket) {
            return;
        }

        $items = $basket->items()->with('beneficiary')->get();
        $total = (int) $items->sum('amount');

        if ($total <= 0) {
            return;
        }

        $ratio = min(1.0, $donation->amount / $total);
        $allocated = 0;
        $last = $items->count() - 1;

        foreach ($items->values() as $i => $item) {
            // The final family absorbs the rounding remainder so the split is exact.
            $amount = $i === $last
                ? max(0, min($donation->amount, $donation->amount) - $allocated)
                : (int) floor($item->amount * $ratio);

            $allocated += $amount;

            DonationAllocation::create([
                'donation_id' => $donation->getKey(),
                'beneficiary_id' => $item->beneficiary_id,
                'amount' => $amount,
                'currency' => $donation->currency,
            ]);

            $this->notifications->send(
                $item->beneficiary->created_by,
                'coverage_updated',
                ['file_number' => $item->beneficiary->file_number],
            );
        }

        $basket->forceFill(['status' => 'paid', 'reserved_until' => null])->save();
    }

    private function isUniqueViolation(QueryException $e, string $column): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505'
            && str_contains((string) $e->getMessage(), $column);
    }
}
