<?php

namespace App\Services;

use App\Models\Region;
use App\Models\RegionRate;
use App\Models\RegionRentReference;
use Illuminate\Support\Facades\DB;

/**
 * T-03 — bulk load of reference values so an admin adds a region's rates
 * without a developer.
 *
 * The format is CSV (what Excel exports with "Save as CSV"). Reading .xlsx
 * directly would need a new package, and CLAUDE.md §3 says not to add one
 * without asking — logged in docs/07-decisions.md.
 *
 * Every import writes a new version rather than editing rows in place, so a
 * stored assessment's snapshot stays true.
 */
class ReferenceImporter
{
    /** Columns: region_name_ar,person_class,amount,effective_from */
    public function importRates(string $path, ?int $userId = null): array
    {
        return $this->import($path, ['region_name_ar', 'person_class', 'amount', 'effective_from'],
            function (array $row, Region $region) use ($userId) {
                $version = 1 + (int) RegionRate::withoutGlobalScopes()
                    ->where('region_id', $region->id)
                    ->where('person_class', $row['person_class'])
                    ->max('version');

                RegionRate::create([
                    'region_id' => $region->id,
                    'person_class' => $row['person_class'],
                    'amount' => (int) $row['amount'],
                    'currency' => config('sanabel.currency'),
                    'effective_from' => $row['effective_from'],
                    'version' => $version,
                    'created_by' => $userId,
                ]);
            });
    }

    /** Columns: region_name_ar,family_size_band,reference_rent,effective_from */
    public function importRentReferences(string $path, ?int $userId = null): array
    {
        return $this->import($path, ['region_name_ar', 'family_size_band', 'reference_rent', 'effective_from'],
            function (array $row, Region $region) use ($userId) {
                $version = 1 + (int) RegionRentReference::withoutGlobalScopes()
                    ->where('region_id', $region->id)
                    ->where('family_size_band', $row['family_size_band'])
                    ->max('version');

                RegionRentReference::create([
                    'region_id' => $region->id,
                    'family_size_band' => $row['family_size_band'],
                    'reference_rent' => (int) $row['reference_rent'],
                    'currency' => config('sanabel.currency'),
                    'effective_from' => $row['effective_from'],
                    'version' => $version,
                    'created_by' => $userId,
                ]);
            });
    }

    /** @return array{imported:int,skipped:array<int,string>} */
    private function import(string $path, array $required, callable $write): array
    {
        $handle = fopen($path, 'rb');

        if (! $handle) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        $header = fgetcsv($handle);

        if (! $header) {
            throw new \RuntimeException('The file is empty.');
        }

        $header = array_map(fn ($h) => trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF"), $header);
        $missing = array_diff($required, $header);

        if ($missing) {
            throw new \RuntimeException('Missing columns: '.implode(', ', $missing));
        }

        $imported = 0;
        $skipped = [];
        $line = 1;

        DB::transaction(function () use ($handle, $header, $write, &$imported, &$skipped, &$line) {
            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                if ($values === [null] || $values === []) {
                    continue;
                }

                $row = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), null));
                $region = Region::withoutGlobalScopes()->where('name_ar', trim((string) $row['region_name_ar']))->first();

                if (! $region) {
                    $skipped[] = "line {$line}: unknown region";

                    continue;
                }

                $write($row, $region);
                $imported++;
            }
        });

        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }
}
