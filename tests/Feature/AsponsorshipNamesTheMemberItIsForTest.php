<?php

/*
 | T-238 — «كل كفالة ترتبط داخليًا باليتيم المعني، وتنعكس على إجمالي تغطية
 | الأسرة دون ازدواج», 3 November.
 |
 | The association's own example: a family of four orphans, three of them
 | sponsored. Before today those three rows were identical once written —
 | same donor table, same family, nothing saying which child. This holds
 | the link, and holds the one rule the link needs: the member named has
 | to be a member of the family sponsored.
 */

use App\Models\Donor;
use App\Models\HouseholdMember;
use App\Models\Sponsorship;
use App\Models\SponsorshipInstallment;
use App\Services\SponsorshipService;

beforeEach(function () {
    seedCore();
});

it('names the member a sponsorship is for', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $child = $family->members()->where('relation', 'child')->first();

    $sponsorship = app(SponsorshipService::class)->create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $family->id,
        'household_member_id' => $child->id,
        'amount' => 20_000,
        'start_date' => '2026-01-01',
        'end_date' => '2026-03-01',
    ]);

    expect($sponsorship->household_member_id)->toBe($child->id)
        ->and($sponsorship->member->is($child))->toBeTrue();
});

it('still accepts a sponsorship of the household as a whole', function () {
    $region = regionWithRates();
    $family = publishedCase($region);

    $sponsorship = app(SponsorshipService::class)->create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $family->id,
        'amount' => 20_000,
        'start_date' => '2026-01-01',
        'end_date' => '2026-03-01',
    ]);

    expect($sponsorship->household_member_id)->toBeNull()
        ->and($sponsorship->member)->toBeNull();
});

it('refuses a member who belongs to another family', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $other = publishedCase($region);
    $stranger = $other->members()->where('relation', 'child')->first();

    expect(fn () => app(SponsorshipService::class)->create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $family->id,
        'household_member_id' => $stranger->id,
        'amount' => 20_000,
        'start_date' => '2026-01-01',
        'end_date' => '2026-03-01',
    ]))->toThrow(RuntimeException::class, __('sanabel.sponsorships.member_not_in_household'));

    expect(Sponsorship::count())->toBe(0)
        ->and(SponsorshipInstallment::count())->toBe(0);
});

it('refuses the wrong member on an update too, not only on creation', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $other = publishedCase($region);

    $sponsorship = app(SponsorshipService::class)->create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $family->id,
        'amount' => 20_000,
        'start_date' => '2026-01-01',
        'end_date' => '2026-03-01',
    ]);

    $stranger = $other->members()->where('relation', 'child')->first();

    expect(fn () => $sponsorship->update(['household_member_id' => $stranger->id]))
        ->toThrow(RuntimeException::class);

    expect($sponsorship->fresh()->household_member_id)->toBeNull();
});

it('carries three separate sponsorships for three members of one family', function () {
    $region = regionWithRates();
    $family = publishedCase($region);

    $orphans = HouseholdMember::factory()->count(3)->child()->create([
        'beneficiary_id' => $family->id,
        'is_orphan' => true,
    ]);

    foreach ($orphans as $orphan) {
        app(SponsorshipService::class)->create([
            'donor_id' => Donor::factory()->create()->id,
            'beneficiary_id' => $family->id,
            'household_member_id' => $orphan->id,
            'amount' => 20_000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-02-01',
        ]);
    }

    // Three rows, one family, three different children — and the family
    // is still one family: it is counted once, with three names under it.
    expect(Sponsorship::where('beneficiary_id', $family->id)->count())->toBe(3)
        ->and(Sponsorship::whereNotNull('household_member_id')->pluck('household_member_id')->sort()->values()->all())
        ->toBe($orphans->pluck('id')->sort()->values()->all());
});

it('does not invalidate a sponsorship when the member is removed from the file', function () {
    $region = regionWithRates();
    $family = publishedCase($region);
    $child = $family->members()->where('relation', 'child')->first();

    $sponsorship = app(SponsorshipService::class)->create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $family->id,
        'household_member_id' => $child->id,
        'amount' => 20_000,
        'start_date' => '2026-01-01',
        'end_date' => '2026-03-01',
    ]);

    $child->delete();

    // A member leaving the file is not a reason the money that was pledged
    // for them can no longer be saved. The row stays writable.
    $sponsorship->update(['status' => 'completed']);

    expect($sponsorship->fresh()->status)->toBe('completed')
        ->and($sponsorship->fresh()->household_member_id)->toBe($child->id);
});
