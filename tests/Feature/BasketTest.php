<?php

use App\Exceptions\ReservationUnavailable;
use App\Models\Basket;
use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Models\Setting;
use App\Services\BasketService;
use App\Services\CoverageService;
use App\Services\DonationService;
use App\Services\RankingService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedCore();
});

it('reserves a basket and holds it for the configured window', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $basket = app(BasketService::class)->openFor(Donor::factory()->create());

    app(BasketService::class)->addItem($basket, $family, 5_000);
    $reserved = app(BasketService::class)->reserve($basket);

    expect($reserved->status)->toBe('reserved')
        ->and($reserved->reserved_until->diffInHours(now()->addHours(24)))->toBeLessThan(1);
});

it('lets only one of two donors reserve the last remaining amount', function () {
    $region = regionWithRates();
    $family = publishedCase($region); // need 16,000
    $need = app(CoverageService::class)->needAmount($family);

    $first = app(BasketService::class)->openFor(Donor::factory()->create());
    $second = app(BasketService::class)->openFor(Donor::factory()->create());

    app(BasketService::class)->addItem($first, $family, $need);
    app(BasketService::class)->addItem($second, $family, $need);

    app(BasketService::class)->reserve($first);

    // The second reservation now sees the first one's hold and has nothing left to claim.
    expect(fn () => app(BasketService::class)->reserve($second))
        ->toThrow(ReservationUnavailable::class);

    expect(Basket::where('status', 'reserved')->count())->toBe(1);
});

it('takes a row lock inside a transaction when reserving', function () {
    // Rule 6 — the check and the write happen together, with the target rows
    // locked. This is what makes two donors racing for the last amount safe;
    // without the lock the check-then-write would let both through.
    $region = regionWithRates();
    $family = publishedCase($region);

    $basket = app(BasketService::class)->openFor(Donor::factory()->create());
    app(BasketService::class)->addItem($basket, $family, 1_000);

    DB::enableQueryLog();
    DB::flushQueryLog();
    app(BasketService::class)->reserve($basket);
    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->contains(fn ($q) => str_contains(strtolower($q), 'for update')))->toBeTrue()
        ->and($queries->first(fn ($q) => str_contains(strtolower($q), 'for update')))
        ->toContain('"beneficiaries"');
});

it('lets a second reservation through once the first one is released', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $need = app(CoverageService::class)->needAmount($family);

    $first = app(BasketService::class)->openFor(Donor::factory()->create());
    $second = app(BasketService::class)->openFor(Donor::factory()->create());
    app(BasketService::class)->addItem($first, $family, $need);
    app(BasketService::class)->addItem($second, $family, $need);

    app(BasketService::class)->reserve($first);
    app(BasketService::class)->release($first);

    expect(app(BasketService::class)->reserve($second)->status)->toBe('reserved');
});

it('releases an expired reservation and returns the family to the list', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $need = app(CoverageService::class)->needAmount($family);

    $basket = app(BasketService::class)->openFor(Donor::factory()->create());
    app(BasketService::class)->addItem($basket, $family, $need);
    app(BasketService::class)->reserve($basket);

    expect(app(CoverageService::class)->remainingNeed($family))->toBe(0);

    $this->travel(25)->hours();

    expect(app(BasketService::class)->releaseExpired())->toBe(1)
        ->and($basket->fresh()->status)->toBe('expired')
        ->and(app(CoverageService::class)->remainingNeed($family))->toBe($need)
        ->and(app(RankingService::class)->fundingList('monthly')->pluck('beneficiary.id'))
        ->toContain($family->id);
});

it('splits one donation across the chosen families', function () {
    $region = regionWithRates();
    $a = publishedCase($region);
    $b = publishedCase($region);
    $c = publishedCase($region);

    $donor = Donor::factory()->create();
    $basket = app(BasketService::class)->openFor($donor);
    app(BasketService::class)->addItem($basket, $a, 5_000);
    app(BasketService::class)->addItem($basket, $b, 3_000);
    app(BasketService::class)->addItem($basket, $c, 2_000);
    app(BasketService::class)->reserve($basket);

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => 10_000,
        'transaction_ref' => 'TRX-BASKET-SPLIT',
        'basket_id' => $basket->id,
    ]);
    app(DonationService::class)->verify($donation, userWithRole('admin')->id);

    $coverage = app(CoverageService::class);

    expect($coverage->confirmedSupport($a->fresh()))->toBe(5_000)
        ->and($coverage->confirmedSupport($b->fresh()))->toBe(3_000)
        ->and($coverage->confirmedSupport($c->fresh()))->toBe(2_000)
        ->and($basket->fresh()->status)->toBe('paid');
});

it('allocates proportionally without failing when the verified amount is lower than the basket total', function () {
    $region = regionWithRates();
    $a = publishedCase($region);
    $b = publishedCase($region);

    $donor = Donor::factory()->create();
    $basket = app(BasketService::class)->openFor($donor);
    app(BasketService::class)->addItem($basket, $a, 6_000);
    app(BasketService::class)->addItem($basket, $b, 4_000);
    app(BasketService::class)->reserve($basket);

    // The donor actually transferred half of what they picked.
    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => 5_000,
        'transaction_ref' => 'TRX-BASKET-SHORT',
        'basket_id' => $basket->id,
    ]);
    app(DonationService::class)->verify($donation, userWithRole('admin')->id);

    $coverage = app(CoverageService::class);
    $allocated = DonationAllocation::where('donation_id', $donation->id)->sum('amount');

    expect((int) $allocated)->toBe(5_000)
        ->and($coverage->confirmedSupport($a->fresh()))->toBe(3_000)
        ->and($coverage->confirmedSupport($b->fresh()))->toBe(2_000);
});

it('refuses to reserve a family beyond its remaining need', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $need = app(CoverageService::class)->needAmount($family);

    $basket = app(BasketService::class)->openFor(Donor::factory()->create());
    app(BasketService::class)->addItem($basket, $family, $need + 1);

    expect(fn () => app(BasketService::class)->reserve($basket))
        ->toThrow(ReservationUnavailable::class);

    expect($basket->fresh()->status)->toBe('open');
});

it('counts verified coverage against the remaining need for a later reservation', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $need = app(CoverageService::class)->needAmount($family);

    $donation = Donation::factory()->create(['amount' => $need, 'status' => 'pending']);
    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $family->id,
        'amount' => $need,
        'currency' => 'SYP',
    ]);
    app(DonationService::class)->verify($donation, userWithRole('admin')->id);

    $basket = app(BasketService::class)->openFor(Donor::factory()->create());
    app(BasketService::class)->addItem($basket, $family, 1_000);

    expect(fn () => app(BasketService::class)->reserve($basket))
        ->toThrow(ReservationUnavailable::class);
});

it('honours the hold duration configured in settings', function () {
    Setting::put('basket_hold_hours', 6);

    $region = regionWithRates();
    $family = publishedCase($region);
    $basket = app(BasketService::class)->openFor(Donor::factory()->create());
    app(BasketService::class)->addItem($basket, $family, 1_000);

    $reserved = app(BasketService::class)->reserve($basket);

    expect($reserved->reserved_until->diffInHours(now()->addHours(6)))->toBeLessThan(1);
});
