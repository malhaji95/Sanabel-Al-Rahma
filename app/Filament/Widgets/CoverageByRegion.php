<?php

namespace App\Filament\Widgets;

use App\Models\Beneficiary;
use App\Services\CoverageService;
use Filament\Widgets\Widget;

/**
 * Coverage per region, added up.
 *
 * This widget used to carry the right heading over the wrong thing: a list of
 * individual files, one row each, under the title "coverage by region". The
 * association read it and said so. What it was asked for is the sum: for every
 * area, how many families, what they need between them, what has reached them
 * this month, and the percentage that leaves.
 *
 * Families hang off the deepest node of the region tree — a village or a town —
 * so each one is rolled up to its area, and to its governorate when it has no
 * area above it. The month is the one the coverage service considers current.
 */
class CoverageByRegion extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.coverage-by-region';

    /** @return array<int,array<string,mixed>> */
    public function getRows(): array
    {
        $coverage = app(CoverageService::class);

        // The region scope has already narrowed this to what the user may see.
        $cases = Beneficiary::published()->with('region.parent.parent.parent')->get();

        // One query for the whole page rather than one per family.
        $confirmedByCase = $coverage->confirmedForMonthForMany($cases);

        $rows = [];

        foreach ($cases as $case) {
            $region = $case->region?->ancestorOfType('area')
                ?? $case->region?->ancestorOfType('governorate')
                ?? $case->region;

            $key = $region?->getKey() ?? 0;

            $rows[$key] ??= [
                'region' => $region?->name_ar ?? __('sanabel.dashboard.region_unknown'),
                'families' => 0,
                'need' => 0,
                'paid' => 0,
            ];

            $rows[$key]['families']++;
            $rows[$key]['need'] += $coverage->needAmount($case);
            // Never more than the need: what arrived beyond it is carried to
            // the next month, so counting it here would read as over-coverage.
            $rows[$key]['paid'] += min(
                $confirmedByCase[$case->getKey()] ?? 0,
                $coverage->needAmount($case),
            );
        }

        foreach ($rows as $key => $row) {
            $rows[$key]['percent'] = $row['need'] > 0
                ? (int) round($row['paid'] / $row['need'] * 100)
                : 0;
        }

        usort($rows, fn (array $a, array $b) => $a['percent'] <=> $b['percent']);

        return $rows;
    }
}
