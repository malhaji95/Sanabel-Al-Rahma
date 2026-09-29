<?php

use App\Models\Donor;
use App\Services\BasketService;
use App\Services\CoverageService;
use App\Services\DonationService;
use App\Services\GeneralMoneyService;

/*
 | General money reaches Sanabel Al-Rahma with no family named. This is how it
 | is placed afterwards, and what it refuses.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->service = app(GeneralMoneyService::class);
    $this->admin = userWithRole('admin');
    $this->donor = Donor::factory()->create();
});

function verifiedGeneral(int $amount, string $ref)
{
    $donation = app(DonationService::class)->record([
        'donor_id' => test()->donor->id,
        'amount' => $amount,
        'transaction_ref' => $ref,
        'designation' => 'general',
    ]);

    return app(DonationService::class)->verify($donation, test()->admin->id);
}

it('counts verified general money as a balance waiting to be placed', function () {
    verifiedGeneral(50_000, 'G-1');
    verifiedGeneral(30_000, 'G-2');

    expect($this->service->balance())->toBe(80_000)
        ->and($this->service->undistributed())->toHaveCount(2);
});

it('moves a family coverage only once the money is placed on it', function () {
    $case = publishedCase($this->region);
    $donation = verifiedGeneral(50_000, 'G-3');
    $coverage = app(CoverageService::class);

    expect($coverage->confirmedForMonth($case))->toBe(0);

    $this->service->allocate($donation, $case, 9_000, $coverage->currentMonth(), $this->admin);

    expect($coverage->confirmedForMonth($case->refresh()))->toBe(9_000);
});

it('draws the balance down as money is placed', function () {
    $case = publishedCase($this->region);
    $donation = verifiedGeneral(50_000, 'G-4');

    $this->service->allocate($donation, $case, 20_000, app(CoverageService::class)->currentMonth(), $this->admin);

    expect($this->service->remaining($donation->refresh()))->toBe(30_000)
        ->and($this->service->balance())->toBe(30_000);
});

it('refuses to place more than the donation still has', function () {
    $case = publishedCase($this->region);
    $donation = verifiedGeneral(10_000, 'G-5');

    $this->service->allocate($donation, $case, 8_000, app(CoverageService::class)->currentMonth(), $this->admin);

    expect(fn () => $this->service->allocate(
        $donation->refresh(), $case, 3_000, app(CoverageService::class)->currentMonth(), $this->admin
    ))->toThrow(RuntimeException::class);
});

it('drops a fully placed donation off the waiting list', function () {
    $case = publishedCase($this->region);
    $donation = verifiedGeneral(10_000, 'G-6');

    $this->service->allocate($donation, $case, 10_000, app(CoverageService::class)->currentMonth(), $this->admin);

    expect($this->service->undistributed())->toBeEmpty()
        ->and($this->service->balance())->toBe(0);
});

it('never offers earmarked money on this screen', function () {
    $case = publishedCase($this->region);
    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($this->donor);
    $baskets->addItem($basket, $case, 4_000);

    $donation = app(DonationService::class)->record([
        'donor_id' => $this->donor->id,
        'basket_id' => $basket->id,
        'amount' => 4_000,
        'transaction_ref' => 'E-1',
    ]);
    app(DonationService::class)->verify($donation, $this->admin->id);

    expect($this->service->undistributed())->toBeEmpty()
        ->and($this->service->balance())->toBe(0);
});

it('refuses an unverified donation and a zero amount', function () {
    $case = publishedCase($this->region);
    $pending = app(DonationService::class)->record([
        'donor_id' => $this->donor->id,
        'amount' => 10_000,
        'transaction_ref' => 'G-7',
        'designation' => 'general',
    ]);

    $month = app(CoverageService::class)->currentMonth();

    expect(fn () => $this->service->allocate($pending, $case, 1_000, $month, $this->admin))
        ->toThrow(RuntimeException::class);

    $verified = verifiedGeneral(10_000, 'G-8');

    expect(fn () => $this->service->allocate($verified, $case, 0, $month, $this->admin))
        ->toThrow(InvalidArgumentException::class);
});

it('carries the month the money answers for', function () {
    $case = publishedCase($this->region);
    $donation = verifiedGeneral(20_000, 'G-9');
    $next = app(CoverageService::class)->currentMonth()->copy()->addMonth();

    $this->service->allocate($donation, $case, 5_000, $next, $this->admin);

    // Placed on next month, so this month's coverage does not move.
    expect(app(CoverageService::class)->confirmedForMonth($case, $next))->toBe(5_000)
        ->and(app(CoverageService::class)->confirmedForMonth($case))->toBe(0);
});
