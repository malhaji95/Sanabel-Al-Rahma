<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\DonationAllocation;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Coverage is money that has actually arrived: only `verified` donations count.
 * Pledges, reservations and unverified proofs never move these numbers
 * (docs/03-rules.md §3), and an unpaid sponsorship installment is not coverage.
 */
class CoverageService
{
    /** Verified money allocated to this family, net of reversals. */
    public function confirmedSupport(Beneficiary $beneficiary, ?Carbon $since = null): int
    {
        $query = DonationAllocation::query()
            ->where('donation_allocations.beneficiary_id', $beneficiary->getKey())
            ->join('donations', 'donations.id', '=', 'donation_allocations.donation_id')
            ->where('donations.status', 'verified')
            ->whereNull('donations.deleted_at');

        if ($since) {
            $query->where('donations.verified_at', '>=', $since);
        }

        // Credits and debits in one round trip. Two separate sums doubled the
        // query count on every list that shows coverage.
        $row = $query->selectRaw(
            'sum(case when donations.reversal_of_id is null'
            .' then donation_allocations.amount else 0 end) as credits,'
            .' sum(case when donations.reversal_of_id is not null'
            .' then donation_allocations.amount else 0 end) as debits'
        )->first();

        return max(0, (int) ($row->credits ?? 0) - (int) ($row->debits ?? 0));
    }

    /**
     * The same figure for many families in one query, keyed by beneficiary id.
     * A list that asks per family issues one query per case; the homepage and
     * the funding list both walk every published family.
     *
     * @param  iterable<int,Beneficiary>  $beneficiaries
     * @return array<int,int>
     */
    public function confirmedSupportForMany(iterable $beneficiaries): array
    {
        $ids = collect($beneficiaries)->map(fn (Beneficiary $b) => $b->getKey())->all();

        if ($ids === []) {
            return [];
        }

        return DonationAllocation::query()
            ->whereIn('donation_allocations.beneficiary_id', $ids)
            ->join('donations', 'donations.id', '=', 'donation_allocations.donation_id')
            ->where('donations.status', 'verified')
            ->whereNull('donations.deleted_at')
            ->groupBy('donation_allocations.beneficiary_id')
            ->selectRaw(
                'donation_allocations.beneficiary_id as beneficiary_id,'
                .' sum(case when donations.reversal_of_id is null'
                .' then donation_allocations.amount else 0 end) as credits,'
                .' sum(case when donations.reversal_of_id is not null'
                .' then donation_allocations.amount else 0 end) as debits'
            )
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->beneficiary_id => max(0, (int) $row->credits - (int) $row->debits),
            ])
            ->all();
    }

    /** The funding target: what the family still needs each month before any money arrives. */
    public function needAmount(Beneficiary $beneficiary): int
    {
        $assessment = $beneficiary->currentAssessment();

        if (! $assessment) {
            return 0;
        }

        return max(0, $assessment->monthly_need - $assessment->stable_income);
    }

    /** Verified money currently held against this family by a live basket reservation. */
    public function reservedAmount(Beneficiary $beneficiary): int
    {
        // As elsewhere, use the relation when a list has loaded it. Basket::isLive()
        // is the same condition the query below expresses.
        if ($beneficiary->relationLoaded('basketItems')) {
            return (int) $beneficiary->basketItems
                ->filter(fn ($item) => (bool) $item->basket?->isLive())
                ->sum('amount');
        }

        return (int) $beneficiary->basketItems()
            ->whereHas('basket', fn ($q) => $q
                ->where('status', 'reserved')
                ->where('reserved_until', '>', now()))
            ->sum('amount');
    }

    /** What a new reservation may still claim: need − confirmed − already reserved. */
    public function remainingNeed(Beneficiary $beneficiary, ?int $confirmed = null): int
    {
        return max(0, $this->needAmount($beneficiary)
            - ($confirmed ?? $this->confirmedSupport($beneficiary))
            - $this->reservedAmount($beneficiary));
    }

    /** 0.0 – 1.0 */
    /**
     * The optional $confirmed lets a caller that has already fetched the figure
     * pass it in. A card shows the percent, the label and the remaining amount,
     * which is three identical sums per case unless they share one lookup.
     */
    public function coverageRatio(Beneficiary $beneficiary, ?int $confirmed = null): float
    {
        $need = $this->needAmount($beneficiary);
        $confirmed ??= $this->confirmedSupport($beneficiary);

        return $need > 0 ? min(1.0, $confirmed / $need) : 1.0;
    }

    public function coveragePercent(Beneficiary $beneficiary, ?int $confirmed = null): int
    {
        return (int) round(100 * $this->coverageRatio($beneficiary, $confirmed));
    }

    public function coverageLabel(Beneficiary $beneficiary, ?int $confirmed = null): string
    {
        $ratio = $this->coverageRatio($beneficiary, $confirmed);

        return match (true) {
            $ratio <= 0.0 => 'none',
            $ratio >= 1.0 => 'full',
            default => 'partial',
        };
    }

    /** Support confirmed inside the deprivation window — the D factor's numerator. */
    public function confirmedSupportInWindow(Beneficiary $beneficiary): int
    {
        $days = (int) Setting::value(
            'deprivation_window_days',
            config('sanabel.setting_defaults.deprivation_window_days')
        );

        return $this->confirmedSupport($beneficiary, now()->subDays($days));
    }
}
