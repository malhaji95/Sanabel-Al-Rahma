<?php

use App\Models\AppNotification;
use App\Models\Donor;
use App\Models\Sponsorship;
use App\Models\SponsorshipInstallment;
use App\Services\CoverageService;
use App\Services\RankingService;
use App\Services\SponsorshipService;

beforeEach(function () {
    seedCore();
});

it('cannot create a sponsorship without an end date', function () {
    $region = regionWithRates();
    $family = publishedCase($region);

    expect(fn () => app(SponsorshipService::class)->create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $family->id,
        'amount' => 20_000,
        'start_date' => now()->toDateString(),
        'end_date' => null,
    ]))->toThrow(RuntimeException::class);

    expect(Sponsorship::count())->toBe(0);
});

it('generates one installment for every month in the range', function () {
    $region = regionWithRates();
    $family = publishedCase($region);

    $sponsorship = app(SponsorshipService::class)->create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $family->id,
        'amount' => 20_000,
        'start_date' => '2026-01-15',
        'end_date' => '2026-06-10',
    ]);

    expect($sponsorship->installments)->toHaveCount(6)
        ->and($sponsorship->installments->pluck('period')->all())
        ->toBe(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'])
        ->and($sponsorship->installments->every(fn ($i) => $i->amount === 20_000))->toBeTrue();
});

it('does not let an unpaid installment change coverage or hide the case', function () {
    $region = regionWithRates();
    $family = publishedCase($region);

    app(SponsorshipService::class)->create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $family->id,
        'amount' => 20_000,
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->startOfMonth()->addMonths(3)->toDateString(),
    ]);

    expect(app(CoverageService::class)->confirmedSupport($family->fresh()))->toBe(0)
        ->and(app(RankingService::class)->rank($family->fresh())['eligible'])->toBeTrue()
        ->and(app(RankingService::class)->fundingList('monthly')->pluck('beneficiary.id'))
        ->toContain($family->id);
});

it('lapses a sponsorship after two consecutive unpaid installments and returns the family to the list', function () {
    $region = regionWithRates();
    $family = publishedCase($region);

    $sponsorship = app(SponsorshipService::class)->create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $family->id,
        'amount' => 20_000,
        'start_date' => now()->subMonths(5)->startOfMonth()->toDateString(),
        'end_date' => now()->addMonths(3)->startOfMonth()->toDateString(),
    ]);

    // The oldest installment was paid; the two after it were not.
    $due = $sponsorship->installments()->orderBy('due_date')->get();
    $due->first()->forceFill(['status' => 'paid'])->save();

    $result = app(SponsorshipService::class)->markOverdueAndLapse();

    expect($result['overdue'])->toBeGreaterThanOrEqual(2)
        ->and($sponsorship->fresh()->status)->toBe('lapsed')
        ->and(app(SponsorshipService::class)->consecutiveUnpaid($sponsorship->fresh()))->toBeGreaterThanOrEqual(2)
        ->and(app(RankingService::class)->fundingList('monthly')->pluck('beneficiary.id'))
        ->toContain($family->id);
});

it('reminds the donor before the due date, once', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $user = userWithRole('donor');
    $donor = Donor::factory()->create(['user_id' => $user->id]);

    app(SponsorshipService::class)->create([
        'donor_id' => $donor->id,
        'beneficiary_id' => $family->id,
        'amount' => 20_000,
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->startOfMonth()->toDateString(),
    ]);

    // Standing on the last day of the month, the single installment is due now.
    $this->travelTo(now()->endOfMonth()->subDay());

    expect(app(SponsorshipService::class)->sendReminders())->toBe(1)
        ->and(app(SponsorshipService::class)->sendReminders())->toBe(0)
        ->and(AppNotification::where('template_key', 'sponsorship_due')->exists())->toBeTrue()
        ->and(SponsorshipInstallment::whereNotNull('reminded_at')->count())->toBe(1);
});
