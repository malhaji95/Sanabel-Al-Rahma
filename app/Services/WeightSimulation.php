<?php

namespace App\Services;

use App\Models\Beneficiary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * T-297 — «توفير إمكانية اختبار أوزان جديدة دون تغيير نتائج الدراسات
 * المعتمدة», 12 November, and the 85/15 model the association says it is
 * still studying.
 *
 * It needs no engine. Rule 8 freezes each assessment's factors on the row
 * when it is computed — `factors_json` holds F, M, V, H, U, D and B, each
 * out of a hundred — and the score is nothing but those seven multiplied by
 * their weights and added up. So a different set of weights is a different
 * sum over figures already written down, and this service never computes a
 * need, never touches an assessment, and never writes anything at all.
 *
 * Which is the whole point: the association asked to see the effect of a
 * change before agreeing to it, and a tool that had to recompute studies to
 * answer would be the very thing they asked us not to do.
 */
class WeightSimulation
{
    /** The seven the score is made of. The sub-weights inside M, V and H are not these. */
    public const FACTORS = ['F', 'M', 'V', 'H', 'U', 'D', 'B'];

    /** A difference smaller than this is rounding, not a decision. */
    private const EPSILON = 0.011;

    public function __construct(private readonly ReferenceResolver $references) {}

    /**
     * The seven weights in force today, a stopped one reading nought exactly
     * as the engine reads it.
     *
     * @return array<string,float>
     */
    public function inForce(?Carbon $asOf = null): array
    {
        $values = $this->references->weights($asOf ?? Carbon::now())['values'];

        return collect(self::FACTORS)
            ->mapWithKeys(fn (string $k) => [$k => (float) ($values[$k] ?? 0.0)])
            ->all();
    }

    /**
     * Every file that ranks today, scored twice: as it stands, and as it
     * would stand under the weights proposed.
     *
     * @param  array<string,float>  $candidate
     * @return array{
     *     rows:array<int,array<string,mixed>>, total:int, up:int, down:int,
     *     same:int, stale:int, sum:float, biggest_rise:?array, biggest_fall:?array
     * }
     */
    public function run(array $candidate, ?Carbon $asOf = null): array
    {
        $inForce = $this->inForce($asOf);
        $candidate = $this->clean($candidate);

        $rows = $this->ranking()->map(function (object $file) use ($candidate, $inForce) {
            $factors = $this->factorsOf($file->factors_json);

            return [
                'id' => (int) $file->id,
                'file_number' => $file->file_number,
                // T-296 — the person, not the family.
                'name' => $file->first_name,
                'score_now' => (float) $file->base_score,
                'score_new' => $this->score($candidate, $factors),
                // What today's weights would give this file if it were scored
                // again. It is not always the score on the row: a study made
                // before the weights last changed keeps the ones it was made
                // with, which is rule 8 working, not a fault.
                'score_today' => $this->score($inForce, $factors),
                'factors' => $factors,
            ];
        });

        $rows = $this->rank($rows, 'score_now', 'rank_now');
        $rows = $this->rank($rows, 'score_new', 'rank_new');

        $rows = $rows->map(function (array $row) {
            // A file that climbs goes from rank 12 to rank 5, so the move is
            // the old rank less the new one: positive is upwards.
            $row['move'] = $row['rank_now'] - $row['rank_new'];

            return $row;
        })->sortBy([['rank_new', 'asc'], ['file_number', 'asc']])->values();

        return [
            'rows' => $rows->all(),
            'total' => $rows->count(),
            'up' => $rows->where('move', '>', 0)->count(),
            'down' => $rows->where('move', '<', 0)->count(),
            'same' => $rows->where('move', 0)->count(),
            'stale' => $rows->filter(
                fn (array $r) => abs($r['score_now'] - $r['score_today']) > self::EPSILON
            )->count(),
            'sum' => round(array_sum($candidate), 4),
            'biggest_rise' => $rows->sortByDesc('move')->first(fn (array $r) => $r['move'] > 0),
            'biggest_fall' => $rows->sortBy('move')->first(fn (array $r) => $r['move'] < 0),
        ];
    }

    /**
     * The same set the funding list ranks: the newest approved study on each
     * file the reader's region scope reaches, and no file without one.
     */
    private function ranking(): Collection
    {
        return Beneficiary::query()
            ->join('assessments as current_assessment', fn ($join) => $join->whereRaw(
                'current_assessment.id = (select max(a.id) from assessments a'
                ." where a.beneficiary_id = beneficiaries.id and a.status = 'approved'"
                .' and a.deleted_at is null)'
            ))
            ->select([
                'beneficiaries.id',
                'beneficiaries.file_number',
                'beneficiaries.first_name',
                'current_assessment.base_score',
                'current_assessment.factors_json',
            ])
            ->get()
            // Read as plain rows: the columns borrowed from the study are not
            // the beneficiary's own and carry none of its casts.
            ->map(fn ($row) => (object) $row->getAttributes());
    }

    /** @return array<string,float> */
    private function factorsOf(mixed $stored): array
    {
        $factors = is_array($stored) ? $stored : json_decode((string) $stored, true);

        if (! is_array($factors)) {
            $factors = [];
        }

        return collect(self::FACTORS)
            ->mapWithKeys(fn (string $k) => [$k => (float) ($factors[$k] ?? 0)])
            ->all();
    }

    /**
     * Exactly what the engine does with these seven, down to the bounds: a
     * score below nought or above a hundred is neither.
     *
     * @param  array<string,float>  $weights
     * @param  array<string,float>  $factors
     */
    private function score(array $weights, array $factors): float
    {
        $sum = 0.0;

        foreach (self::FACTORS as $key) {
            $sum += ($weights[$key] ?? 0.0) * ($factors[$key] ?? 0.0);
        }

        return round(min(100, max(0, $sum)), 2);
    }

    /**
     * Places, not positions: two files on the same score hold the same place
     * and the next one down takes the place after both. Otherwise a tie would
     * be broken by whatever the sort happened to do, and the screen would
     * report a family as having moved when nothing about it changed.
     */
    private function rank(Collection $rows, string $by, string $into): Collection
    {
        $ordered = $rows->sortByDesc($by)->values();
        $place = 0;
        $seen = 0;
        $last = null;

        $ranked = $ordered->map(function (array $row) use ($by, $into, &$place, &$seen, &$last) {
            $seen++;

            if ($last === null || abs($row[$by] - $last) > self::EPSILON) {
                $place = $seen;
                $last = $row[$by];
            }

            $row[$into] = $place;

            return $row;
        });

        return $ranked;
    }

    /**
     * Only the seven, only numbers, and never below nought. A weight above
     * one is left alone: the association may want to see what it does.
     *
     * @param  array<string,mixed>  $candidate
     * @return array<string,float>
     */
    private function clean(array $candidate): array
    {
        return collect(self::FACTORS)
            ->mapWithKeys(fn (string $k) => [$k => max(0.0, (float) ($candidate[$k] ?? 0))])
            ->all();
    }
}
