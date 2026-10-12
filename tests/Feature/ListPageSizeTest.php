<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ListBeneficiaries;
use App\Filament\Resources\DonationResource\Pages\ListDonations;

use function Pest\Livewire\livewire;

/*
 | T-60 — AUTH-04 of the security roadmap: a bulk read has a ceiling.
 |
 | Filament's own default page-size list offers "all". On the families table or
 | the donations table that means one click pulls every row the account can
 | reach into a single response — which is the shape of a quiet bulk
 | extraction, and the shape of an out-of-memory page.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->admin = userWithRole('admin', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->admin);
});

it('offers no "all" page size on the families list', function () {
    $table = livewire(ListBeneficiaries::class)->instance()->getTable();

    expect($table->getPaginationPageOptions())->not->toContain('all')
        ->and($table->getPaginationPageOptions())->not->toContain(-1)
        ->and(max($table->getPaginationPageOptions()))->toBeLessThanOrEqual(100);
})->group('AUTH-04');

it('offers no "all" page size on the donations list either', function () {
    // This one stated no sizes of its own, so it was taking the framework's
    // default — which is exactly the case the global cap is for.
    $table = livewire(ListDonations::class)->instance()->getTable();

    expect($table->getPaginationPageOptions())->toBe([25, 50, 100])
        ->and($table->getDefaultPaginationPageOption())->toBe(25);
})->group('AUTH-04');

it('puts the cap in one place, so a new resource inherits it', function () {
    // Twenty copies of the same call is twenty chances to forget it on the
    // twenty-first table.
    expect(file_get_contents(base_path('app/Providers/AppServiceProvider.php')))
        ->toContain('Table::configureUsing');
})->group('AUTH-04');

it('lets no table choose a size above the cap for itself', function () {
    $files = array_merge(
        glob(base_path('app/Filament/Resources/*.php')),
        glob(base_path('app/Filament/Resources/*/RelationManagers/*.php')),
        glob(base_path('app/Filament/Association/Resources/*.php')),
        glob(base_path('app/Filament/Provider/Resources/*.php')) ?: [],
    );

    foreach ($files as $file) {
        preg_match_all('/paginated\(\[([^\]]*)\]\)/', file_get_contents($file), $matches);

        foreach ($matches[1] as $options) {
            foreach (array_map('trim', explode(',', $options)) as $size) {
                expect(is_numeric($size))->toBeTrue(basename($file).' offers '.$size)
                    ->and((int) $size)->toBeLessThanOrEqual(100);
            }
        }
    }
})->group('AUTH-04');
