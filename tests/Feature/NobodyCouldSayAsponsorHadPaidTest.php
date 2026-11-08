<?php

use App\Filament\Resources\SponsorshipResource\Pages\EditSponsorship;
use App\Filament\Resources\SponsorshipResource\RelationManagers\InstallmentsRelationManager;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Sponsorship;
use App\Services\DonationService;
use App\Services\SponsorshipService;
use Filament\Facades\Filament;

/*
 | T-266 — found while auditing the whole system, and reported by nobody.
 |
 | `markPaid()` is the only place an installment's status becomes «مدفوع»,
 | and nothing in the application called it. So a month was generated, fell
 | due, fell overdue, was counted overdue in the reports, and after two of
 | them the lapse rule stopped the sponsorship — and there was no screen
 | anywhere that could say a sponsor had paid. Left alone, every sponsorship
 | lapsed in its second month.
 |
 | What is held here is the screen that says it, and the four guards that
 | decide what may be said.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->admin = userWithRole('executive_director');
    $this->sponsor = Donor::factory()->create();
    $this->service = app(SponsorshipService::class);
});

function sponsorshipOf(Donor $sponsor, $region): Sponsorship
{
    return Sponsorship::create([
        'donor_id' => $sponsor->id,
        'beneficiary_id' => publishedCase($region)->id,
        'amount' => 100_000,
        'currency' => config('sanabel.currency'),
        'start_date' => now()->startOfMonth()->subMonths(2),
        'end_date' => now()->startOfMonth()->addMonths(3),
        'status' => 'active',
    ]);
}

function verifiedGift(Donor $donor, int $amount, $admin): Donation
{
    static $ref = 0;

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => $amount,
        'transaction_ref' => 'SPON-'.(++$ref),
    ]);

    app(DonationService::class)->verify($donation, $admin->id);

    return $donation->refresh();
}

it('marks a month paid against the transfer that paid it', function () {
    $sponsorship = sponsorshipOf($this->sponsor, $this->region);
    $this->service->generateInstallments($sponsorship);

    $installment = $sponsorship->installments()->orderBy('due_date')->firstOrFail();
    $donation = verifiedGift($this->sponsor, 100_000, $this->admin);

    $this->service->markPaid($installment, $donation->id);

    expect($installment->refresh()->status)->toBe('paid')
        ->and($installment->donation_id)->toBe($donation->id);
});

it('refuses money the finance desk has not verified', function () {
    $sponsorship = sponsorshipOf($this->sponsor, $this->region);
    $this->service->generateInstallments($sponsorship);

    $installment = $sponsorship->installments()->orderBy('due_date')->firstOrFail();

    // Recorded, not verified: rule 5's own line — this is not money yet.
    $pending = app(DonationService::class)->record([
        'donor_id' => $this->sponsor->id,
        'amount' => 100_000,
        'transaction_ref' => 'SPON-PENDING',
    ]);

    expect(fn () => $this->service->markPaid($installment, $pending->id))
        ->toThrow(RuntimeException::class);

    expect($installment->refresh()->status)->not->toBe('paid');
});

it('refuses a transfer that is somebody else s', function () {
    $sponsorship = sponsorshipOf($this->sponsor, $this->region);
    $this->service->generateInstallments($sponsorship);

    $installment = $sponsorship->installments()->orderBy('due_date')->firstOrFail();
    $stranger = verifiedGift(Donor::factory()->create(), 100_000, $this->admin);

    expect(fn () => $this->service->markPaid($installment, $stranger->id))
        ->toThrow(RuntimeException::class);
});

it('lets one transfer settle several months, because a sponsor may pay a year', function () {
    $sponsorship = sponsorshipOf($this->sponsor, $this->region);
    $this->service->generateInstallments($sponsorship);

    $months = $sponsorship->installments()->orderBy('due_date')->take(3)->get();
    $donation = verifiedGift($this->sponsor, 300_000, $this->admin);

    foreach ($months as $month) {
        $this->service->markPaid($month, $donation->id);
    }

    expect($months->map(fn ($m) => $m->refresh()->status)->all())->toBe(['paid', 'paid', 'paid']);
});

it('leaves a month already paid exactly as it is', function () {
    $sponsorship = sponsorshipOf($this->sponsor, $this->region);
    $this->service->generateInstallments($sponsorship);

    $installment = $sponsorship->installments()->orderBy('due_date')->firstOrFail();
    $first = verifiedGift($this->sponsor, 100_000, $this->admin);
    $second = verifiedGift($this->sponsor, 100_000, $this->admin);

    $this->service->markPaid($installment, $first->id);

    expect(fn () => $this->service->markPaid($installment->refresh(), $second->id))
        ->toThrow(RuntimeException::class);

    expect($installment->refresh()->donation_id)->toBe($first->id);
});

it('stops the sponsorship lapsing, which is what nobody could do before', function () {
    $sponsorship = sponsorshipOf($this->sponsor, $this->region);
    $this->service->generateInstallments($sponsorship);

    // Two months owed and nothing said about them is what the lapse rule
    // counts. This was the only state a sponsorship could ever reach.
    expect($this->service->consecutiveUnpaid($sponsorship))->toBeGreaterThanOrEqual(2);

    foreach ($sponsorship->installments()->whereDate('due_date', '<=', now())->get() as $month) {
        $this->service->markPaid($month, verifiedGift($this->sponsor, 100_000, $this->admin)->id);
    }

    expect($this->service->consecutiveUnpaid($sponsorship->refresh()))->toBe(0);
});

it('offers the desk the months and the button that was never there', function () {
    $sponsorship = sponsorshipOf($this->sponsor, $this->region);
    $this->service->generateInstallments($sponsorship);

    $donation = verifiedGift($this->sponsor, 100_000, $this->admin);
    $installment = $sponsorship->installments()->orderBy('due_date')->firstOrFail();

    /*
     | Finance, not the director: the button is for whoever may verify a
     | transfer, because saying a month was paid is saying money arrived.
     */
    passTwoFactor();
    $this->actingAs(userWithRole('finance'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire\Livewire::test(InstallmentsRelationManager::class, [
        'ownerRecord' => $sponsorship,
        'pageClass' => EditSponsorship::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($sponsorship->installments)
        ->callTableAction('markPaid', $installment, ['donation_id' => $donation->id]);

    expect($installment->refresh()->status)->toBe('paid');
});

it('offers the sponsor s own transfers and nobody else s', function () {
    $sponsorship = sponsorshipOf($this->sponsor, $this->region);
    $this->service->generateInstallments($sponsorship);

    $theirs = verifiedGift($this->sponsor, 100_000, $this->admin);
    $stranger = verifiedGift(Donor::factory()->create(), 100_000, $this->admin);

    $pending = app(DonationService::class)->record([
        'donor_id' => $this->sponsor->id,
        'amount' => 100_000,
        'transaction_ref' => 'SPON-NOT-YET',
    ]);

    $options = (fn () => $this->eligibleDonations())->call(
        tap(new InstallmentsRelationManager, fn ($m) => $m->ownerRecord = $sponsorship)
    );

    expect(array_keys($options))->toContain($theirs->id)
        ->and(array_keys($options))->not->toContain($stranger->id)
        // Money the desk has not verified is not offered either.
        ->and(array_keys($options))->not->toContain($pending->id);
});
