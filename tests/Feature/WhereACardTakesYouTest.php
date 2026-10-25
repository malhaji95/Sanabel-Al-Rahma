<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\DisbursementResource;
use App\Filament\Resources\DonationResource;
use App\Filament\Widgets\OverviewStats;
use App\Models\Beneficiary;
use App\Models\Disbursement;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Region;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

/*
 | T-124 — four of the association's notes of 26 October, all about a screen
 | that holds the answer and does not offer it:
 |
 |   · the money row that names a file number and will not open the file;
 |   · the donation that says «قيد المراجعة» and not what turns it into the
 |     other thing;
 |   · the hundred and forty-seven places in one filter;
 |   · the dashboard figure that cannot be pressed.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

it('opens the family file from the money row, for an account that may read it', function () {
    $chairman = userWithRole('board_director');
    $case = publishedCase($this->region);

    Disbursement::create([
        'beneficiary_id' => $case->id,
        'amount' => 50_000,
        'currency' => 'SYP',
        'period' => now()->startOfMonth(),
        'status' => 'confirmed',
    ]);

    $html = Livewire\Livewire::actingAs($chairman)
        ->test(DisbursementResource\Pages\ListDisbursements::class)
        ->assertSuccessful()
        ->html();

    expect($html)->toContain($case->file_number)
        ->and($html)->toContain(BeneficiaryResource::getUrl('edit', ['record' => $case->id]));
})->group('AUTH-01');

it('leaves the file number a plain string for an account that may not read it', function () {
    // The link is the permission. A provider holds no key to a family file,
    // so the row names what it is for and goes no further.
    $case = publishedCase($this->region);

    Disbursement::create([
        'beneficiary_id' => $case->id,
        'amount' => 50_000,
        'currency' => 'SYP',
        'period' => now()->startOfMonth(),
        'status' => 'confirmed',
    ]);

    $provider = userWithRole('service_provider');

    expect($provider->can('view', $case))->toBeFalse();
})->group('AUTH-02');

it('says on the donations screen what turns a pending gift into a confirmed one', function () {
    Donation::factory()->create([
        'donor_id' => Donor::factory()->create()->id,
        'status' => 'pending',
        'transaction_ref' => 'REF-PENDING-HELP',
    ]);

    $page = Livewire\Livewire::actingAs(userWithRole('board_director'))
        ->test(DonationResource\Pages\ListDonations::class)
        ->assertSuccessful();

    expect($page->instance()->getSubheading())->toBe(__('sanabel.donations.verify_help'));

    // And an account that cannot verify is told who does, rather than being
    // shown a button it does not have.
    $page = Livewire\Livewire::actingAs(userWithRole('council'))
        ->test(DonationResource\Pages\ListDonations::class)
        ->assertSuccessful();

    expect($page->instance()->getSubheading())->toBe(__('sanabel.donations.verify_help_readonly'));
})->group('AUTH-01');

it('filters the files by area first, then by the place inside it', function () {
    // The governorate the rates hang on, an area inside it, and a village
    // inside that — the shape the association's own lists have.
    $area = placeIn($this->region, 'area');
    $village = placeIn($this->region, 'village');
    $elsewhere = Region::create([
        'name_ar' => 'منطقة ثانية', 'type' => 'area',
        'parent_id' => $this->region->id, 'is_active' => true,
    ]);

    $here = publishedCase($this->region);
    $here->forceFill(['region_id' => $village->id])->save();

    $there = publishedCase($this->region);
    $there->forceFill(['region_id' => $elsewhere->id])->save();

    $list = Livewire\Livewire::actingAs(userWithRole('executive_director'))
        ->test(BeneficiaryResource\Pages\ListBeneficiaries::class);

    // The area alone is an answer: every file inside it.
    $list->set('tableFilters.place.area_id', $area->id)
        ->assertCanSeeTableRecords([$here])
        ->assertCanNotSeeTableRecords([$there]);

    // And the locality narrows it further.
    $list->set('tableFilters.place.region_id', $village->id)
        ->assertCanSeeTableRecords([$here]);
});

it('gives every number on the dashboard the screen it was counted from', function () {
    $method = new ReflectionMethod(OverviewStats::class, 'getStats');
    $method->setAccessible(true);

    $stats = collect($method->invoke(new OverviewStats));

    expect($stats)->not->toBeEmpty();

    /*
     | Every card but one. A hold is a basket, baskets have no screen in the
     | panel, and sending a reader to the donations list would be sending
     | them to money that has not arrived — so that card is a figure and the
     | test says which one rather than allowing any.
     */
    $without = $stats->filter(fn ($stat) => $stat->getUrl() === null)
        ->map(fn ($stat) => strip_tags((string) $stat->getLabel()))
        ->values();

    expect($without->all())->toBe([__('sanabel.dashboard.reserved_now')]);

    // And the one the association reads first lands on the files it counted,
    // already filtered — not on an unfiltered list they have to narrow again.
    $published = $stats->first();

    expect($published->getUrl())
        ->toContain(BeneficiaryResource::getUrl('index'))
        ->toContain('published');
});
