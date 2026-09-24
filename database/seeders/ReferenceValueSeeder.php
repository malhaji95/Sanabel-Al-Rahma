<?php

namespace Database\Seeders;

use App\Models\AdjustmentCatalog;
use App\Models\Region;
use App\Models\RegionRate;
use App\Models\RegionRentReference;
use App\Models\ScoringWeight;
use App\Services\ReferenceResolver;
use Illuminate\Database\Seeder;

/**
 * PLACEHOLDER VALUES.
 *
 * docs/07-decisions.md item 1: the real living and rent references per region
 * are still with the client and must be loaded before go-live. These rows exist
 * only so the engine has something to compute with in development.
 */
class ReferenceValueSeeder extends Seeder
{
    public function run(): void
    {
        $from = now()->startOfYear()->toDateString();

        foreach (Region::whereIn('type', ['governorate', 'area'])->get() as $region) {
            foreach (['adult' => 5000, 'wife' => 4000, 'child' => 2000, 'elderly' => 6000] as $class => $amount) {
                RegionRate::firstOrCreate(
                    ['region_id' => $region->id, 'person_class' => $class, 'effective_from' => $from],
                    ['amount' => $amount, 'version' => 1],
                );
            }

            foreach (['1-3' => 30000, '4-6' => 45000, '7+' => 60000] as $band => $rent) {
                RegionRentReference::firstOrCreate(
                    ['region_id' => $region->id, 'family_size_band' => $band, 'effective_from' => $from],
                    ['reference_rent' => $rent, 'version' => 1],
                );
            }
        }

        $adjustments = [
            ['children_present', 'وجود أطفال', 3000],
            ['elderly_present', 'وجود مسنين', 4000],
            ['unable_to_earn_present', 'وجود عاجز عن العمل', 5000],
            ['shelter_household', 'أسرة في مركز إيواء', 2000],
        ];

        foreach ($adjustments as [$key, $nameAr, $amount]) {
            AdjustmentCatalog::firstOrCreate(
                ['key' => $key, 'region_id' => null, 'effective_from' => $from],
                ['name_ar' => $nameAr, 'amount' => $amount, 'version' => 1],
            );
        }

        // The resolver falls back to these same numbers when no row is in
        // force, so seeding them changes no score. It does two other things:
        // the weights become visible and editable on the panel instead of
        // living only in code, and every assessment snapshot then records the
        // version of each weight it used, as rule 8 intends.
        foreach (ReferenceResolver::DEFAULT_WEIGHTS as $factor => $weight) {
            ScoringWeight::firstOrCreate(
                ['factor_key' => $factor, 'effective_from' => $from],
                ['weight' => $weight, 'version' => 1],
            );
        }
    }
}
