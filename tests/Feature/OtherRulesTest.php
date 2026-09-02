<?php

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Complaint;
use App\Models\Donation;
use App\Models\JobProfile;
use App\Models\Provider;
use App\Models\Referral;
use App\Services\CaseService;
use App\Services\DuplicateService;
use App\Services\ReferralService;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->admin = userWithRole('admin');
});

it('cannot publish a campaign without surplus policy text', function () {
    expect(fn () => Campaign::factory()->create([
        'is_published' => true,
        'surplus_policy_text_ar' => null,
    ]))->toThrow(RuntimeException::class, __('sanabel.campaigns.surplus_policy_required'));

    $campaign = Campaign::factory()->create(['is_published' => false]);

    expect(fn () => $campaign->update(['is_published' => true]))
        ->toThrow(RuntimeException::class);

    $campaign->update([
        'surplus_policy_text_ar' => 'يوجه الفائض لحملة مماثلة.',
        'is_published' => true,
    ]);

    expect($campaign->fresh()->is_published)->toBeTrue();
});

it('stops pledges once collected plus reserved reaches the goal', function () {
    $campaign = Campaign::factory()->create([
        'goal_amount' => 100_000,
        'collected_amount' => 60_000,
        'reserved_amount' => 30_000,
    ]);

    expect($campaign->acceptsPledges())->toBeTrue()
        ->and($campaign->progressPercent())->toBe(60);

    $campaign->update(['reserved_amount' => 40_000]);

    expect($campaign->fresh()->acceptsPledges())->toBeFalse();
});

it('refuses an expired referral card and a card that was already used', function () {
    $case = publishedCase($this->region);
    $provider = Provider::factory()->create(['region_id' => $this->region->id]);

    $expired = Referral::factory()->expired()->create([
        'beneficiary_id' => $case->id, 'provider_id' => $provider->id,
    ]);

    expect(fn () => app(ReferralService::class)->redeem($expired, 1))
        ->toThrow(RuntimeException::class, __('sanabel.referrals.expired'))
        ->and($expired->fresh()->status)->toBe('expired');

    $card = app(ReferralService::class)->issue($case, $provider);
    app(ReferralService::class)->redeem($card, 2);

    expect($card->fresh()->status)->toBe('used')
        ->and(fn () => app(ReferralService::class)->redeem($card->fresh(), 3))
        ->toThrow(RuntimeException::class, __('sanabel.referrals.already_used'));

    $revoked = app(ReferralService::class)->revoke(app(ReferralService::class)->issue($case, $provider));

    expect(fn () => app(ReferralService::class)->redeem($revoked, 4))
        ->toThrow(RuntimeException::class, __('sanabel.referrals.revoked'));
});

it('hides a job profile when a case approval is revoked', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['created_by' => userWithRole('delegate')->id])->save();

    JobProfile::factory()->create([
        'beneficiary_id' => $case->id,
        'region_id' => $case->region_id,
        'status' => 'published',
    ]);

    app(CaseService::class)->reject($case->refresh(), $this->admin, 'تبيّن عدم استحقاق');

    expect($case->fresh()->jobProfile->status)->toBe('hidden');

    $visible = $this->actingAs(userWithRole('donor'), 'sanctum');
    App\Models\Donor::factory()->create(['user_id' => auth()->id()]);

    expect(JobProfile::where('status', 'published')->count())->toBe(0);
});

it('never assigns a complaint to the person it is about', function () {
    $subject = userWithRole('delegate');

    expect(fn () => Complaint::factory()->create([
        'against_user_id' => $subject->id,
        'owner_id' => $subject->id,
    ]))->toThrow(RuntimeException::class, __('sanabel.complaints.owner_conflict'));

    $complaint = Complaint::factory()->create([
        'against_user_id' => $subject->id,
        'owner_id' => $this->admin->id,
        'status' => 'assigned',
    ]);

    expect($complaint->owner_id)->toBe($this->admin->id)
        ->and($complaint->reference_no)->not->toBeEmpty();
});

it('blocks a second file for the same national ID and merges without deleting history', function () {
    $case = publishedCase($this->region);
    $nationalId = $case->national_id_encrypted;

    expect(fn () => app(DuplicateService::class)->guardAgainstDuplicate($nationalId))
        ->toThrow(RuntimeException::class, __('sanabel.cases.duplicate_national_id'));

    // A merge moves history across; nothing is deleted.
    $duplicate = publishedCase($this->region);
    $donation = Donation::factory()->create(['status' => 'verified', 'verified_at' => now()]);
    App\Models\DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $duplicate->id,
        'amount' => 5_000,
        'currency' => 'SYP',
    ]);

    app(DuplicateService::class)->merge($case, $duplicate, $this->admin);

    expect($duplicate->fresh()->status)->toBe('merged')
        ->and($duplicate->fresh()->merged_into_id)->toBe($case->id)
        ->and($duplicate->fresh()->deleted_at)->toBeNull()
        ->and((int) $case->fresh()->allocations()->sum('amount'))->toBe(5_000)
        ->and($duplicate->fresh()->allocations()->count())->toBe(0);
});

it('flags a suspicious duplicate for review instead of merging it automatically', function () {
    $first = familyOf($this->region);
    $first->update(['phone_encrypted' => '0987654321']);

    $second = familyOf($this->region);
    $second->update(['phone_encrypted' => '0987654321']);

    expect(app(DuplicateService::class)->flagIfSuspicious($second->refresh()))->toBeTrue()
        ->and($second->fresh()->duplicate_review_flag)->toBeTrue()
        // Never an auto-merge.
        ->and($second->fresh()->merged_into_id)->toBeNull()
        ->and($second->fresh()->status)->not->toBe('merged');
});

it('logs every write to a case and to money, without personal data', function () {
    $case = publishedCase($this->region);

    $caseWrites = AuditLog::where('entity_type', Beneficiary::class)
        ->where('entity_id', $case->id)
        ->get();

    expect($caseWrites)->not->toBeEmpty();

    $donation = Donation::factory()->create();

    expect(AuditLog::where('entity_type', Donation::class)->where('entity_id', $donation->id)->exists())
        ->toBeTrue();

    // Personal columns are redacted before they reach the log.
    $created = $caseWrites->firstWhere('action', 'created');

    expect($created->after_json['national_id_encrypted'])->toBe('[redacted]')
        ->and($created->after_json['phone_encrypted'])->toBe('[redacted]')
        ->and($created->after_json['national_id_hash'])->toBe('[redacted]');
});

it('keeps the audit log append-only', function () {
    $case = publishedCase($this->region);
    $entry = AuditLog::where('entity_id', $case->id)->first();

    expect(fn () => $entry->update(['action' => 'tampered']))
        ->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $entry->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('flags an overdue reassessment without stopping existing support', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['next_assessment_due_at' => now()->subDay()])->save();

    $donation = Donation::factory()->create(['status' => 'verified', 'verified_at' => now()]);
    App\Models\DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $case->id,
        'amount' => 4_000,
        'currency' => 'SYP',
    ]);

    expect(app(CaseService::class)->flagOverdueReassessments())->toBe(1)
        ->and($case->fresh()->status)->toBe('needs_reassessment')
        // Support already given is untouched.
        ->and(app(App\Services\CoverageService::class)->confirmedSupport($case->fresh()))->toBe(4_000);
});
