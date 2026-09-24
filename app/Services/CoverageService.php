<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Campaign;
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
     * The coverage cycle the association approved on 24 Sep 2026: the Gregorian
     * calendar month, starting at the beginning of each month.
     */
    public function currentMonth(): Carbon
    {
        return Carbon::now()->startOfMonth();
    }

    /**
     * The months a donor may fund right now. The next one opens a configurable
     * number of days before this one ends, so a family is not left with a gap
     * while the turn of the month is being funded.
     *
     * @return array<int,Carbon>
     */
    public function openMonths(): array
    {
        $current = $this->currentMonth();
        $lead = (int) Setting::value(
            'next_month_opens_days_before',
            config('sanabel.setting_defaults.next_month_opens_days_before')
        );

        $opensOn = $current->copy()->endOfMonth()->startOfDay()->subDays(max(0, $lead - 1));

        return Carbon::now()->startOfDay()->greaterThanOrEqualTo($opensOn)
            ? [$current, $current->copy()->addMonth()]
            : [$current];
    }

    public function monthIsOpen(Carbon $month): bool
    {
        return collect($this->openMonths())->contains(
            fn (Carbon $open) => $open->isSameMonth($month)
        );
    }

    /**
     * Verified money answering one month's need, net of reversals.
     *
     * Rows written before the cycle was defined carry no month; they are read
     * as belonging to the month the donation was verified in, so an existing
     * file does not suddenly read as uncovered.
     */
    public function confirmedForMonth(Beneficiary $beneficiary, ?Carbon $month = null): int
    {
        $month = ($month ?? $this->currentMonth())->copy()->startOfMonth();

        $row = DonationAllocation::query()
            ->where('donation_allocations.beneficiary_id', $beneficiary->getKey())
            ->join('donations', 'donations.id', '=', 'donation_allocations.donation_id')
            ->where('donations.status', 'verified')
            ->whereNull('donations.deleted_at')
            ->where(fn ($q) => $q
                ->whereDate('donation_allocations.coverage_month', $month->toDateString())
                ->orWhere(fn ($legacy) => $legacy
                    ->whereNull('donation_allocations.coverage_month')
                    ->whereBetween('donations.verified_at', [$month, $month->copy()->endOfMonth()])))
            ->selectRaw(
                'sum(case when donations.reversal_of_id is null'
                .' then donation_allocations.amount else 0 end) as credits,'
                .' sum(case when donations.reversal_of_id is not null'
                .' then donation_allocations.amount else 0 end) as debits'
            )
            ->first();

        return max(0, (int) ($row->credits ?? 0) - (int) ($row->debits ?? 0));
    }

    /** The same figure for many families in one query, keyed by beneficiary id. */
    public function confirmedForMonthForMany(iterable $beneficiaries, ?Carbon $month = null): array
    {
        $month = ($month ?? $this->currentMonth())->copy()->startOfMonth();
        $ids = collect($beneficiaries)->map(fn (Beneficiary $b) => $b->getKey())->all();

        if ($ids === []) {
            return [];
        }

        return DonationAllocation::query()
            ->whereIn('donation_allocations.beneficiary_id', $ids)
            ->join('donations', 'donations.id', '=', 'donation_allocations.donation_id')
            ->where('donations.status', 'verified')
            ->whereNull('donations.deleted_at')
            ->where(fn ($q) => $q
                ->whereDate('donation_allocations.coverage_month', $month->toDateString())
                ->orWhere(fn ($legacy) => $legacy
                    ->whereNull('donation_allocations.coverage_month')
                    ->whereBetween('donations.verified_at', [$month, $month->copy()->endOfMonth()])))
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

    /**
     * What a campaign has actually raised: its verified allocations, less any
     * that were reversed. Derived rather than stored, like a family's coverage,
     * so no counter can drift away from the allocations it is meant to total.
     */
    public function campaignConfirmed(Campaign $campaign): int
    {
        $row = DonationAllocation::query()
            ->where('donation_allocations.campaign_id', $campaign->getKey())
            ->join('donations', 'donations.id', '=', 'donation_allocations.donation_id')
            ->where('donations.status', 'verified')
            ->whereNull('donations.deleted_at')
            ->selectRaw(
                'sum(case when donations.reversal_of_id is null'
                .' then donation_allocations.amount else 0 end) as credits,'
                .' sum(case when donations.reversal_of_id is not null'
                .' then donation_allocations.amount else 0 end) as debits'
            )
            ->first();

        return max(0, (int) ($row->credits ?? 0) - (int) ($row->debits ?? 0));
    }

    /** The same figure for many campaigns in one query, keyed by campaign id. */
    public function campaignConfirmedForMany(iterable $campaigns): array
    {
        $ids = collect($campaigns)->map(fn ($c) => $c instanceof Campaign ? $c->getKey() : (int) $c)->all();

        if ($ids === []) {
            return [];
        }

        return DonationAllocation::query()
            ->whereIn('donation_allocations.campaign_id', $ids)
            ->join('donations', 'donations.id', '=', 'donation_allocations.donation_id')
            ->where('donations.status', 'verified')
            ->whereNull('donations.deleted_at')
            ->groupBy('donation_allocations.campaign_id')
            ->selectRaw(
                'donation_allocations.campaign_id,'
                .' sum(case when donations.reversal_of_id is null'
                .' then donation_allocations.amount else 0 end) as credits,'
                .' sum(case when donations.reversal_of_id is not null'
                .' then donation_allocations.amount else 0 end) as debits'
            )
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->campaign_id => max(0, (int) $row->credits - (int) $row->debits),
            ])
            ->all();
    }

    /** Money held against a campaign by a live basket reservation. */
    public function campaignReserved(Campaign $campaign): int
    {
        if ($campaign->relationLoaded('basketItems')) {
            return (int) $campaign->basketItems
                ->filter(fn ($item) => (bool) $item->basket?->isLive())
                ->sum('amount');
        }

        return (int) $campaign->basketItems()
            ->whereHas('basket', fn ($q) => $q
                ->where('status', 'reserved')
                ->where('reserved_until', '>', now()))
            ->sum('amount');
    }

    /** What a new pledge may still claim: goal − raised − already held. */
    public function campaignRemaining(Campaign $campaign, ?int $confirmed = null): int
    {
        return max(0, $campaign->goal_amount
            - ($confirmed ?? $this->campaignConfirmed($campaign))
            - $this->campaignReserved($campaign));
    }

    /** Verified money currently held against this family by a live basket reservation. */
    public function reservedAmount(Beneficiary $beneficiary, ?Carbon $month = null): int
    {
        $month = ($month ?? $this->currentMonth())->copy()->startOfMonth();

        // As elsewhere, use the relation when a list has loaded it. Basket::isLive()
        // is the same condition the query below expresses.
        if ($beneficiary->relationLoaded('basketItems')) {
            return (int) $beneficiary->basketItems
                ->filter(fn ($item) => (bool) $item->basket?->isLive()
                    && (string) $item->coverage_month?->format('Y-m') === $month->format('Y-m'))
                ->sum('amount');
        }

        return (int) $beneficiary->basketItems()
            ->whereDate('coverage_month', $month->toDateString())
            ->whereHas('basket', fn ($q) => $q
                ->where('status', 'reserved')
                ->where('reserved_until', '>', now()))
            ->sum('amount');
    }

    /**
     * What a new reservation may still claim for one month: need − what that
     * month has already received − what is held against it.
     */
    public function remainingNeed(Beneficiary $beneficiary, ?int $confirmed = null, ?Carbon $month = null): int
    {
        $month = ($month ?? $this->currentMonth())->copy()->startOfMonth();

        return max(0, $this->needAmount($beneficiary)
            - ($confirmed ?? $this->confirmedForMonth($beneficiary, $month))
            - $this->reservedAmount($beneficiary, $month));
    }

    /** 0.0 – 1.0 */
    /**
     * The optional $confirmed lets a caller that has already fetched the figure
     * pass it in. A card shows the percent, the label and the remaining amount,
     * which is three identical sums per case unless they share one lookup.
     */
    public function coverageRatio(Beneficiary $beneficiary, ?int $confirmed = null, ?Carbon $month = null): float
    {
        $need = $this->needAmount($beneficiary);
        // One month's need against that month's money. Comparing it against
        // everything the family ever received was the bug this replaces: a
        // family funded once read as covered for ever.
        $confirmed ??= $this->confirmedForMonth($beneficiary, $month);

        return $need > 0 ? min(1.0, $confirmed / $need) : 1.0;
    }

    public function coveragePercent(Beneficiary $beneficiary, ?int $confirmed = null, ?Carbon $month = null): int
    {
        return (int) round(100 * $this->coverageRatio($beneficiary, $confirmed, $month));
    }

    public function coverageLabel(Beneficiary $beneficiary, ?int $confirmed = null, ?Carbon $month = null): string
    {
        $ratio = $this->coverageRatio($beneficiary, $confirmed, $month);

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
