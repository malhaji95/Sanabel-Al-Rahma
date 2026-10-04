<?php

use App\Models\Beneficiary;
use App\Models\Delivery;
use App\Models\Setting;
use App\Services\CoverageService;
use App\Services\DonationService;

/*
 | Decisions of 4 Oct 2026:
 |   - the badge a donor reads is the priority band, taken from how much of the
 |     need is still uncovered, with the thresholds held as settings;
 |   - a delegate verifies and studies, and touches no money;
 |   - a technical administrator does not open a family file.
 */

beforeEach(function () {
    seedCore();
});

/** Confirms `$amount` onto a published family, which is what moves coverage. */
function fund(Beneficiary $case, int $amount): void
{
    $donation = app(DonationService::class)->record([
        'donor_id' => \App\Models\Donor::factory()->create()->id,
        'amount' => $amount,
        'transaction_ref' => 'REF-'.uniqid(),
        'basket_id' => null,
        'designation' => 'general',
    ]);

    $admin = userWithRole('admin');

    app(DonationService::class)->verify($donation, $admin->id);
    app(\App\Services\GeneralMoneyService::class)->allocate(
        $donation, $case, $amount, app(CoverageService::class)->currentMonth(), $admin
    );
}

it('reads the priority band from what is still uncovered', function () {
    $region = regionWithRates(adult: 5000, child: 2000);
    $coverage = app(CoverageService::class);

    $case = publishedCase($region);
    $need = $coverage->needAmount($case);

    // Nothing in yet.
    expect($coverage->priorityBand($case))->toBe('critical');

    // Half way: past the 41% line, below the 61% one.
    fund($case, (int) round($need * 0.5));
    expect($coverage->priorityBand($case->refresh()))->toBe('middle');

    // Most of the way.
    fund($case, (int) round($need * 0.3));
    expect($coverage->priorityBand($case->refresh()))->toBe('low');

    // Covered.
    fund($case, $coverage->remainingNeed($case->refresh()));
    expect($coverage->priorityBand($case->refresh()))->toBe('complete');
});

it('follows the thresholds the association sets, not numbers in the code', function () {
    $region = regionWithRates(adult: 5000, child: 2000);
    $coverage = app(CoverageService::class);

    $case = publishedCase($region);
    fund($case, (int) round($coverage->needAmount($case) * 0.5));

    expect($coverage->priorityBand($case->refresh()))->toBe('middle');

    // The association decides half-covered is still the highest priority.
    Setting::updateOrCreate(['key' => 'priority_critical_below'], ['value_json' => 71]);
    Setting::updateOrCreate(['key' => 'priority_middle_below'], ['value_json' => 91]);

    expect($coverage->priorityBand($case->refresh()))->toBe('critical');
});

it('shows the donor the band and keeps urgency as its own marker', function () {
    $region = regionWithRates(adult: 5000, child: 2000);

    // Nearly covered, and running out of time: two different facts.
    $case = publishedCase($region, attributes: ['urgency_deadline_at' => now()->addDays(2)]);
    fund($case, (int) round(app(CoverageService::class)->needAmount($case) * 0.8));

    $card = (new \App\Http\Resources\MaskedCaseResource($case->refresh()))->resolve();

    expect($card['priority_band'])->toBe('low')
        ->and($card['priority_label'])->toBe('أولوية متدنية')
        // One badge could not have said both.
        ->and($card['is_urgent'])->toBeTrue();
});

it('stops a delegate confirming that money reached a family', function () {
    $region = regionWithRates();
    $case = publishedCase($region);

    $delegate = userWithRole('delegate', ['region_id' => $region->id]);
    $officer = userWithRole('case_officer', ['region_id' => $region->id]);

    $delivery = Delivery::create([
        'beneficiary_id' => $case->id,
        'type' => 'cash',
        'note_ar' => 'تسليم تجريبي',
    ]);

    expect($delegate->can('create', Delivery::class))->toBeFalse()
        ->and($delegate->can('update', $delivery))->toBeFalse()
        // Someone outside the money chain still closes the case.
        ->and($officer->can('create', Delivery::class))->toBeTrue();
});

it('stops the system administrator opening a family file', function () {
    $admin = userWithRole('admin');
    $officer = userWithRole('case_officer');

    expect($admin->can('create', Beneficiary::class))->toBeFalse()
        ->and($officer->can('create', Beneficiary::class))->toBeTrue();
});
