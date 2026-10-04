<?php

use App\Exceptions\ReservationUnavailable;
use App\Models\Campaign;
use App\Models\Delivery;
use App\Services\BasketService;

/*
 | Decisions of 4 October: a campaign runs between two dates, and a receipt for
 | money names the transfer it proves.
 */

beforeEach(function () {
    seedCore();
});

it('refuses a pledge to a campaign that has not opened yet', function () {
    $campaign = Campaign::factory()->create([
        'starts_on' => now()->addWeek(),
        'ends_on' => now()->addMonth(),
    ]);

    $basket = app(BasketService::class)->openFor(\App\Models\Donor::factory()->create());

    expect(fn () => app(BasketService::class)->addCampaign($basket, $campaign, 10_000))
        ->toThrow(ReservationUnavailable::class);
});

it('refuses a pledge to a campaign whose end has passed', function () {
    $campaign = Campaign::factory()->create([
        'starts_on' => now()->subMonth(),
        'ends_on' => now()->subDay(),
    ]);

    $basket = app(BasketService::class)->openFor(\App\Models\Donor::factory()->create());

    expect(fn () => app(BasketService::class)->addCampaign($basket, $campaign, 10_000))
        ->toThrow(ReservationUnavailable::class);
});

it('keeps a campaign without dates open, as every campaign was before', function () {
    $campaign = Campaign::factory()->create([
        'starts_on' => null,
        'ends_on' => null,
        'beneficiary_id' => publishedCase(regionWithRates())->id,
    ]);

    expect($campaign->isOpenToday())->toBeTrue()
        ->and(app(BasketService::class)->addCampaign(
            app(BasketService::class)->openFor(\App\Models\Donor::factory()->create()), $campaign, 10_000
        ))->not->toBeNull();
});

it('marks a campaign lapsed when its end passes short of the goal', function () {
    $campaign = Campaign::factory()->create([
        'goal_amount' => 1_000_000,
        'ends_on' => now()->subDay(),
        'status' => 'active',
    ]);

    $campaign->lapseIfOverdue(collected: 400_000);

    expect($campaign->refresh()->status)->toBe('lapsed');
});

it('leaves a campaign that met its goal alone, however late', function () {
    $campaign = Campaign::factory()->create([
        'goal_amount' => 1_000_000,
        'ends_on' => now()->subDay(),
        'status' => 'active',
    ]);

    $campaign->lapseIfOverdue(collected: 1_000_000);

    expect($campaign->refresh()->status)->toBe('active');
});

it('refuses a cash receipt that does not name the transfer it proves', function () {
    $case = publishedCase(regionWithRates());

    expect(fn () => Delivery::create([
        'beneficiary_id' => $case->id,
        'type' => 'cash',
        'proof_media_id' => 1,
        'confirmed_at' => now(),
    ]))->toThrow(RuntimeException::class);
});

it('lets help that moved no money stand without one', function () {
    $case = publishedCase(regionWithRates());

    $delivery = Delivery::create([
        'beneficiary_id' => $case->id,
        'type' => 'in_kind',
        'proof_media_id' => 1,
        'confirmed_at' => now(),
    ]);

    expect($delivery->exists)->toBeTrue()
        ->and($delivery->donation_id)->toBeNull();
});
