<?php

namespace App\Services;

use App\Models\Scopes\RegionScope;
use App\Models\ScoringWeight;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 | T-315 — phase two, item 14: «التراجع عن إصدار».
 |
 | The weights are versioned and dated, and the screen has always let the
 | association publish a new set. What it has never let them do is go back.
 | Somebody who published a wrong seven this morning had one way out: read
 | yesterday's numbers off the audit log and type them in again, by hand,
 | seven times, hoping not to fat-finger one.
 |
 | So: name a date, and the weights that governed on that date are published
 | again as a new version in force from today.
 |
 | Nothing is deleted and no past row is touched. A study computed last August
 | keeps the snapshot it was computed from (hard rule 8), so restoring an old
 | set changes what happens next and never what already happened.
 */
class WeightRollback
{
    /**
     * Publishes the weights of `$asOf` as a new version effective today.
     *
     * @return array<string,array{from: float, to: float, stopped: bool}> the factors it changed, empty if none
     */
    public function restoreTo(Carbon $asOf, User $actor): array
    {
        if ($asOf->isFuture()) {
            throw new \RuntimeException(__('sanabel.weight.rollback_future'));
        }

        $then = $this->governing($asOf);
        $now = $this->governing(Carbon::today());

        $changes = [];

        foreach (WeightSimulation::FACTORS as $factor) {
            $was = $then[$factor];
            $is = $now[$factor];

            if ($this->same($was, $is)) {
                continue;
            }

            $changes[$factor] = [
                'from' => $is['weight'],
                'to' => $was['weight'],
                'stopped' => ! $was['is_active'],
            ];
        }

        if ($changes === []) {
            throw new \RuntimeException(__('sanabel.weight.rollback_no_change'));
        }

        DB::transaction(function () use ($changes, $then, $actor) {
            foreach (array_keys($changes) as $factor) {
                ScoringWeight::create([
                    'factor_key' => $factor,
                    'weight' => $then[$factor]['weight'],
                    'is_active' => $then[$factor]['is_active'],
                    'effective_from' => Carbon::today(),
                    'version' => $this->nextVersion($factor),
                    'created_by' => $actor->getKey(),
                ]);
            }
        });

        return $changes;
    }

    /**
     * What each factor stood at on a date: the row in force, or the default
     * where no row had been published yet.
     *
     * The value is read from the row rather than from the resolver, because
     * the resolver reports a stopped factor as nought — which is the right
     * answer for the arithmetic and the wrong one for copying a row forward:
     * restoring would turn «stopped at 0.15» into «running at 0».
     *
     * @return array<string,array{weight: float, is_active: bool}>
     */
    private function governing(Carbon $asOf): array
    {
        $state = [];

        foreach (WeightSimulation::FACTORS as $factor) {
            $state[$factor] = [
                'weight' => (float) (ReferenceResolver::DEFAULT_WEIGHTS[$factor] ?? 0.0),
                'is_active' => true,
            ];
        }

        $rows = ScoringWeight::withoutGlobalScope(RegionScope::class)
            ->whereDate('effective_from', '<=', $asOf)
            ->orderBy('effective_from')
            ->orderBy('version')
            ->get();

        foreach ($rows as $row) {
            if (! array_key_exists($row->factor_key, $state)) {
                continue;
            }

            $state[$row->factor_key] = [
                'weight' => (float) $row->weight,
                'is_active' => $row->is_active !== false,
            ];
        }

        return $state;
    }

    /**
     * @param  array{weight: float, is_active: bool}  $a
     * @param  array{weight: float, is_active: bool}  $b
     */
    private function same(array $a, array $b): bool
    {
        // The same epsilon the weights screen reads its sum with: a difference
        // this small is rounding, not a decision.
        return $a['is_active'] === $b['is_active']
            && abs($a['weight'] - $b['weight']) < 0.0005;
    }

    private function nextVersion(string $factor): int
    {
        return 1 + (int) ScoringWeight::withoutGlobalScope(RegionScope::class)
            ->withTrashed()
            ->where('factor_key', $factor)
            ->max('version');
    }
}
