<?php

use App\Filament\Pages\OperationalReports;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Services\DisbursementService;
use App\Services\DonationService;

/*
 | The operational figures the association listed, read for one month. Not an
 | analysis: the count they were exporting a list to arrive at.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->admin = userWithRole('admin');
});

function reportFor(?string $month = null): array
{
    $page = new OperationalReports;
    $page->month = $month ?? now()->startOfMonth()->toDateString();

    return $page->getReport();
}

it('counts the month money, split the way the association reads it', function () {
    $family = publishedCase($this->region);

    // Earmarked money arrives the way a donor sends it: through a basket.
    $donor = Donor::factory()->create();
    $baskets = app(App\Services\BasketService::class);
    $basket = $baskets->currentFor($donor);
    $baskets->addItem($basket, $family, 6_000);
    $baskets->reserve($basket);

    $earmarked = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => 6_000,
        'transaction_ref' => 'TRX-REP-1',
        'basket_id' => $basket->id,
    ]);
    app(DonationService::class)->verify($earmarked, $this->admin->id);

    $general = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => 10_000,
        'transaction_ref' => 'TRX-REP-2',
    ]);
    app(DonationService::class)->verify($general, $this->admin->id);

    // Recorded and not yet verified: waiting, not collected.
    app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => 5_000,
        'transaction_ref' => 'TRX-REP-3',
    ]);

    $money = reportFor()['money'];

    expect($money['collected'])->toBe(16_000)
        ->and($money['earmarked'])->toBe(6_000)
        ->and($money['general'])->toBe(10_000)
        ->and($money['allocated'])->toBe(6_000)
        ->and($money['pending'])->toBe(5_000);
});

it('counts what actually left through an approved order', function () {
    $family = publishedCase($this->region);
    $service = app(DisbursementService::class);

    $line = $service->confirm($family, 8_000, userWithRole('case_officer'));
    $order = $service->gather([$line], userWithRole('treasurer'));
    $service->submit($order);
    $service->approve($order, userWithRole('executive_director'));

    // Approved is not out of the door.
    expect(reportFor()['money']['disbursed'])->toBe(0);

    $service->execute($line->refresh(), userWithRole('treasurer'), 'TRF-REP-1');

    expect(reportFor()['money']['disbursed'])->toBe(8_000);
});

it('counts the families by the band the donor sees', function () {
    publishedCase($this->region);
    publishedCase($this->region);

    $families = reportFor()['families'];

    expect($families['published'])->toBe(2)
        // Nothing has reached either of them, so both read as most urgent.
        ->and($families['critical'])->toBe(2)
        ->and($families['complete'])->toBe(0);
});

it('reads a month that is over without counting this one', function () {
    $family = publishedCase($this->region);

    $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(4));

    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => 12_000,
        'transaction_ref' => 'TRX-REP-LAST',
    ]);
    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $family->id,
        'amount' => 12_000,
        'currency' => 'SYP',
        'coverage_month' => now()->startOfMonth(),
    ]);
    app(DonationService::class)->verify($donation, $this->admin->id);

    $lastMonth = now()->startOfMonth()->toDateString();
    $this->travelBack();

    expect(reportFor($lastMonth)['money']['collected'])->toBe(12_000)
        ->and(reportFor()['money']['collected'])->toBe(0);
});

it('opens the reports to the association, and not to a donor or a provider', function () {
    foreach (['admin', 'executive_director', 'finance', 'treasurer', 'oversight_director', 'area_supervisor'] as $role) {
        $this->actingAs(userWithRole($role));
        expect(OperationalReports::canAccess())->toBeTrue("{$role} should read the reports");
    }

    // These hold view_reports too, but only over their own work.
    foreach (['donor', 'service_provider', 'delegate', 'beneficiary'] as $role) {
        $this->actingAs(userWithRole($role));
        expect(OperationalReports::canAccess())->toBeFalse("{$role} should not read the association's figures");
    }
});
