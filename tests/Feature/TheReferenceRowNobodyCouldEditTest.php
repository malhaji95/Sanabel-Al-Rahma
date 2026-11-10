<?php

use App\Filament\Resources\AdjustmentCatalogResource\Pages\EditAdjustmentCatalog;
use App\Filament\Resources\RegionRateResource\Pages\EditRegionRate;
use App\Filament\Resources\RegionRentReferenceResource\Pages\EditRegionRentReference;
use App\Models\AdjustmentCatalog;
use App\Models\RegionRate;
use App\Models\RegionRentReference;
use Filament\Facades\Filament;

/*
 | T-286 — «ظهر خطأ (تعذر إتمام الطلب) أثناء تعديل أحد البنود المرجعية»,
 | 10 November.
 |
 | The screen offered a region on the row, through a Filament relationship
 | whose relation the model never defined. So opening any adjustment to edit
 | it asked for a relation that did not exist and the page answered 500 —
 | every adjustment, every time. The association could read their own
 | reference values and never change one.
 |
 | The test is the page opening, because that is where it broke.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
    $this->actor = userWithRole('executive_director');
});

it('opens an adjustment that applies everywhere, which is how they are all stored', function () {
    $row = AdjustmentCatalog::create([
        'key' => 'children_present',
        'name_ar' => 'وجود أطفال',
        'amount' => 3000,
        'currency' => config('sanabel.currency'),
        'region_id' => null,
        'effective_from' => now()->startOfYear(),
        'version' => 1,
    ]);

    Livewire\Livewire::actingAs($this->actor)
        ->test(EditAdjustmentCatalog::class, ['record' => $row->getRouteKey()])
        ->assertSuccessful()
        ->assertFormSet(['key' => 'children_present', 'amount' => 3000]);
});

it('saves the new amount, which is the whole point of the screen', function () {
    $row = AdjustmentCatalog::create([
        'key' => 'elderly_present',
        'name_ar' => 'وجود مسنين',
        'amount' => 4000,
        'currency' => config('sanabel.currency'),
        'region_id' => $this->region->id,
        'effective_from' => now()->startOfYear(),
        'version' => 1,
    ]);

    Livewire\Livewire::actingAs($this->actor)
        ->test(EditAdjustmentCatalog::class, ['record' => $row->getRouteKey()])
        ->fillForm(['amount' => 7500, 'version' => 2])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($row->fresh()->amount)->toBe(7500)
        ->and($row->fresh()->version)->toBe(2);
});

it('opens the other two reference screens too, which were never broken', function () {
    $rate = RegionRate::where('region_id', $this->region->id)->firstOrFail();
    $rent = RegionRentReference::where('region_id', $this->region->id)->firstOrFail();

    Livewire\Livewire::actingAs($this->actor)
        ->test(EditRegionRate::class, ['record' => $rate->getRouteKey()])
        ->assertSuccessful();

    Livewire\Livewire::actingAs($this->actor)
        ->test(EditRegionRentReference::class, ['record' => $rent->getRouteKey()])
        ->assertSuccessful();
});

it('names a region on the row when one is set, and nothing when it applies everywhere', function () {
    $global = AdjustmentCatalog::create([
        'key' => 'shelter_household', 'name_ar' => 'مركز إيواء', 'amount' => 2000,
        'currency' => config('sanabel.currency'), 'region_id' => null,
        'effective_from' => now()->startOfYear(), 'version' => 1,
    ]);

    $local = AdjustmentCatalog::create([
        'key' => 'shelter_household', 'name_ar' => 'مركز إيواء', 'amount' => 2500,
        'currency' => config('sanabel.currency'), 'region_id' => $this->region->id,
        'effective_from' => now()->startOfYear(), 'version' => 1,
    ]);

    expect($global->region)->toBeNull()
        ->and($local->region?->getKey())->toBe($this->region->getKey());
});
