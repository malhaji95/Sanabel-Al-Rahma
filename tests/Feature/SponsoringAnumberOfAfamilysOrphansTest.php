<?php

/*
 | T-245 — «نحتاج تجربة كاملة توضح اختيار عدد الأيتام، وقيمة ومدة كل كفالة،
 | وكيفية منع ازدواج الكفالة، وكيف تنعكس ماليًا على الأسرة دون تكرار»,
 | 4 November. Their four questions, in their order.
 */

use App\Models\Beneficiary;
use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Models\HouseholdMember;
use App\Models\Sponsorship;
use App\Models\SponsorshipInstallment;
use App\Models\SponsorshipRate;
use App\Services\CoverageService;
use App\Services\SponsorshipService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->family = publishedCase($this->region);
    $this->donor = Donor::factory()->create();

    // The association's own price for «كفالة يتيم» (T-210): what it costs a
    // month and for how many months. Never the family's monthly need.
    $this->rate = SponsorshipRate::create([
        'kind' => 'orphan', 'amount' => 75_000, 'currency' => 'SYP', 'months' => 12, 'is_active' => true,
    ]);
});

/** @return Collection<int, HouseholdMember> */
function orphansOf(Beneficiary $family, int $count)
{
    return HouseholdMember::factory()->count($count)->child()->create([
        'beneficiary_id' => $family->id,
        'is_orphan' => true,
    ]);
}

it('counts the orphans a donor may still sponsor', function () {
    orphansOf($this->family, 4);

    // Four in the house, none taken yet.
    expect($this->family->orphansAvailableForSponsorship()->count())->toBe(4);
});

it('takes the number the donor asked for and no more', function () {
    orphansOf($this->family, 4);

    $taken = app(SponsorshipService::class)->sponsorOrphans($this->family, $this->donor, 3);

    expect($taken)->toHaveCount(3)
        // «إذا كانت الأسرة تضم 4 أيتام، و3 منهم متاحين» — now it is three
        // taken and one left, which is the same arithmetic from the other end.
        ->and($this->family->orphansAvailableForSponsorship()->count())->toBe(1)
        ->and(Sponsorship::count())->toBe(3);
});

it('names a different child on each sponsorship', function () {
    $orphans = orphansOf($this->family, 3);

    app(SponsorshipService::class)->sponsorOrphans($this->family, $this->donor, 3);

    $named = Sponsorship::pluck('household_member_id')->sort()->values()->all();

    expect($named)->toBe($orphans->pluck('id')->sort()->values()->all())
        ->and(array_unique($named))->toHaveCount(3);
});

it('prices and dates each sponsorship from the association’s own rate', function () {
    orphansOf($this->family, 2);

    $taken = app(SponsorshipService::class)->sponsorOrphans($this->family, $this->donor, 2);

    foreach ($taken as $sponsorship) {
        expect($sponsorship->amount)->toBe(75_000)
            ->and($sponsorship->currency)->toBe('SYP')
            // Twelve months, so twelve installments of the monthly figure.
            ->and($sponsorship->installments()->count())->toBe(12)
            ->and($sponsorship->installments()->first()->amount)->toBe(75_000)
            ->and($sponsorship->start_date->toDateString())
            ->toBe(now()->startOfMonth()->toDateString())
            ->and($sponsorship->end_date->toDateString())
            ->toBe(now()->startOfMonth()->addMonths(11)->endOfMonth()->toDateString());
    }
});

it('follows the rate when the association changes it', function () {
    orphansOf($this->family, 1);
    $this->rate->update(['amount' => 100_000, 'months' => 6]);

    $taken = app(SponsorshipService::class)->sponsorOrphans($this->family, $this->donor, 1);

    expect($taken->first()->amount)->toBe(100_000)
        ->and($taken->first()->installments()->count())->toBe(6);
});

it('refuses when the association has set no price for an orphan’s sponsorship', function () {
    orphansOf($this->family, 2);
    $this->rate->update(['is_active' => false]);

    expect(fn () => app(SponsorshipService::class)->sponsorOrphans($this->family, $this->donor, 1))
        ->toThrow(RuntimeException::class, __('sanabel.sponsorships.no_rate_for_kind'));

    expect(Sponsorship::count())->toBe(0);
});

