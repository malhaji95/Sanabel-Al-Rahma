<?php

use App\Exceptions\ReservationUnavailable;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Services\BasketService;
use App\Services\DonationService;

/**
 * Campaigns were a shell: the table, the model, the admin screen and the donor
 * page all existed, but no money could reach them. collected_amount was never
 * incremented anywhere in the application, so every campaign read as zero
 * raised and every progress bar sat at 0%.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

function campaignFor(int $goal = 100_000): Campaign
{
    return Campaign::factory()->create([
        'beneficiary_id' => publishedCase(test()->region)->id,
        'goal_amount' => $goal,
        'status' => 'active',
        'is_published' => true,
        'surplus_policy_text_ar' => 'يوجه الفائض لحملة مماثلة.',
    ]);
}

function pledge(Campaign $campaign, int $amount): Donation
{
    $donor = Donor::factory()->create();
    $basket = app(BasketService::class)->openFor($donor);

    app(BasketService::class)->addCampaign($basket, $campaign, $amount);
    app(BasketService::class)->reserve($basket);

    return app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'basket_id' => $basket->id,
        'amount' => $amount,
        'transaction_ref' => 'CAMP-'.$campaign->id.'-'.$amount.'-'.uniqid(),
    ]);
}

it('counts a held pledge against the goal before any money arrives', function () {
    $campaign = campaignFor(100_000);
    $donor = Donor::factory()->create();
    $basket = app(BasketService::class)->openFor($donor);

    app(BasketService::class)->addCampaign($basket, $campaign, 40_000);
    app(BasketService::class)->reserve($basket);

    $campaign->refresh();

    // Held, not raised: a reservation is not money (docs/03-rules.md §3).
    expect($campaign->reservedAmount())->toBe(40_000)
        ->and($campaign->collectedAmount())->toBe(0)
        ->and($campaign->progressPercent())->toBe(0);
});

it('credits the campaign only when the transfer is verified', function () {
    $campaign = campaignFor(100_000);
    $donation = pledge($campaign, 40_000);

    expect($campaign->fresh()->collectedAmount())->toBe(0, 'a pending transfer is not money');

    app(DonationService::class)->verify($donation, userWithRole('admin')->id);

    $campaign->refresh();

    expect($campaign->collectedAmount())->toBe(40_000)
        ->and($campaign->progressPercent())->toBe(40)
        // The hold is consumed by the payment, not left standing alongside it.
        ->and($campaign->reservedAmount())->toBe(0)
        ->and($campaign->status)->toBe('active');
});

it('closes funding at the goal and never completes the campaign itself', function () {
    $campaign = campaignFor(100_000);

    app(DonationService::class)->verify(pledge($campaign, 100_000), userWithRole('admin')->id);

    $campaign->refresh();

    // Funding is closed; the money still has to be moved and proved.
    expect($campaign->status)->toBe('funded')
        ->and($campaign->acceptsPledges())->toBeFalse()
        ->and($campaign->progressPercent())->toBe(100);
});

it('refuses a pledge that would take the campaign past its goal', function () {
    $campaign = campaignFor(100_000);

    app(DonationService::class)->verify(pledge($campaign, 70_000), userWithRole('admin')->id);

    $basket = app(BasketService::class)->openFor(Donor::factory()->create());
    app(BasketService::class)->addCampaign($basket, $campaign->fresh(), 40_000);

    expect(fn () => app(BasketService::class)->reserve($basket))
        ->toThrow(ReservationUnavailable::class);

    // 30,000 is exactly what is left, and is accepted.
    $ok = app(BasketService::class)->openFor(Donor::factory()->create());
    app(BasketService::class)->addCampaign($ok, $campaign->fresh(), 30_000);

    expect(app(BasketService::class)->reserve($ok)->status)->toBe('reserved');
});

it('refuses a pledge to a campaign that is no longer funding', function () {
    $campaign = campaignFor(50_000);
    app(DonationService::class)->verify(pledge($campaign, 50_000), userWithRole('admin')->id);

    $basket = app(BasketService::class)->openFor(Donor::factory()->create());
    app(BasketService::class)->addCampaign($basket, $campaign->fresh(), 1_000);

    expect(fn () => app(BasketService::class)->reserve($basket))
        ->toThrow(ReservationUnavailable::class);
});

it('reopens a funded campaign when a donation behind it is reversed', function () {
    $campaign = campaignFor(100_000);
    $admin = userWithRole('admin');

    $donation = pledge($campaign, 100_000);
    app(DonationService::class)->verify($donation, $admin->id);

    expect($campaign->fresh()->status)->toBe('funded');

    app(DonationService::class)->reverse($donation->fresh(), $admin->id, 'حوالة مرتجعة.');

    $campaign->refresh();

    // The reversal cancels the allocation out, so the campaign is short again.
    expect($campaign->collectedAmount())->toBe(0)
        ->and($campaign->status)->toBe('active')
        ->and($campaign->acceptsPledges())->toBeTrue();
});

it('lands the money on the family the campaign was opened for', function () {
    $campaign = campaignFor(60_000);
    $donation = pledge($campaign, 60_000);

    app(DonationService::class)->verify($donation, userWithRole('admin')->id);

    $allocation = $donation->fresh()->allocations()->first();

    expect($allocation->campaign_id)->toBe($campaign->id)
        ->and($allocation->beneficiary_id)->toBe($campaign->beneficiary_id)
        ->and($allocation->amount)->toBe(60_000);
});
