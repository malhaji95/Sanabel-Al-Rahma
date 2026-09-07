<?php

use App\Models\Region;
use App\Models\RegionRate;
use App\Models\RegionRentReference;
use App\Services\ReferenceImporter;

beforeEach(function () {
    seedCore();

    // Two regions, so the template proves it carries one row per region.
    regionWithRates();
    regionWithRates();
});

/*
 | T-03 — an admin loads a region's rates without a developer. The template is
 | half of that: it carries the exact columns and every region name already
 | spelled correctly, so the file that comes back matches what the importer
 | expects instead of being skipped line by line.
 */
it('hands out a template the importer accepts back', function () {
    $csv = app(ReferenceImporter::class)->rateTemplate();

    // Excel shows Arabic as mojibake without the byte-order mark.
    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('region_name_ar,person_class,amount,effective_from');

    $regions = Region::whereIn('type', ['governorate', 'area'])->get();
    expect($regions)->not->toBeEmpty();

    foreach ($regions as $region) {
        expect($csv)->toContain($region->name_ar);
    }

    // The admin fills the empty amount column in and saves it back as CSV.
    $filled = str_replace(
        [',adult,,', ',child,,', ',elderly,,'],
        [',adult,7000,', ',child,3000,', ',elderly,9000,'],
        $csv,
    );

    $path = tempnam(sys_get_temp_dir(), 'rates').'.csv';
    file_put_contents($path, $filled);

    $result = app(ReferenceImporter::class)->importRates($path);
    unlink($path);

    expect($result['skipped'])->toBeEmpty()
        ->and($result['imported'])->toBe($regions->count() * 3);

    $rate = RegionRate::withoutGlobalScopes()
        ->where('region_id', $regions->first()->id)
        ->where('person_class', 'adult')
        ->orderByDesc('version')->first();

    expect($rate->amount)->toBe(7000);
});

it('hands out a rent template the importer accepts back', function () {
    $csv = app(ReferenceImporter::class)->rentTemplate();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('region_name_ar,family_size_band,reference_rent,effective_from');

    $filled = str_replace(
        [',1-3,,', ',4-6,,', ',7+,,'],
        [',1-3,40000,', ',4-6,55000,', ',"7+",70000,'],
        $csv,
    );

    $path = tempnam(sys_get_temp_dir(), 'rent').'.csv';
    file_put_contents($path, $filled);

    $result = app(ReferenceImporter::class)->importRentReferences($path);
    unlink($path);

    $regions = Region::whereIn('type', ['governorate', 'area'])->count();

    expect($result['skipped'])->toBeEmpty()
        ->and($result['imported'])->toBe($regions * 3);

    expect(RegionRentReference::withoutGlobalScopes()
        ->where('family_size_band', '1-3')
        ->orderByDesc('version')->first()->reference_rent)->toBe(40000);
});

it('supersedes rather than overwrites, so old assessments keep their snapshot', function () {
    $region = Region::whereIn('type', ['governorate', 'area'])->first();

    $before = RegionRate::withoutGlobalScopes()
        ->where('region_id', $region->id)->where('person_class', 'adult')->count();

    $csv = "\xEF\xBB\xBF".'region_name_ar,person_class,amount,effective_from'."\n"
        .'"'.$region->name_ar.'",adult,12345,'.now()->toDateString()."\n";

    $path = tempnam(sys_get_temp_dir(), 'rates').'.csv';
    file_put_contents($path, $csv);
    app(ReferenceImporter::class)->importRates($path);
    unlink($path);

    $rows = RegionRate::withoutGlobalScopes()
        ->where('region_id', $region->id)->where('person_class', 'adult')
        ->orderByDesc('version')->get();

    expect($rows)->toHaveCount($before + 1)
        ->and($rows->first()->amount)->toBe(12345)
        ->and($rows->first()->version)->toBeGreaterThan($rows->get(1)->version);
});
