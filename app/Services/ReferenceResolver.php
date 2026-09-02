<?php

namespace App\Services;

use App\Models\AdjustmentCatalog;
use App\Models\Region;
use App\Models\RegionRate;
use App\Models\RegionRentReference;
use App\Models\ScoringWeight;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Resolves the reference values in force on a given date, and reports the
 * versions it used so an assessment can snapshot them (rule 8).
 *
 * Reference values are looked up on the beneficiary's own region first, then
 * on each ancestor, so a village inherits its area's rates until it gets its own.
 */
class ReferenceResolver
{
    /** Weights that apply when no scoring_weights row is in force. */
    public const DEFAULT_WEIGHTS = [
        'F' => 0.25, 'M' => 0.20, 'V' => 0.15, 'H' => 0.10, 'U' => 0.15, 'D' => 0.10, 'B' => 0.05,
        'M_severity' => 0.45, 'M_economic_impact' => 0.25, 'M_care_burden' => 0.15, 'M_cost_burden' => 0.15,
        'V_dependents' => 60, 'V_single_caregiver' => 15, 'V_orphans' => 15, 'V_unsupported_elderly' => 10,
        'H_safety' => 0.35, 'H_overcrowding' => 0.20, 'H_services' => 0.15, 'H_eviction' => 0.15, 'H_rent_burden' => 0.15,
    ];

    /** @return array{amount:int,version:int|null,id:int|null} */
    public function rate(Region $region, string $personClass, CarbonInterface $asOf): array
    {
        foreach ($this->lineage($region) as $node) {
            $row = RegionRate::withoutGlobalScopes()
                ->where('region_id', $node->id)
                ->where('person_class', $personClass)
                ->whereDate('effective_from', '<=', $asOf)
                ->orderByDesc('effective_from')
                ->orderByDesc('version')
                ->first();

            if ($row) {
                return ['amount' => (int) $row->amount, 'version' => (int) $row->version, 'id' => $row->id];
            }
        }

        return ['amount' => 0, 'version' => null, 'id' => null];
    }

    /** @return array{amount:int,version:int|null,id:int|null,band:string} */
    public function rentReference(Region $region, int $familySize, CarbonInterface $asOf): array
    {
        $band = self::familySizeBand($familySize);

        foreach ($this->lineage($region) as $node) {
            $row = RegionRentReference::withoutGlobalScopes()
                ->where('region_id', $node->id)
                ->where('family_size_band', $band)
                ->whereDate('effective_from', '<=', $asOf)
                ->orderByDesc('effective_from')
                ->orderByDesc('version')
                ->first();

            if ($row) {
                return [
                    'amount' => (int) $row->reference_rent,
                    'version' => (int) $row->version,
                    'id' => $row->id,
                    'band' => $band,
                ];
            }
        }

        return ['amount' => 0, 'version' => null, 'id' => null, 'band' => $band];
    }

    /**
     * Adjustments in force for the region, keyed by catalogue key.
     *
     * @param  array<int,string>  $keys
     * @return array<string,array{amount:int,version:int|null,id:int|null}>
     */
    public function adjustments(Region $region, array $keys, CarbonInterface $asOf): array
    {
        $out = [];

        foreach ($keys as $key) {
            $regionIds = array_map(fn (Region $r) => $r->id, $this->lineage($region));

            $row = AdjustmentCatalog::withoutGlobalScopes()
                ->where('key', $key)
                ->where(fn ($q) => $q->whereIn('region_id', $regionIds)->orWhereNull('region_id'))
                ->whereDate('effective_from', '<=', $asOf)
                ->orderByRaw('region_id IS NULL')       // region-specific rows win over global ones
                ->orderByDesc('effective_from')
                ->orderByDesc('version')
                ->first();

            $out[$key] = $row
                ? ['amount' => (int) $row->amount, 'version' => (int) $row->version, 'id' => $row->id]
                : ['amount' => 0, 'version' => null, 'id' => null];
        }

        return $out;
    }

    /** @return array{values:array<string,float>,versions:array<string,int>} */
    public function weights(CarbonInterface $asOf): array
    {
        $values = self::DEFAULT_WEIGHTS;
        $versions = [];

        $rows = ScoringWeight::withoutGlobalScopes()
            ->whereDate('effective_from', '<=', $asOf)
            ->orderBy('effective_from')
            ->orderBy('version')
            ->get();

        foreach ($rows as $row) {
            $values[$row->factor_key] = (float) $row->weight;
            $versions[$row->factor_key] = (int) $row->version;
        }

        return ['values' => $values, 'versions' => $versions];
    }

    public static function familySizeBand(int $size): string
    {
        return match (true) {
            $size <= 3 => '1-3',
            $size <= 6 => '4-6',
            default => '7+',
        };
    }

    /** The region itself and every ancestor, nearest first. */
    private function lineage(Region $region): array
    {
        $chain = [];
        $node = Region::withoutGlobalScopes()->find($region->id);

        while ($node) {
            $chain[] = $node;
            $node = $node->parent_id
                ? Region::withoutGlobalScopes()->find($node->parent_id)
                : null;
        }

        return $chain;
    }

    public function now(): Carbon
    {
        return Carbon::now();
    }
}
