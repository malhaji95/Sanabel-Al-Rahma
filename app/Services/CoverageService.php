<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\DonationAllocation;
use App\Models\Setting;
use App\Models\SupportSource;
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

    /*
     | T-83 — the month a family is being funded for, and when that month is
     | answered.
     |
     | The association asked on 13 October that a family whose month is covered
     | should not vanish from the donor list: it drops to the bottom carrying
     | the word «مكتملة», and opens again for the new month with the month it
     | covers written on it. Both halves need the same two questions answered —
     | is this month covered, and if it is, which month is a donor funding now.
     */

    /**
     * Whether one month's need is already answered.
     *
     * A family with no need recorded reads as covered, exactly as
     * coverageRatio() does: there is nothing to fund, so nothing is missing.
     * Holds are deliberately not counted — money another donor is holding has
     * not arrived, and a hold that lapses must not leave a month looking shut.
     */
    public function monthIsCovered(Beneficiary $beneficiary, ?Carbon $month = null, ?int $funded = null): bool
    {
        $need = $this->needAmount($beneficiary);

        return $need <= 0 || ($funded ?? $this->fundedForMonth($beneficiary, $month)) >= $need;
    }

    /**
     * The month a donor would be funding this family for, that month's money,
     * and whether there is anything left to fund at all.
     *
     * Which months are open is the association's own setting: this one, and
     * the next once it opens — the lead time that keeps a family from falling
     * into a gap at the turn of the month.
     *
     * Month and money come back together because a card needs both, and
     * asking twice means walking the same months twice. A list that has
     * already batched its sums passes them to the card instead and never
     * reaches this.
     *
     * @return array{month:Carbon,funded:int,covered:bool}
     */
    public function fundingStage(Beneficiary $beneficiary): array
    {
        $month = $this->currentMonth();
        $funded = 0;

        foreach ($this->openMonths() as $month) {
            $funded = $this->fundedForMonth($beneficiary, $month);

            if (! $this->monthIsCovered($beneficiary, $month, $funded)) {
                return ['month' => $month, 'funded' => $funded, 'covered' => false];
            }
        }

        // Every open month is answered. The furthest of them is the one to
        // name, so a card says «اكتملت تغطية» the last month a donor could
        // have funded rather than one already behind them.
        return ['month' => $month, 'funded' => $funded, 'covered' => true];
    }

    /**
     * The first open month this family's need is not already answered for, or
     * null when every open month is.
     */
    public function fundableMonth(Beneficiary $beneficiary): ?Carbon
    {
        $stage = $this->fundingStage($beneficiary);

        return $stage['covered'] ? null : $stage['month'];
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

    /**
     * What another association or body actually paid towards this month's need.
     *
     * Approved 11 October: such a payment is treated exactly as a partial
     * donation — it answers the month it is recorded against and no other. It
     * is coverage, not income, so it never reaches stable income or the score,
     * and it is deliberately left out of the surplus carry below: a one-off
     * payment does not travel to the next month.
     */
    public function externalForMonth(Beneficiary $beneficiary, ?Carbon $month = null): int
    {
        $month = ($month ?? $this->currentMonth())->copy()->startOfMonth();

        if ($beneficiary->relationLoaded('supportSources')) {
            return (int) $beneficiary->supportSources
                ->filter(fn (SupportSource $s) => $s->isPayment()
                    && $s->coverage_month->isSameMonth($month))
                ->sum('amount');
        }

        return (int) SupportSource::query()
            ->where('beneficiary_id', $beneficiary->getKey())
            ->whereDate('coverage_month', $month->toDateString())
            ->sum('amount');
    }

    /** The same figure for many families in one query, keyed by beneficiary id. */
    public function externalForMonthForMany(iterable $beneficiaries, ?Carbon $month = null): array
    {
        $month = ($month ?? $this->currentMonth())->copy()->startOfMonth();
        $ids = collect($beneficiaries)->map(fn (Beneficiary $b) => $b->getKey())->all();

        if ($ids === []) {
            return [];
        }

        return SupportSource::query()
            ->whereIn('beneficiary_id', $ids)
            ->whereDate('coverage_month', $month->toDateString())
            ->groupBy('beneficiary_id')
            ->selectRaw('beneficiary_id, sum(amount) as paid')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->beneficiary_id => (int) $row->paid])
            ->all();
    }

    /**
     * Last month's surplus, carried into this one.
     *
     * The association decided on 6 October that a transfer larger than the
     * month's need is not paid twice and is not lost: it carries to the next
     * month. Only one month back, which is what they asked for, so this stays
     * a single lookup rather than a walk down the whole history.
     */
    public function carriedInto(Beneficiary $beneficiary, ?Carbon $month = null): int
    {
        $month = ($month ?? $this->currentMonth())->copy()->startOfMonth();

        return max(0, $this->confirmedForMonth($beneficiary, $month->copy()->subMonth())
            - $this->needAmount($beneficiary));
    }

    /**
     * What answers this month's need: this association's own verified money,
     * last month's surplus, and what another body paid for this month.
     */
    public function fundedForMonth(Beneficiary $beneficiary, ?Carbon $month = null): int
    {
        return $this->confirmedForMonth($beneficiary, $month)
            + $this->carriedInto($beneficiary, $month)
            + $this->externalForMonth($beneficiary, $month);
    }

    /**
     * The same figure for many families, in three queries rather than three
     * per family: this month's money, last month's, which the carry is read
     * from, and what other bodies paid for this month.
     *
     * @return array<int,int>
     */
    public function fundedForMonthForMany(iterable $beneficiaries, ?Carbon $month = null): array
    {
        $families = collect($beneficiaries);
        $month = ($month ?? $this->currentMonth())->copy()->startOfMonth();

        $thisMonth = $this->confirmedForMonthForMany($families, $month);
        $lastMonth = $this->confirmedForMonthForMany($families, $month->copy()->subMonth());
        $external = $this->externalForMonthForMany($families, $month);

        return $families->mapWithKeys(function (Beneficiary $family) use ($thisMonth, $lastMonth, $external) {
            $id = $family->getKey();
            $carried = max(0, ($lastMonth[$id] ?? 0) - $this->needAmount($family));

            return [$id => ($thisMonth[$id] ?? 0) + $carried + ($external[$id] ?? 0)];
        })->all();
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

    /*
     | T-78 — the funding target, decided by the association on 13 October.
     |
     | A target is a share of the real monthly need, shown to the donor so a
     | family can be helped in stages. It is a display layer and nothing more:
     | the engine, the score, the priority badge, the disbursement ceiling and
     | the association's own coverage reports all go on reading the real need,
     | because a family that has reached 100% of a 30% target is still short
     | 70% of what it needs, and every one of those would lie if it read the
     | target instead.
     */
    public function targetPercent(Beneficiary $beneficiary): int
    {
        $region = $beneficiary->region;

        // The region's own share, else its parent's, else the system setting.
        while ($region) {
            if ($region->funding_target_percent !== null) {
                return $this->clampPercent((int) $region->funding_target_percent);
            }

            $region = $region->parent;
        }

        return $this->clampPercent((int) Setting::value(
            'funding_target_percent',
            config('sanabel.setting_defaults.funding_target_percent')
        ));
    }

    /** The amount the donor is asked for: a share of the need, in whole units. */
    public function targetAmount(Beneficiary $beneficiary): int
    {
        return intdiv($this->needAmount($beneficiary) * $this->targetPercent($beneficiary), 100);
    }

    /**
     * What is still missing from the target. Never more than what the month
     * can still take: a target cannot ask for money the need no longer wants.
     */
    public function targetRemaining(Beneficiary $beneficiary, ?int $confirmed = null, ?Carbon $month = null): int
    {
        $month = ($month ?? $this->currentMonth())->copy()->startOfMonth();
        $funded = $confirmed ?? $this->fundedForMonth($beneficiary, $month);

        return min(
            max(0, $this->targetAmount($beneficiary) - $funded),
            $this->remainingNeed($beneficiary, $funded, $month),
        );
    }

    public function targetIsComplete(Beneficiary $beneficiary, ?int $confirmed = null, ?Carbon $month = null): bool
    {
        $target = $this->targetAmount($beneficiary);

        if ($target <= 0) {
            return true;
        }

        return ($confirmed ?? $this->fundedForMonth($beneficiary, $month)) >= $target;
    }

    /** 0 – 100, so a mistyped setting cannot ask for more than the need. */
    private function clampPercent(int $percent): int
    {
        return max(0, min(100, $percent));
    }

    /**
     * What a new reservation may still claim for one month: need − what that
     * month has already received − what is held against it.
     */
    public function remainingNeed(Beneficiary $beneficiary, ?int $confirmed = null, ?Carbon $month = null): int
    {
        $month = ($month ?? $this->currentMonth())->copy()->startOfMonth();

        return max(0, $this->needAmount($beneficiary)
            - ($confirmed ?? $this->fundedForMonth($beneficiary, $month))
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
        $confirmed ??= $this->fundedForMonth($beneficiary, $month);

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

    /**
     * The priority band a donor reads on the card, from how much of the need is
     * still uncovered: the less that has arrived, the higher the priority. The
     * two thresholds are settings, so the association retunes them without a
     * release.
     */
    public function priorityBand(Beneficiary $beneficiary, ?int $confirmed = null, ?Carbon $month = null): string
    {
        $percent = $this->coveragePercent($beneficiary, $confirmed, $month);

        $critical = (int) Setting::value(
            'priority_critical_below',
            config('sanabel.setting_defaults.priority_critical_below')
        );
        $middle = (int) Setting::value(
            'priority_middle_below',
            config('sanabel.setting_defaults.priority_middle_below')
        );

        return match (true) {
            $percent >= 100 => 'complete',
            $percent < $critical => 'critical',
            $percent < $middle => 'middle',
            default => 'low',
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
