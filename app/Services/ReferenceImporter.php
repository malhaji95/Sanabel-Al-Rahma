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
    public const RATE_COLUMNS = ['region_name_ar', 'person_class', 'amount', 'effective_from'];

    public const RENT_COLUMNS = ['region_name_ar', 'family_size_band', 'reference_rent', 'effective_from'];

    public function importRates(string $path, ?int $userId = null): array
    {
        return $this->import($path, self::RATE_COLUMNS,
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

    public function importRentReferences(string $path, ?int $userId = null): array
    {
        return $this->import($path, self::RENT_COLUMNS,
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

    /**
     * A CSV the admin fills in and hands straight back to the importer. Every
     * region is already on its own row, so nobody has to retype an Arabic
     * region name and have the import skip the line for not matching.
     */
    public function rateTemplate(): string
    {
        return $this->template(self::RATE_COLUMNS, ['adult', 'child', 'elderly']);
    }

    public function rentTemplate(): string
    {
        return $this->template(self::RENT_COLUMNS, ['1-3', '4-6', '7+']);
    }

    /**
     * @param  array<int,string>  $columns
     * @param  array<int,string>  $keys  the second column's value, one row each
     */
    private function template(array $columns, array $keys): string
    {
        $handle = fopen('php://temp', 'r+');

        // Excel reads a UTF-8 CSV as mojibake without a byte-order mark, and the
        // region names are Arabic. The importer trims the mark back off.
        fwrite($handle, "\xEF\xBB\xBF");
        // Explicit escape: PHP 8.4 deprecates relying on the default, and an
        // empty one is what RFC 4180 (and Excel) expects.
        fputcsv($handle, $columns, ',', '"', '');

        $from = now()->toDateString();

        foreach (Region::whereIn('type', ['governorate', 'area'])->orderBy('name_ar')->get() as $region) {
            foreach ($keys as $key) {
                fputcsv($handle, [$region->name_ar, $key, '', $from], ',', '"', '');
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
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
