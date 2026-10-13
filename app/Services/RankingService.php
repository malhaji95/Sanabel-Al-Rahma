<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Beneficiary;
use App\Models\Region;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * docs/03-rules.md §3.
 *
 *   Remaining    = max(0, (need − confirmed) ÷ need)
 *   CurrentScore = BaseScore × Remaining
 *   WaitingBonus = min(10, floor(waiting_days ÷ 7))
 *   Priority     = min(100, CurrentScore + WaitingBonus)
 *
 * Remaining = 0 removes the case from the funding list. The waiting bonus must
 * never resurrect a covered case, so the exclusion is applied first.
 */
class RankingService
{
    public function __construct(private readonly CoverageService $coverage) {}

    /** @return array{remaining:float,current_score:float,waiting_bonus:int,priority:float,eligible:bool} */
    public function rank(Beneficiary $beneficiary, ?int $confirmed = null): array
    {
        $assessment = $beneficiary->currentAssessment();
        $score = $assessment?->effectiveScore() ?? 0.0;

        $need = $this->coverage->needAmount($beneficiary);

        // fundingList() fetches the whole list's confirmed support in one query
        // and passes it in; on its own the call falls back to its own lookup.
        $confirmed ??= $this->coverage->confirmedForMonth($beneficiary);

        $remaining = $need > 0 ? max(0, ($need - $confirmed) / $need) : 0.0;
        $currentScore = $score * $remaining;
        $waitingBonus = $this->waitingBonus($beneficiary);

        // A fully covered case is out of the list; the bonus cannot bring it back.
        $eligible = $remaining > 0;

        return [
            'remaining' => round($remaining, 4),
            'current_score' => round($currentScore, 2),
            'waiting_bonus' => $waitingBonus,
            'priority' => $eligible ? round(min(100, $currentScore + $waitingBonus), 2) : 0.0,
            'eligible' => $eligible,
        ];
    }

    /**
     * What the family's surplus assets are reckoned to be worth.
     *
     * Reported, never acted on. Approved 11 October: an asset held as
     * necessary for the home, for work or for a living is recorded and lowers
     * nothing, whatever it is worth, so it is never in this total; nor is an
     * asset nobody has answered the question on, because unanswered is not
     * "surplus".
     *
     * On 12 October, asked for a value above which this total should bear on
     * eligibility, the association answered that there is no such value and
     * the committee judges each case by hand. There is therefore nothing here
     * that compares it to anything: rank() does not read it, and the figure
     * exists so a committee reads one number instead of adding up a list.
     */
    public function surplusAssetValue(Beneficiary $beneficiary): int
    {
        if ($beneficiary->relationLoaded('assets')) {
            return (int) $beneficiary->assets
                ->filter(fn (Asset $a) => $a->isSurplus())
                ->sum('estimated_value');
        }

        return (int) $beneficiary->assets()->where('necessity', 'surplus')->sum('estimated_value');
    }

    public function waitingBonus(Beneficiary $beneficiary): int
    {
        $since = $beneficiary->published_at ?? $beneficiary->approved_at ?? $beneficiary->created_at;

        if (! $since) {
            return 0;
        }

        return (int) min(10, intdiv((int) $since->diffInDays(now()), 7));
    }

    /**
     * The funding list for one support type. Monthly and one-time cases are
     * two separate lists in the donor UI.
     *
     * `$withCovered` is the donor list's own view of the same families, added
     * for the association's decision of 13 October: a family whose month is
     * answered stays on the screen, at the bottom, marked as complete, and
     * opens again for the next month once that month opens. The plain list is
     * untouched — §3 of docs/03-rules.md still takes a covered family out of
     * it, which is what the distribution engine and the home page read.
     *
     * @return Collection<int,array{beneficiary:Beneficiary,confirmed:int,need:int,month:Carbon,covered:bool,ranking:array}>
     */
    public function fundingList(string $supportType, ?int $regionId = null, bool $withCovered = false): Collection
    {
        $query = Beneficiary::query()
            ->published()
            ->where('support_type', $supportType)
            // Everything MaskedCaseResource reads, loaded once for the whole list.
            ->with(['assessments.overrides', 'region', 'members', 'housing', 'healthRecords', 'basketItems.basket', 'association']);

        if ($regionId) {
            $query->whereIn('region_id', Region::descendantIds($regionId));
        }

        $families = $query->get();

        // One batch of sums per open month rather than per family. The plain
        // list asks about this month only, so it costs exactly what it did.
        $months = $withCovered ? $this->coverage->openMonths() : [$this->coverage->currentMonth()];
        $funded = [];

        foreach ($months as $month) {
            $funded[$month->format('Y-m')] = $this->coverage->fundedForMonthForMany($families, $month);
        }

        return $families
            ->map(fn (Beneficiary $b) => $this->row($b, $months, $funded))
            // A family with no need recorded is not a funding opportunity at
            // all, in either list. A covered one is, in the donor's list only.
            ->filter(fn (array $row) => $row['need'] > 0 && ($withCovered || ! $row['covered']))
            ->sortByDesc(fn (array $row) => $this->listOrder($row))
            ->values();
    }

    /**
     * One row: the month a donor would be funding this family for, and that
     * month's figures.
     *
     * @param  array<int,Carbon>  $months
     * @param  array<string,array<int,int>>  $funded
     * @return array{beneficiary:Beneficiary,confirmed:int,need:int,month:Carbon,covered:bool,ranking:array}
     */
    private function row(Beneficiary $beneficiary, array $months, array $funded): array
    {
        $need = $this->coverage->needAmount($beneficiary);

        foreach ($months as $month) {
            $confirmed = $funded[$month->format('Y-m')][$beneficiary->getKey()] ?? 0;

            if ($need > 0 && $confirmed < $need) {
                return [
                    'beneficiary' => $beneficiary,
                    'confirmed' => $confirmed,
                    'need' => $need,
                    'month' => $month,
                    'covered' => false,
                    'ranking' => $this->rank($beneficiary, $confirmed),
                ];
            }
        }

        // Every open month is answered. The row carries the furthest of them,
        // so the card says «اكتملت تغطية» the last month a donor could have
        // funded rather than one already behind them.
        $month = end($months);
        $confirmed = $funded[$month->format('Y-m')][$beneficiary->getKey()] ?? 0;

        return [
            'beneficiary' => $beneficiary,
            'confirmed' => $confirmed,
            'need' => $need,
            'month' => $month,
            'covered' => true,
            'ranking' => $this->rank($beneficiary, $confirmed),
        ];
    }

    /**
     * Where a row sits on the donor's list.
     *
     * Three tiers, a thousand apart because a priority never exceeds 100: the
     * families still short this month, then the ones already answered for it
     * and open for the next, then the covered. Inside a tier the priority
     * decides, less the demotion a case past its reassessment date carries.
     *
     * Without that first separation a family covered for October and reopened
     * for November would arrive with nothing yet raised, score at its fullest,
     * and climb over every family still waiting for October — the opposite of
     * what the association asked for.
     */
    private function listOrder(array $row): float
    {
        $tier = match (true) {
            $row['covered'] => 2,
            $row['month']->isSameMonth($this->coverage->currentMonth()) => 0,
            default => 1,
        };

        return $row['ranking']['priority']
            - ($tier * 1_000)
            - ($this->isOverdue($row['beneficiary']) ? 100 : 0);
    }

    public function isOverdue(Beneficiary $beneficiary): bool
    {
        return $beneficiary->next_assessment_due_at !== null
            && $beneficiary->next_assessment_due_at->isPast();
    }
}
