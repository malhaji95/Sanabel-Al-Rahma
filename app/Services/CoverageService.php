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

        $credits = (int) (clone $query)->whereNull('donations.reversal_of_id')->sum('donation_allocations.amount');
        $debits = (int) (clone $query)->whereNotNull('donations.reversal_of_id')->sum('donation_allocations.amount');

        return max(0, $credits - $debits);
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
        return (int) $beneficiary->basketItems()
            ->whereHas('basket', fn ($q) => $q
                ->where('status', 'reserved')
                ->where('reserved_until', '>', now()))
            ->sum('amount');
    }

    /** What a new reservation may still claim: need − confirmed − already reserved. */
    public function remainingNeed(Beneficiary $beneficiary): int
    {
        return max(0, $this->needAmount($beneficiary)
            - $this->confirmedSupport($beneficiary)
            - $this->reservedAmount($beneficiary));
    }

    /** 0.0 – 1.0 */
    public function coverageRatio(Beneficiary $beneficiary): float
    {
        $need = $this->needAmount($beneficiary);

        return $need > 0 ? min(1.0, $this->confirmedSupport($beneficiary) / $need) : 1.0;
    }

    public function coveragePercent(Beneficiary $beneficiary): int
    {
        return (int) round(100 * $this->coverageRatio($beneficiary));
    }

    public function coverageLabel(Beneficiary $beneficiary): string
    {
        $ratio = $this->coverageRatio($beneficiary);

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
