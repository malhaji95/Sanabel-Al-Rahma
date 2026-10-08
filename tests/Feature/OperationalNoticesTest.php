<?php

use App\Models\AppNotification;
use App\Models\Basket;
use App\Models\Donor;
use App\Models\SponsorshipInstallment;
use App\Services\BasketService;
use App\Services\DonationService;
use App\Services\SponsorshipService;

/*
 | The three notices the association looked for and did not find: a hold about
 | to run out, money waiting to be verified, and a sponsorship instalment that
 | has gone late. The templates were written months ago; nothing fired them.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

function noticesOf(string $template)
{
    return AppNotification::where('template_key', $template)->get();
}

it('tells a donor once that their hold is about to run out', function () {
    $donor = Donor::factory()->create(['user_id' => userWithRole('donor')->id]);
    $case = publishedCase($this->region);

    $baskets = app(BasketService::class);
    $basket = $baskets->currentFor($donor);
    $baskets->addItem($basket, $case, 5_000);
    $baskets->reserve($basket);

    // Still hours away: nothing is said yet.
    expect($baskets->warnExpiring(1))->toBe(0);

    $basket->refresh()->forceFill(['reserved_until' => now()->addMinutes(30)])->save();

    expect($baskets->warnExpiring(1))->toBe(1)
        ->and(noticesOf('basket_expiring'))->not->toBeEmpty()
        // And not again on the next pass five minutes later.
        ->and($baskets->warnExpiring(1))->toBe(0);
});

it('tells whoever verifies money that a donation is waiting', function () {
    $finance = userWithRole('finance');
    $treasurer = userWithRole('treasurer');
    userWithRole('delegate');

    app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => 10_000,
        'transaction_ref' => 'TRX-NOTICE-1',
    ]);

    $told = noticesOf('donation_pending')->pluck('recipient_id')->unique();

    expect($told)->toContain($finance->id)
        ->and($told)->toContain($treasurer->id)
        // A delegate has no business with an unverified transfer.
        ->and($told)->not->toContain(userWithRole('delegate')->id);

    // The reference travels; nothing about the donor does.
    expect(noticesOf('donation_pending')->first()->payload_json)
        ->toBe(['ref' => 'TRX-NOTICE-1']);
});

it('tells a donor an instalment is late, before anything lapses', function () {
    $donorUser = userWithRole('donor');
    $donor = Donor::factory()->create(['user_id' => $donorUser->id]);
    $case = publishedCase($this->region);

    $sponsorship = app(SponsorshipService::class)->create([
        'donor_id' => $donor->id,
        'beneficiary_id' => $case->id,
        'amount' => 10_000,
        'currency' => 'SYP',
        'start_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
        'end_date' => now()->addMonths(4)->endOfMonth()->toDateString(),
        'status' => 'active',
    ]);

    app(SponsorshipService::class)->generateInstallments($sponsorship);

    SponsorshipInstallment::where('sponsorship_id', $sponsorship->id)
        ->orderBy('due_date')->first()
        ->forceFill(['status' => 'due', 'due_date' => now()->subMonth()])->save();

    app(SponsorshipService::class)->markOverdueAndLapse();

    expect(noticesOf('sponsorship_overdue'))->not->toBeEmpty()
        ->and(noticesOf('sponsorship_overdue')->first()->recipient_id)->toBe($donorUser->id);
});
