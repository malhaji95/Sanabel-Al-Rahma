<?php

use App\Models\Region;
use App\Models\User;
use Database\Seeders\RegionSeeder;

/*
 | T-80 — the association's own region lists, sent on 13 October.
 |
 | Until they arrived, «a delegate sees their own locality» was a rule the code
 | enforced and nobody could use: the seeded tree held a governorate and five
 | areas and not one locality beneath them, so no delegate could be attached to
 | an account at all.
 |
 | Region names are data (CLAUDE.md §5), and these are theirs. The test holds
 | the shape rather than every name: that each area carries its localities, that
 | a delegate can be placed in one, and that the three kinds their lists
 | distinguish survive into the system.
 */

beforeEach(function () {
    seedCore();
    $this->seed(RegionSeeder::class);
});

it('seeds Daraa with the five areas they named', function () {
    $governorate = Region::where('type', 'governorate')->first();

    expect($governorate->name_ar)->toBe('درعا');

    $areas = Region::where('type', 'area')->pluck('name_ar');

    expect($areas)->toHaveCount(5);

    foreach (['درعا', 'إزرع', 'نوى', 'الصنمين', 'بصرى الشام'] as $area) {
        expect($areas)->toContain($area);
    }
});

it('puts every locality under its own area, and none loose', function () {
    $areaIds = Region::where('type', 'area')->pluck('id');

    $localities = Region::whereIn('type', ['city', 'town', 'village'])->get();

    expect($localities)->toHaveCount(141);

    foreach ($localities as $locality) {
        // in_array, not toContain with a message: toContain's later arguments
        // are more needles, not a failure message.
        expect($areaIds->contains($locality->parent_id))->toBeTrue(
            "{$locality->name_ar} hangs off no area"
        );
    }
});

it('keeps the three kinds of locality their lists distinguish', function () {
    // مدينة, بلدة and قرية are three levels in their own writing, and a tree
    // that flattened them would lose what they told us.
    expect(Region::where('type', 'city')->count())->toBe(12)
        ->and(Region::where('type', 'town')->count())->toBe(73)
        ->and(Region::where('type', 'village')->count())->toBe(56);

    foreach (['city', 'town', 'village'] as $type) {
        expect(__('sanabel.region_type.'.$type))->not->toBe('sanabel.region_type.'.$type);
    }
});

it('lets a delegate be attached at last, which nothing could do before', function () {
    $locality = Region::whereIn('type', ['city', 'town', 'village'])->first();

    $delegate = User::factory()->role('delegate')->create([
        'region_id' => $locality->id,
    ]);

    expect($delegate->fresh()->region_id)->toBe($locality->id);
})->group('AUTH-02');

it('still refuses a delegate an area or the governorate', function () {
    foreach (['area', 'governorate'] as $type) {
        $wide = Region::where('type', $type)->first();

        expect(fn () => User::factory()->role('delegate')->create(['region_id' => $wide->id]))
            ->toThrow(RuntimeException::class, '', "a delegate was given a {$type}");
    }
})->group('AUTH-02');

it('seeds the same tree twice without doubling it', function () {
    $before = Region::count();

    $this->seed(RegionSeeder::class);

    expect(Region::count())->toBe($before);
});

it('names a locality the association can be asked about, spelled as they wrote it', function () {
    // A spot check in each area, so a silent reshuffle of the file shows up.
    foreach ([
        'إزرع' => 'الشيخ مسكين',
        'نوى' => 'تسيل',
        'الصنمين' => 'غباغب',
        'بصرى الشام' => 'المسيفرة',
        'درعا' => 'خربة غزالة',
    ] as $area => $locality) {
        $parent = Region::where('type', 'area')->where('name_ar', $area)->first();

        expect(Region::where('parent_id', $parent->id)->where('name_ar', $locality)->exists())
            ->toBeTrue("{$locality} is not under {$area}");
    }
});
