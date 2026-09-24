<?php

use App\Models\Beneficiary;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Fund;
use App\Services\CaseService;
use App\Services\DonationService;

/**
 * The walk the association asked to watch with separate accounts:
 * create → verify → area supervisor → approve → pay, and the checks that no
 * one step can be reached by the account that performed the one before it.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();

    $this->delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $this->supervisor = userWithRole('area_supervisor', ['region_id' => $this->region->id]);
    $this->officer = userWithRole('case_officer');
    $this->admin = userWithRole('admin');
    $this->finance = userWithRole('finance');
    $this->donor = userWithRole('donor');
});

it('walks a case from creation to approval across four accounts', function () {
    // 1. The delegate creates the file.
    expect($this->delegate->can('create', Beneficiary::class))->toBeTrue();

    $case = familyOf($this->region, attributes: [
        'created_by' => $this->delegate->id,
        'status' => 'pending_visit',
    ]);

    // 2. The delegate records the visit; the supervisor recommends within scope.
    expect($this->delegate->can('recordVisit', $case))->toBeTrue()
        ->and($this->supervisor->can('recordVisit', $case))->toBeTrue();

    // 3. Neither the delegate nor the supervisor may approve: the delegate
    //    because he created it, and neither holds approve_case at all.
    expect($this->delegate->can('approve', $case))->toBeFalse()
        ->and($this->supervisor->can('approve', $case))->toBeFalse();

    // 4. The admin, who did not create it, approves.
    expect($this->admin->can('approve', $case))->toBeTrue();

    $approved = app(CaseService::class)->approve($case, $this->admin);

    expect($approved->status)->toBe('approved')
        ->and($approved->approved_by)->toBe($this->admin->id);
});

it('keeps the creator out of the approval, whichever role created it', function () {
    foreach (['case_officer', 'admin'] as $roleKey) {
        $creator = userWithRole($roleKey);
        $case = familyOf($this->region, attributes: [
            'created_by' => $creator->id,
            'status' => 'pending_approval',
        ]);

        expect($creator->can('approve', $case))->toBeFalse("{$roleKey} approved a case it created")
            ->and($creator->can('reject', $case))->toBeFalse("{$roleKey} rejected a case it created");

        expect(fn () => app(CaseService::class)->approve($case, $creator))
            ->toThrow(RuntimeException::class);
    }
});

it('holds each account inside its own region', function () {
    $otherRegion = regionWithRates();
    $mine = familyOf($this->region);
    $theirs = familyOf($otherRegion);

    expect($this->supervisor->can('view', $mine))->toBeTrue()
        ->and($this->supervisor->can('view', $theirs))->toBeFalse()
        ->and($this->delegate->can('view', $theirs))->toBeFalse();
});

it('separates the money from the case entirely', function () {
    $case = publishedCase($this->region);

    // Finance verifies transfers and never opens a family file.
    expect($this->finance->can_('verify_payment'))->toBeTrue()
        ->and($this->finance->can('view', $case))->toBeFalse()
        ->and($this->finance->can('approve', $case))->toBeFalse();

    // The case roles do not touch the money.
    expect($this->delegate->can_('verify_payment'))->toBeFalse()
        ->and($this->supervisor->can_('verify_payment'))->toBeFalse()
        ->and($this->officer->can_('verify_payment'))->toBeFalse();
});

it('will not let the donor who paid confirm the transfer', function () {
    $donorRecord = Donor::factory()->create(['user_id' => $this->donor->id]);

    $donation = Donation::create([
        'donor_id' => $donorRecord->id,
        'amount' => 10_000,
        'currency' => config('sanabel.currency'),
        'transaction_ref' => 'WALK-0001',
        'status' => 'pending',
        'fund_id' => Fund::byKey(Fund::OPERATIONAL)->id,
    ]);

    expect($this->donor->can('verify', $donation))->toBeFalse()
        ->and($this->finance->can('verify', $donation))->toBeTrue();

    app(DonationService::class)->verify($donation, $this->finance->id);

    expect($donation->fresh()->status)->toBe('verified')
        ->and($donation->fresh()->verified_by)->toBe($this->finance->id);
});

it('denies the council every write while letting it read', function () {
    $council = userWithRole('council');
    $case = publishedCase($this->region);

    expect($council->can('view', $case))->toBeTrue()
        ->and($council->can('approve', $case))->toBeFalse()
        ->and($council->can('update', $case))->toBeFalse()
        ->and($council->can_('verify_payment'))->toBeFalse()
        ->and($council->can_('edit_config'))->toBeFalse();
});
