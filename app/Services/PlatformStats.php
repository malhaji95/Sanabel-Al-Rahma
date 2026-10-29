<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Donation;
use App\Models\Region;
use Illuminate\Support\Facades\Cache;

/**
 * The figures the front page counts, and nothing that identifies a family.
 *
 * T-87 — it used to load every published family with its assessments to count
 * how many were covered, which is the homepage carrying the whole table on
 * every visit. It asks for the needs alone and counts what is still short: a
 * family with no approved assessment has no need and is covered, which is why
 * the covered figure is the total less the short rather than a filter over
 * models.
 *
 * T-183 — «نعم نريده أن يتحرك أمام العين بدون تحميل», 29 October. The strip
 * is a Livewire component now and asks again every few seconds, so the same
 * few queries would be run once per visitor per poll. The answer is cached
 * for a shorter time than the poll: every visitor sees a figure at most a
 * breath old, and the database answers once for all of them.
 */
class PlatformStats
{
    /** Shorter than the poll, so a poll finds a fresh answer rather than its own. */
    private const SECONDS = 10;

    public function __construct(
        private readonly RankingService $ranking,
        private readonly CoverageService $coverage,
    ) {}

    /** @return array<string,mixed> */
    public function all(): array
    {
        return Cache::remember('platform.stats', self::SECONDS, fn () => $this->compute());
    }

    /** @return array<string,mixed> */
    private function compute(): array
    {
        $needs = $this->ranking->publishedNeeds();
        $funded = $this->coverage->fundedForMonthByNeed($needs);

        $families = Beneficiary::published()->count();

        // T-89 — the five figures the design asks for, over the needs alone.
        $bands = ['critical' => 0, 'middle' => 0, 'low' => 0, 'complete' => 0];

        foreach ($needs as $id => $need) {
            $bands[$this->coverage->bandOf($need, $funded[$id] ?? 0)]++;
        }

        $bands['complete'] += $families - count($needs);

        /*
         | T-99 — the two money figures the association asked for on 15
         | October, and both are read from verified rows only: a transfer the
         | finance desk has matched to its notice. A pending one is not a
         | donation yet, and a figure on the front page that counted it would
         | be a claim the books cannot support.
         |
         | T-183 — «ونحن مع فكرة المبالغ لا تظهر إلا بعد تأكيد أمين الصندوق»,
         | 29 October. That is this line, and it has been this line since
         | T-99; the counter moving live does not loosen it.
         |
         | Rule 9 — integers in the smallest unit, formatted where they are
         | shown and never divided here.
         */
        $verified = Donation::where('status', 'verified');

        return [
            'families' => $families,
            'regions' => Region::where('is_active', true)->where('type', '!=', 'governorate')->count(),
            'covered' => $bands['complete'],
            'bands' => $bands,
            // What the «الدفع المباشر» card counts: families a donor could
            // give to right now.
            'open' => $families - $bands['complete'],
            'donated' => (int) (clone $verified)->sum('amount'),
            // «عداد التبرع المباشر»: how many transfers arrived and were
            // matched. A count beside the sum, so the page says both «how
            // much» and «from how many hands».
            'donations' => (clone $verified)->count(),
        ];
    }
}
