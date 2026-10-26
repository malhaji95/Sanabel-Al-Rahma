<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ListBeneficiaries;
use App\Models\Beneficiary;
use App\Models\Region;
use Filament\Facades\Filament;

/*
 | T-157 — «بصفحة مدير المنطقة ما نحتاج اظهار الكنية ممكن فقط الاسم الاول»,
 | 29 October.
 |
 | A supervisor reads the list to see how their area is moving; the file
 | number names the file and the area names the place. The surname is not on
 | that screen, and it is not searchable from it either — a name that cannot
 | be typed into a box cannot be fished out of one.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** One family, in an area with a supervisor over it. */
function familyUnderASupervisor(Region $governorate): array
{
    $delegate = userWithRole('delegate', ['region_id' => $governorate->id]);
    $village = Region::withoutGlobalScopes()->find($delegate->region_id);
    $area = $village->ancestorOfType('area');
    $supervisor = userWithRole('area_supervisor', ['region_id' => $area->id]);

    $case = familyOf($village, adults: 1, children: 0);
    $case->forceFill(['first_name' => 'سميرة', 'family_name' => 'الحلبي'])->save();

    return [$supervisor, $case];
}

it('shows the supervisor the first name and not the surname', function () {
    [$supervisor, $case] = familyUnderASupervisor($this->region);

    $this->actingAs($supervisor);

    Livewire\Livewire::actingAs($supervisor)
        ->test(ListBeneficiaries::class)
        ->assertCanSeeTableRecords([$case])
        ->assertSee('سميرة')
        ->assertDontSee('الحلبي');
});

it('still gives the case officer the family’s name', function () {
    [, $case] = familyUnderASupervisor($this->region);

    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->actingAs($officer);

    Livewire\Livewire::actingAs($officer)
        ->test(ListBeneficiaries::class)
        ->assertSee('الحلبي');
});

it('does not let the supervisor search the surname out of the list', function () {
    [$supervisor, $case] = familyUnderASupervisor($this->region);

    $this->actingAs($supervisor);

    Livewire\Livewire::actingAs($supervisor)
        ->test(ListBeneficiaries::class)
        ->searchTable('الحلبي')
        ->assertCanNotSeeTableRecords([$case]);
});

it('leaves the file itself where the supervisor can still open it', function () {
    [$supervisor, $case] = familyUnderASupervisor($this->region);

    expect($supervisor->can('view', Beneficiary::withoutGlobalScopes()->find($case->getKey())))->toBeTrue();
});
