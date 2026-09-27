<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\AppNotification;
use App\Models\Donor;
use App\Models\Setting;
use App\Services\BasketService;
use App\Services\DonationService;
use App\Services\TransferRouting;

/*
 | The association's decision of 27 Sep 2026: a donor transfers to the family
 | directly, to the association, or is offered both — and which it is, is set
 | on the association with an exception per family.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->routing = app(TransferRouting::class);

    Setting::put('platform_wallet', ['number' => '0900000000', 'holder' => 'سنابل الرحمة']);
});

function associationWith(string $mode, ?string $wallet = '0911111111')
{
    return userWithRole('association', [
        'name' => 'جمعية الاختبار',
        'transfer_mode' => $mode,
        'wallet_encrypted' => $wallet,
    ]);
}

it('follows the association when the family says nothing', function () {
    $association = associationWith('direct');
    $case = publishedCase($this->region);
    $case->update(['association_id' => $association->id, 'wallet_encrypted' => '0922222222']);

    expect($this->routing->modeFor($case->refresh()))->toBe('direct');
});

it('lets one family be excepted from its association', function () {
    $association = associationWith('association');
    $case = publishedCase($this->region);
    $case->update([
        'association_id' => $association->id,
        'transfer_mode' => 'direct',
        'wallet_encrypted' => '0922222222',
    ]);

    $routes = $this->routing->routesFor($case->refresh());

    expect($this->routing->modeFor($case))->toBe('direct')
        ->and($routes)->toHaveCount(1)
        ->and($routes[0]['wallet'])->toBe('0922222222');
});

it('falls back to the platform default for a family with no association', function () {
    Setting::put('default_transfer_mode', 'association');
    $case = publishedCase($this->region);

    $routes = $this->routing->routesFor($case);

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['route'])->toBe('platform')
        ->and($routes[0]['wallet'])->toBe('0900000000');
});

it('offers both wallets when the association says both', function () {
    $association = associationWith('both');
    $case = publishedCase($this->region);
    $case->update(['association_id' => $association->id, 'wallet_encrypted' => '0922222222']);

    $routes = collect($this->routing->routesFor($case->refresh()));

    expect($routes)->toHaveCount(2)
        ->and($routes->pluck('route')->all())->toBe(['direct', 'association'])
        ->and($routes->pluck('wallet')->all())->toBe(['0922222222', '0911111111']);
});

it('drops a route whose wallet was never entered', function () {
    // The association routes directly but the family has no wallet: showing
    // "transfer directly" with no number to transfer to is worse than nothing.
    $association = associationWith('both', wallet: null);
    $case = publishedCase($this->region);
    $case->update(['association_id' => $association->id, 'wallet_encrypted' => null]);

    $routes = $this->routing->routesFor($case->refresh());

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['route'])->toBe('platform');
});

it('labels a family wallet by file number, never by name', function () {
    $association = associationWith('direct');
    $case = publishedCase($this->region);
    $case->update(['association_id' => $association->id, 'wallet_encrypted' => '0922222222']);

    $routes = $this->routing->routesFor($case->refresh());

    expect($routes[0]['holder'])->toBe($case->file_number)
        ->and($routes[0]['holder'])->not->toContain($case->family_name);
});

it('shows one wallet once for two families of the same association', function () {
    $association = associationWith('association');
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

it('tells the association when a donor records a direct transfer to its family', function () {
    $association = associationWith('direct');
    $donor = Donor::factory()->create();
    $case = publishedCase($this->region);
    $case->update(['association_id' => $association->id, 'wallet_encrypted' => '0922222222']);

    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($donor);
    $baskets->addItem($basket, $case->refresh(), 1_000);

    app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'basket_id' => $basket->id,
        'amount' => 1_000,
        'transaction_ref' => 'TRX-DIRECT-1',
    ]);

    $sent = AppNotification::where('recipient_id', $association->id)
        ->where('template_key', 'direct_transfer_recorded')
        ->get();

    expect($sent)->not->toBeEmpty()
        ->and($sent->first()->payload_json['file_number'])->toBe($case->file_number)
        // Rule 10 still stands: no name, no wallet in a notification body.
        ->and($sent->first()->payload_json)->not->toHaveKey('wallet');
});

it('says nothing to the association when the transfer goes to the association', function () {
    $association = associationWith('association');
    $donor = Donor::factory()->create();
    $case = publishedCase($this->region);
    $case->update(['association_id' => $association->id]);

    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($donor);
    $baskets->addItem($basket, $case->refresh(), 1_000);

    app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'basket_id' => $basket->id,
        'amount' => 1_000,
        'transaction_ref' => 'TRX-ASSOC-1',
    ]);

    expect(AppNotification::where('template_key', 'direct_transfer_recorded')->count())->toBe(0);
});

it('keeps the family wallet out of donor output unless the route is direct', function () {
    $association = associationWith('association');
    $case = publishedCase($this->region);
    $case->update(['association_id' => $association->id, 'wallet_encrypted' => '0922222222']);

    $masked = (new MaskedCaseResource($case->refresh()))->resolve();

    expect(json_encode($masked))->not->toContain('0922222222');
});
