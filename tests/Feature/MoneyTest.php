<?php

use App\Exceptions\DuplicateTransactionRef;
use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Models\Fund;
use App\Payments\ManualDriver;
use App\Payments\PaymentGateway;
use App\Services\CoverageService;
use App\Services\DonationService;

beforeEach(function () {
    seedCore();
});

it('resolves the payment gateway to the manual driver', function () {
    expect(app(PaymentGateway::class))->toBeInstanceOf(ManualDriver::class)
        ->and(app(PaymentGateway::class)->key())->toBe('manual');
});

it('rejects a duplicate transaction_ref', function () {
    $donor = Donor::factory()->create();
    $payload = [
        'donor_id' => $donor->id,
        'amount' => 10_000,
        'transaction_ref' => 'TRX-DUPLICATE',
    ];

    app(DonationService::class)->record($payload);

    expect(fn () => app(DonationService::class)->record($payload))
        ->toThrow(DuplicateTransactionRef::class);

    expect(Donation::where('transaction_ref', 'TRX-DUPLICATE')->count())->toBe(1);
});

it('changes coverage only after verification, never on upload', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $coverage = app(CoverageService::class);

    $before = $coverage->confirmedSupport($family);

    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => 9_000,
        'transaction_ref' => 'TRX-UPLOAD',
    ]);
    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $family->id,
        'amount' => 9_000,
        'currency' => 'SYP',
    ]);

    expect($coverage->confirmedSupport($family->fresh()))->toBe($before);

    app(DonationService::class)->verify($donation->fresh(), userWithRole('admin')->id);

    expect($coverage->confirmedSupport($family->fresh()))->toBe($before + 9_000);
});

it('returns a reason on rejection and changes nothing else', function () {
    $region = regionWithRates();
    $family = publishedCase($region);

    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => 9_000,
        'transaction_ref' => 'TRX-REJECT',
    ]);
    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $family->id,
        'amount' => 9_000,
        'currency' => 'SYP',
    ]);

    $rejected = app(DonationService::class)->reject($donation, userWithRole('admin')->id, 'الإيصال غير مقروء');

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->reject_reason)->toBe('الإيصال غير مقروء')
        ->and(app(CoverageService::class)->confirmedSupport($family->fresh()))->toBe(0)
        ->and($rejected->donor->fresh()->donations_count)->toBe(0);
});

it('never updates a verified donation and creates a reversal row instead', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $admin = userWithRole('admin');

    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => 12_000,
        'transaction_ref' => 'TRX-VERIFIED',
    ]);
    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $family->id,
        'amount' => 12_000,
        'currency' => 'SYP',
    ]);
    $donation = app(DonationService::class)->verify($donation, $admin->id);

    // A direct edit is refused at the model boundary.
    expect(fn () => $donation->update(['amount' => 1]))
        ->toThrow(RuntimeException::class, 'A verified donation cannot be updated');

    $reversal = app(DonationService::class)->reverse($donation, $admin->id, 'حوالة مكررة');

    expect($reversal->reversal_of_id)->toBe($donation->id)
        ->and($reversal->amount)->toBe(12_000)
        ->and($donation->fresh()->status)->toBe('reversed')
        ->and($donation->fresh()->amount)->toBe(12_000)
        // The reversal cancels the coverage out.
        ->and(app(CoverageService::class)->confirmedSupport($family->fresh()))->toBe(0);
});

it('never allocates membership money to a family', function () {
    $region = regionWithRates();
    $family = publishedCase($region);

    $donation = Donation::factory()->membershipFund()->create();

    expect(fn () => DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $family->id,
        'amount' => 5_000,
        'currency' => 'SYP',
    ]))->toThrow(RuntimeException::class, 'can never be allocated to a family');

    expect(Fund::byKey(Fund::MEMBERSHIP)->can_fund_families)->toBeFalse();
});

it('lets a family with no wallet receive help paid to a provider', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $family->update(['wallet_encrypted' => null]);

    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => 8_000,
        'transaction_ref' => 'TRX-PROVIDER',
    ]);
    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $family->id,
        'amount' => 8_000,
        'currency' => 'SYP',
    ]);
    app(DonationService::class)->verify($donation, userWithRole('admin')->id);

    $delivery = App\Models\Delivery::create([
        'beneficiary_id' => $family->id,
        'donation_id' => $donation->id,
        'type' => 'provider_invoice',
        'proof_media_id' => 1,
        'confirmed_by' => userWithRole('admin')->id,
        'confirmed_at' => now(),
    ]);

    expect($family->fresh()->wallet_encrypted)->toBeNull()
        ->and(app(CoverageService::class)->confirmedSupport($family->fresh()))->toBe(8_000)
        ->and($delivery->isProven())->toBeTrue();
});

it('promotes a donor badge by verified donation count', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $donor = Donor::factory()->create();
    $admin = userWithRole('admin');

    for ($i = 1; $i <= 3; $i++) {
        $donation = app(DonationService::class)->record([
            'donor_id' => $donor->id,
            'amount' => 1_000,
            'transaction_ref' => "TRX-BADGE-$i",
        ]);
        app(DonationService::class)->verify($donation, $admin->id);
    }

    expect($donor->fresh()->badge)->toBe('silver')
        ->and($donor->fresh()->donations_count)->toBe(3);
});
