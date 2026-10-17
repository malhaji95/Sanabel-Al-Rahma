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
        return $this->priority(
            $beneficiary->currentAssessment()?->effectiveScore() ?? 0.0,
            $this->coverage->needAmount($beneficiary),
            // fundingList() fetches the whole list's confirmed support in one
            // query and passes it in; on its own the call falls back to its
            // own lookup.
            $confirmed ?? $this->coverage->confirmedForMonth($beneficiary),
            $this->waitingBonus($beneficiary),
        );
    }

    /**
     * docs/03-rules.md §3, written once.
     *
     * T-87 — the list stopped loading a model per family, and reads the four
     * figures below off a flat row instead. The formula did not move with it:
     * a second copy of the rule that decides who is helped first is the one
     * duplication this system cannot afford, so both paths land here.
     *
     * @return array{remaining:float,current_score:float,waiting_bonus:int,priority:float,eligible:bool}
     */
    private function priority(float $score, int $need, int $confirmed, int $waitingBonus): array
    {
        $remaining = $need > 0 ? max(0, ($need - $confirmed) / $need) : 0.0;
        $currentScore = $score * $remaining;

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
        return $this->waitingBonusSince(
            $beneficiary->published_at ?? $beneficiary->approved_at ?? $beneficiary->created_at
        );
    }

    /** The same, from the date itself, for the list's flat rows. */
    private function waitingBonusSince(?Carbon $since): int
    {
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
     * T-87 — it answers with identifiers and figures, not models. Ranking
     * needs a family's score, its need, its waiting time and its reassessment
     * date; a Beneficiary carries sixty-eight columns and this list used to
     * load every published one of them, with seven relations each, on every
     * request. `hydrate()` fetches the families a page actually shows.
     *
     * @return Collection<int,array{beneficiary_id:int,file_number:string,confirmed:int,need:int,month:Carbon,covered:bool,ranking:array}>
     */
    public function fundingList(
        string $supportType,
        ?int $regionId = null,
        bool $withCovered = false,
        bool $zakatOnly = false,
    ): Collection {
        $families = $this->rankingRows($supportType, $regionId, $zakatOnly);

        if ($families->isEmpty()) {
            return collect();
        }

        // The carry from last month is measured against the need, so the
        // batched sums are asked for by need rather than by model.
        $needs = $families->mapWithKeys(fn (object $f) => [(int) $f->id => $this->needOf($f)])->all();

        // One batch of sums per open month rather than per family. The plain
        // list asks about this month only, so it costs exactly what it did.
        $months = $withCovered ? $this->coverage->openMonths() : [$this->coverage->currentMonth()];
        $funded = [];

        foreach ($months as $month) {
            $funded[$month->format('Y-m')] = $this->coverage->fundedForMonthByNeed($needs, $month);
        }

        return $families
            ->map(fn (object $family) => $this->row($family, $months, $funded))
            // A family with no need recorded is not a funding opportunity at
            // all, in either list. A covered one is, in the donor's list only.
            ->filter(fn (array $row) => $row['need'] > 0 && ($withCovered || ! $row['covered']))
            ->sortByDesc(fn (array $row) => $this->listOrder($row))
            ->values();
    }

    /**
     * The families behind a page of ranked rows, loaded in full and in order.
     *
     * Everything MaskedCaseResource reads comes back here, for the dozen or so
     * rows a page shows — never for the whole list.
     *
     * @param  iterable<int,array>  $rows
     * @return Collection<int,array>
     */
    public function hydrate(iterable $rows): Collection
    {
        $rows = collect($rows);
        $ids = $rows->pluck('beneficiary_id')->all();

        if ($ids === []) {
            return collect();
        }

        $families = Beneficiary::query()
            ->whereIn('id', $ids)
            // T-101 — debts, assets and livestock joined the card on 15
            // October, so they are loaded with it. Without them the page
            // would ask three more questions per family.
            ->with([
                'assessments', 'region', 'members', 'housing', 'healthRecords',
                'basketItems.basket', 'association', 'debts', 'assets', 'livestock',
            ])
            ->get()
            ->keyBy('id');

        return $rows
            // A family that has slipped out from under the ranking — deleted
            // or unpublished between the two queries — is dropped rather than
            // drawn half empty.
            ->filter(fn (array $row) => $families->has($row['beneficiary_id']))
            ->map(fn (array $row) => $row + ['beneficiary' => $families[$row['beneficiary_id']]])
            ->values();
    }

    /**
     * One row per published family, carrying only what the ranking reads.
     *
     * The joins pick the family's current assessment and the live override on
     * it, which is what Beneficiary::currentAssessment() and
     * Assessment::effectiveScore() pick when the relations are loaded —
     * highest id wins in both. A family with no approved assessment has no
     * need and never appeared in this list, so the inner join leaves it out
     * where the filter used to.
     *
     * @return Collection<int,object>
     */
    private function rankingRows(?string $supportType, ?int $regionId, bool $zakatOnly = false): Collection
    {
        $query = Beneficiary::query()->published();

        /*
         | T-98 — «زكاة مستحقة»: the families the association has judged a
         | zakat payment may be spent on. The judgement is `zakat_category`,
         | one of the eight, and rule 2 keeps WHICH of them behind the
         | association's door — the donor is told only that zakat is accepted
         | on this file, which is the one thing they need in order to decide.
         */
        if ($zakatOnly) {
            $query->whereNotNull('beneficiaries.zakat_category');
        }

        if ($supportType) {
            // Qualified throughout: this is the one query in the system that
            // joins another table carrying columns of the same names.
            $query->where('beneficiaries.support_type', $supportType);
        }

        if ($regionId) {
            $query->whereIn('beneficiaries.region_id', Region::descendantIds($regionId));
        }

        return collect(
            // applyScopes() first: the region scope and the soft-delete scope
            // are global, and dropping to the base builder would shed them.
            $query->applyScopes()->toBase()
                ->select([
                    'beneficiaries.id',
                    'beneficiaries.file_number',
                    'beneficiaries.published_at',
                    'beneficiaries.approved_at',
                    'beneficiaries.created_at',
                    'beneficiaries.next_assessment_due_at',
                    'current_assessment.monthly_need',
                    'current_assessment.stable_income',
                    'current_assessment.base_score',
                ])
                ->join('assessments as current_assessment', fn ($join) => $join->whereRaw(
                    'current_assessment.id = (select max(a.id) from assessments a'
                    ." where a.beneficiary_id = beneficiaries.id and a.status = 'approved'"
                    .' and a.deleted_at is null)'
                ))
                ->get()
        );
    }

    /**
     * Every published family's monthly need, keyed by id, loading nothing else.
     *
     * The home page counts how many families are covered this month, and used
     * to load every published family with its assessments to do it. Families
     * with no approved assessment are absent here, exactly as they have no
     * need: the caller counts what is still short and subtracts.
     *
     * @return array<int,int>
     */
    public function publishedNeeds(): array
    {
        return $this->rankingRows(null, null)
            ->mapWithKeys(fn (object $family) => [(int) $family->id => $this->needOf($family)])
            ->all();
    }

    /** needAmount(), off a flat row. */
    private function needOf(object $family): int
    {
        return max(0, (int) $family->monthly_need - (int) $family->stable_income);
    }

    /**
     * One row: the month a donor would be funding this family for, and that
     * month's figures.
     *
     * @param  array<int,Carbon>  $months
     * @param  array<string,array<int,int>>  $funded
     * @return array{beneficiary_id:int,file_number:string,confirmed:int,need:int,month:Carbon,covered:bool,ranking:array}
     */
    private function row(object $family, array $months, array $funded): array
    {
        $id = (int) $family->id;
        $need = $this->needOf($family);

        // effectiveScore(): the score the assessment computed, and no other.
        // The hand-set override was removed on 16 October at the
        // association's instruction.
        $score = (float) $family->base_score;
        $waiting = $this->waitingBonusSince($this->waitingFrom($family));

        foreach ($months as $month) {
            $confirmed = $funded[$month->format('Y-m')][$id] ?? 0;

            if ($need > 0 && $confirmed < $need) {
                return [
                    'beneficiary_id' => $id,
                    'file_number' => (string) $family->file_number,
                    'confirmed' => $confirmed,
                    'need' => $need,
                    'month' => $month,
                    'covered' => false,
                    'overdue' => $this->overdue($family),
                    'ranking' => $this->priority($score, $need, $confirmed, $waiting),
                ];
            }
        }

        // Every open month is answered. The row carries the furthest of them,
        // so the card says «اكتملت تغطية» the last month a donor could have
        // funded rather than one already behind them.
        $month = end($months);
        $confirmed = $funded[$month->format('Y-m')][$id] ?? 0;

        return [
            'beneficiary_id' => $id,
            'file_number' => (string) $family->file_number,
            'confirmed' => $confirmed,
            'need' => $need,
            'month' => $month,
            'covered' => true,
            'overdue' => $this->overdue($family),
            'ranking' => $this->priority($score, $need, $confirmed, $waiting),
        ];
    }

    /** published_at ?? approved_at ?? created_at, off a flat row. */
    private function waitingFrom(object $family): ?Carbon
    {
        $since = $family->published_at ?? $family->approved_at ?? $family->created_at;

        return $since ? Carbon::parse($since) : null;
    }

    private function overdue(object $family): bool
    {
        return $family->next_assessment_due_at !== null
            && Carbon::parse($family->next_assessment_due_at)->isPast();
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
            - ($row['overdue'] ? 100 : 0);
    }

    public function isOverdue(Beneficiary $beneficiary): bool
    {
        return $beneficiary->next_assessment_due_at !== null
            && $beneficiary->next_assessment_due_at->isPast();
    }
}
