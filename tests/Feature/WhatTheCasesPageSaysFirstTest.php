<?php

use App\Livewire\BrowseCases;
use App\Models\Region;

/*
 | T-189 — «نحتاج وضع بي أعلى فوق كلمة الحالات» with the hadith written out,
 | «الفلاتر: جميع الحالات, دعم شهري, دعم لمرة واحدة», and «الفلاتر التي في
 | الأعلى فقط المناطق, لا نحتاج إظهار القرى» — 29 October.
 |
 | Their reason for the first: «هذا من باب التحفيز, عند الوصول إلى مرحلة
 | الاختيار تكون حافزًا له على الاستمرار في عملية التبرع».
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('opens with the hadith, its grading, and why the list is in this order', function () {
    Livewire\Livewire::test(BrowseCases::class)
        ->assertSee(__('sanabel.cases_intro.said'))
        ->assertSee(__('sanabel.cases_intro.hadith'))
        // A hadith without its grading is one nobody can check.
        ->assertSee(__('sanabel.cases_intro.grading'))
        ->assertSee(__('sanabel.cases_intro.ordering'))
        ->assertSee(__('sanabel.cases_intro.choose'));
});

it('lands a visitor on all the families rather than on half of them', function () {
    $monthly = publishedCase($this->region, attributes: ['support_type' => 'monthly']);
    $oneTime = publishedCase($this->region, attributes: ['support_type' => 'one_time']);

    Livewire\Livewire::test(BrowseCases::class)
        ->assertSet('supportType', 'all')
        ->assertSee($monthly->file_number)
        ->assertSee($oneTime->file_number);
});

it('still narrows to one kind when the donor asks for one', function () {
    $monthly = publishedCase($this->region, attributes: ['support_type' => 'monthly']);
    $oneTime = publishedCase($this->region, attributes: ['support_type' => 'one_time']);

    Livewire\Livewire::test(BrowseCases::class)
        ->set('supportType', 'monthly')
        ->assertSee($monthly->file_number)
        ->assertDontSee($oneTime->file_number)
        ->set('supportType', 'one_time')
        ->assertSee($oneTime->file_number)
        ->assertDontSee($monthly->file_number);
});

it('offers the areas in the filter and not the villages under them', function () {
    $village = placeIn($this->region, 'village');
    $area = $village->ancestorOfType('area');

    $offered = Livewire\Livewire::test(BrowseCases::class)->viewData('regions');

    expect($offered->keys()->all())->toContain($area->id)
        ->not->toContain($village->id);
});

it('finds a family in a village when its area is chosen', function () {
    $village = placeIn($this->region, 'village');
    $case = publishedCase($this->region);
    $case->forceFill(['region_id' => $village->id])->save();

    // Choosing an area covers every village under it: the ranking filters
    // down the tree, so the donor never has to know the village's name.
    Livewire\Livewire::test(BrowseCases::class)
        ->set('regionId', $village->ancestorOfType('area')->id)
        ->assertSee($case->file_number);
});

it('leaves the zakat filter narrowing whichever list is open', function () {
    $zakat = publishedCase($this->region, attributes: ['zakat_category' => 'poor']);
    $plain = publishedCase($this->region);

    Livewire\Livewire::test(BrowseCases::class)
        ->set('zakat', true)
        ->assertSee($zakat->file_number)
        ->assertDontSee($plain->file_number);
});

it('counts every published area in the filter, however many villages hang off it', function () {
    // A guard on the thing they complained about: one list of a hundred and
    // forty-seven places is not a filter anybody uses.
    $offered = Livewire\Livewire::test(BrowseCases::class)->viewData('regions');

    $areas = Region::where('is_active', true)->where('type', 'area')->count();

    expect($offered)->toHaveCount($areas);
});
