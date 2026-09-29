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
 | The association settled it on 29 Sep 2026: a donor never transfers to a
 | family. Money reaches the association — earmarked for named files, or
 | general money the association may spend at its discretion.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->routing = app(TransferRouting::class);

    Setting::put('platform_wallet', ['number' => '0900000000', 'holder' => 'سنابل الرحمة']);
});

function associationWith(?string $wallet = '0911111111')
{
    return userWithRole('association', [
        'name' => 'جمعية الاختبار',
        'wallet_encrypted' => $wallet,
    ]);
}

it('sends a donor to the wallet of the association the family belongs to', function () {
    $association = associationWith();
    $case = publishedCase($this->region);
    $case->update(['association_id' => $association->id, 'wallet_encrypted' => '0922222222']);

    $routes = $this->routing->routesFor($case->refresh());

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['route'])->toBe('association')
        ->and($routes[0]['wallet'])->toBe('0911111111')
        ->and($routes[0]['holder'])->toBe('جمعية الاختبار');
});

it('sends a donor to the platform wallet when the family has no association', function () {
    $case = publishedCase($this->region);
    $case->update(['wallet_encrypted' => '0922222222']);

    $routes = $this->routing->routesFor($case->refresh());

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['route'])->toBe('platform')
        ->and($routes[0]['wallet'])->toBe('0900000000');
});

it('falls back to the platform when the association entered no wallet', function () {
    $association = associationWith(wallet: null);
    $case = publishedCase($this->region);
    $case->update(['association_id' => $association->id]);

    expect($this->routing->routesFor($case->refresh())[0]['route'])->toBe('platform');
});

it('never puts a family wallet in front of a donor', function () {
    $association = associationWith();
    $case = publishedCase($this->region);
    $case->update(['association_id' => $association->id, 'wallet_encrypted' => '0922222222']);

    $masked = json_encode((new MaskedCaseResource($case->refresh()))->resolve());

    expect($masked)->not->toContain('0922222222');
});

it('shows one wallet once for two families of the same association', function () {
    $association = associationWith();
    $donor = Donor::factory()->create();
    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($donor);

    foreach (range(1, 2) as $i) {
        $case = publishedCase($this->region);
        $case->update(['association_id' => $association->id]);
        $baskets->addItem($basket, $case->refresh(), 1_000);
    }

    $routes = $this->routing->routesForBasket($basket->refresh());

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['wallet'])->toBe('0911111111')
        ->and($routes[0]['files'])->toHaveCount(2);
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
