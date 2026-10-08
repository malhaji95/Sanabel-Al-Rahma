<?php

use App\Filament\Widgets\CoverageByRegion;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Services\CoverageService;
use App\Services\DonationService;

/*
 | The dashboard panel that reads "coverage by region" used to list files one
 | by one. The association asked for the sum per region, and these assert the
 | sum.
 */
beforeEach(function () {
    seedCore();
});

function giveTo($family, int $amount, string $ref, $verifier)
{
    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => $amount,
        'transaction_ref' => $ref,
    ]);

    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $family->id,
        'amount' => $amount,
        'currency' => 'SYP',
    ]);

    return app(DonationService::class)->verify($donation, $verifier->id);
}

it('adds the families of a region up into one row', function () {
    $region = regionWithRates();
    $admin = userWithRole('admin');

    $first = publishedCase($region);
    $second = publishedCase($region);

    giveTo($first, 10_000, 'TRX-REG-A', $admin);

    $rows = (new CoverageByRegion)->getRows();

    expect($rows)->toHaveCount(1);

    $row = $rows[0];
    $coverage = app(CoverageService::class);
    $need = $coverage->needAmount($first->fresh()) + $coverage->needAmount($second->fresh());

    expect($row['families'])->toBe(2)
        ->and($row['need'])->toBe($need)
        ->and($row['paid'])->toBe(10_000)
        ->and($row['percent'])->toBe((int) round(10_000 / $need * 100));
});

it('keeps each region on its own row, neediest first', function () {
    $admin = userWithRole('admin');

    $covered = regionWithRates();
    $bare = regionWithRates();

    $a = publishedCase($covered);
    publishedCase($bare);

    // Enough to cover the first region's only family outright.
    giveTo($a, app(CoverageService::class)->needAmount($a) , 'TRX-REG-B', $admin);

    $rows = (new CoverageByRegion)->getRows();

    expect($rows)->toHaveCount(2)
        // The region with nothing reaching it is read first.
        ->and($rows[0]['percent'])->toBe(0)
        ->and($rows[1]['percent'])->toBe(100);
});

it('does not read money beyond the need as more than full coverage', function () {
    $region = regionWithRates();
    $admin = userWithRole('admin');

    $family = publishedCase($region);
    $need = app(CoverageService::class)->needAmount($family);

    giveTo($family, $need * 2, 'TRX-REG-C', $admin);

    $row = (new CoverageByRegion)->getRows()[0];

    // The surplus carries to next month; it is not this month's coverage.
    expect($row['paid'])->toBe($need)
        ->and($row['percent'])->toBe(100);
});

it('shows nothing at all before a file is published', function () {
    expect((new CoverageByRegion)->getRows())->toBeEmpty();
});