it('refuses to take more orphans than the family still has free', function () {
    orphansOf($this->family, 2);

    expect(fn () => app(SponsorshipService::class)->sponsorOrphans($this->family, $this->donor, 3))
        ->toThrow(RuntimeException::class);

    // All or nothing: a donor who asked for three is not quietly given two.
    expect(Sponsorship::count())->toBe(0)
        ->and(SponsorshipInstallment::count())->toBe(0);
});

it('does not offer a child who already has a live sponsorship', function () {
    orphansOf($this->family, 3);

    app(SponsorshipService::class)->sponsorOrphans($this->family, $this->donor, 2);

    $second = Donor::factory()->create();
    $taken = app(SponsorshipService::class)->sponsorOrphans($this->family->refresh(), $second, 1);

    // The third child, and nobody twice.
    expect(Sponsorship::count())->toBe(3)
        ->and(Sponsorship::pluck('household_member_id')->unique())->toHaveCount(3)
        ->and($taken->first()->donor_id)->toBe($second->id)
        ->and($this->family->orphansAvailableForSponsorship()->count())->toBe(0);
});

it('refuses a second live sponsorship on one child at the database itself', function () {
    $orphan = orphansOf($this->family, 1)->first();

    app(SponsorshipService::class)->sponsorOrphans($this->family, $this->donor, 1);

    // Past the service, straight at the table: the rule is the index's.
    expect(fn () => Sponsorship::create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $this->family->id,
        'household_member_id' => $orphan->id,
        'amount' => 75_000,
        'currency' => 'SYP',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonths(3)->toDateString(),
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});

it('frees the child again when a sponsorship lapses', function () {
    $orphan = orphansOf($this->family, 1)->first();

    $sponsorship = app(SponsorshipService::class)
        ->sponsorOrphans($this->family, $this->donor, 1)->first();

    expect($this->family->orphansAvailableForSponsorship()->count())->toBe(0);

    $sponsorship->forceFill(['status' => 'lapsed'])->save();

    // A lapsed sponsorship is not a sponsorship: the child is offered again,
    // and somebody else may take them.
    expect($this->family->orphansAvailableForSponsorship()->count())->toBe(1);

    app(SponsorshipService::class)->sponsorOrphans($this->family->refresh(), Donor::factory()->create(), 1);

    expect(Sponsorship::where('household_member_id', $orphan->id)->count())->toBe(2)
        ->and(Sponsorship::where('household_member_id', $orphan->id)->where('status', 'active')->count())
        ->toBe(1);
});

it('leaves a household-wide sponsorship alone, because it names no child', function () {
    orphansOf($this->family, 1);

    // Two of them, neither naming a member: the index only sees rows that do.
    foreach ([1, 2] as $i) {
        Sponsorship::create([
            'donor_id' => Donor::factory()->create()->id,
            'beneficiary_id' => $this->family->id,
            'amount' => 20_000,
            'currency' => 'SYP',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);
    }

    expect(Sponsorship::whereNull('household_member_id')->count())->toBe(2);
});

it('counts three sponsorships of one family once each, and never twice', function () {
    orphansOf($this->family, 3);

    app(SponsorshipService::class)->sponsorOrphans($this->family, $this->donor, 3);

    $coverage = app(CoverageService::class);

    // An unpaid installment is not coverage. Three live sponsorships move
    // nothing until the money actually arrives (docs/03-rules.md §3).
    expect($coverage->confirmedSupport($this->family))->toBe(0);

    // One month of each, paid and verified, through the one allocation
    // table every other donation reaches the family through.
    foreach (Sponsorship::all() as $sponsorship) {
        $donation = Donation::factory()->create([
            'donor_id' => $sponsorship->donor_id,
            'amount' => $sponsorship->amount,
            'status' => 'verified',
            'verified_at' => now(),
        ]);

        DonationAllocation::create([
            'donation_id' => $donation->id,
            'beneficiary_id' => $this->family->id,
            'amount' => $sponsorship->amount,
            'currency' => 'SYP',
        ]);
    }

    // Three contributions, counted once each. The family's coverage is their
    // sum and not a multiple of it: there is one place coverage is read from,
    // so a sponsorship cannot be counted again as the household's own.
    expect($coverage->confirmedSupport($this->family))->toBe(3 * 75_000);
});
