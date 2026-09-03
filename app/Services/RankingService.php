<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Region;
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
        $confirmed ??= $this->coverage->confirmedSupport($beneficiary);

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
     * @return Collection<int,array{beneficiary:Beneficiary,confirmed:int,ranking:array}>
     */
    public function fundingList(string $supportType, ?int $regionId = null): Collection
    {
        $query = Beneficiary::query()
            ->published()
            ->where('support_type', $supportType)
            // Everything MaskedCaseResource reads, loaded once for the whole list.
            ->with(['assessments.overrides', 'region', 'members', 'housing', 'healthRecords', 'basketItems.basket']);

        if ($regionId) {
            $query->whereIn('region_id', Region::descendantIds($regionId));
        }

        $families = $query->get();
        $confirmed = $this->coverage->confirmedSupportForMany($families);

        return $families
            ->map(fn (Beneficiary $b) => [
                'beneficiary' => $b,
                'confirmed' => $confirmed[$b->getKey()] ?? 0,
                'ranking' => $this->rank($b, $confirmed[$b->getKey()] ?? 0),
            ])
            ->filter(fn (array $row) => $row['ranking']['eligible'])
            // A case past its reassessment date stays in the list but is demoted.
            ->sortByDesc(fn (array $row) => $row['ranking']['priority'] - ($this->isOverdue($row['beneficiary']) ? 100 : 0))
            ->values();
    }

    public function isOverdue(Beneficiary $beneficiary): bool
    {
        return $beneficiary->next_assessment_due_at !== null
            && $beneficiary->next_assessment_due_at->isPast();
    }
}
