<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Setting;
use App\Payments\PaymentGateway;
use App\Services\BasketService;
use App\Services\CoverageService;
use App\Services\DonationService;
use App\Services\TransferRouting;

/*
 | Settled on 29 Sep 2026. One wallet — Sanabel Al-Rahma's own — receives every
 | transfer, and Sanabel Al-Rahma moves the money on. What a donor chooses is
 | not where it goes but what it is for: named files, or the association's
 | discretion.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->routing = app(TransferRouting::class);

    Setting::put('platform_wallet', ['number' => '0900000000', 'holder' => 'جمعية سنابل الرحمة']);
});

it('sends every donor to the one wallet', function () {
    expect($this->routing->wallet())
        ->toBe(['wallet' => '0900000000', 'holder' => 'جمعية سنابل الرحمة']);
});

it('says nothing rather than showing a blank when no wallet was entered', function () {
    Setting::put('platform_wallet', ['number' => null, 'holder' => null]);

    expect($this->routing->wallet())->toBeNull();
});

it('keeps a family wallet out of donor output entirely', function () {
    $case = publishedCase($this->region);
    $case->update(['wallet_encrypted' => '0922222222']);

    $masked = json_encode((new MaskedCaseResource($case->refresh()))->resolve());

    expect($masked)->not->toContain('0922222222');
});

it('marks a basket donation as earmarked for the files it names', function () {
    $donor = Donor::factory()->create();
    $case = publishedCase($this->region);
    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($donor);
    $baskets->addItem($basket, $case, 1_000);

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'basket_id' => $basket->id,
        'amount' => 1_000,
        'transaction_ref' => 'TRX-EARMARKED',
    ]);

    expect($donation->designation)->toBe('earmarked')
        ->and($donation->route)->toBe('platform');
});

it('records a general donation that names no family at all', function () {
    $donor = Donor::factory()->create();

    $donation = app(PaymentGateway::class)->record([
        'donor_id' => $donor->id,
        'amount' => 50_000,
        'transaction_ref' => 'TRX-GENERAL',
        'designation' => 'general',
    ]);

    expect($donation->designation)->toBe('general')
        ->and($donation->basket_id)->toBeNull()
        ->and($donation->allocations)->toBeEmpty();
});

it('refuses general money that arrives tied to a basket', function () {
    $donor = Donor::factory()->create();
    $case = publishedCase($this->region);
    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($donor);
    $baskets->addItem($basket, $case, 1_000);

    app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'basket_id' => $basket->id,
        'amount' => 1_000,
        'transaction_ref' => 'TRX-CONTRADICTION',
        'designation' => 'general',
    ]);
})->throws(InvalidArgumentException::class);

it('refuses earmarked money that names no files', function () {
    $donor = Donor::factory()->create();

    app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => 1_000,
        'transaction_ref' => 'TRX-NO-FILES',
        'designation' => 'earmarked',
    ]);
})->throws(InvalidArgumentException::class);

it('never stores a donation as paid straight to a family', function () {
    $donor = Donor::factory()->create();

    app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => 1_000,
        'transaction_ref' => 'TRX-FORCED-DIRECT',
        'designation' => 'general',
        // Even asked for outright, the direct route is not written.
        'route' => 'direct',
    ]);

    expect(Donation::where('transaction_ref', 'TRX-FORCED-DIRECT')->value('route'))->toBe('platform');
});

it('leaves a general donation out of every family coverage figure', function () {
    $donor = Donor::factory()->create();
    $case = publishedCase($this->region);

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => 50_000,
        'transaction_ref' => 'TRX-GENERAL-2',
        'designation' => 'general',
    ]);

    app(DonationService::class)->verify($donation, userWithRole('admin')->id);

    expect(app(CoverageService::class)->confirmedForMonth($case))->toBe(0);
});
