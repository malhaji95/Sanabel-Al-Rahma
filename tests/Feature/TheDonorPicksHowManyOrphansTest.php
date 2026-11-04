<?php

/*
 | T-246 — «وعند دخول المتبرع يظهر انه يوجد اكثر من يتيم ويستطيع تحديد كم
 | يتيم يحتاج أن يكفل», 3 November, and the screen half of «نحتاج تجربة
 | كاملة» of 4 November.
 */

use App\Http\Resources\MaskedCaseResource;
use App\Livewire\SponsorOrphans;
use App\Models\Donor;
use App\Models\HouseholdMember;
use App\Models\Setting;
use App\Models\Sponsorship;
use App\Models\SponsorshipRate;
use App\Models\User;
use App\Services\SponsorshipService;
use Livewire\Livewire;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->family = publishedCase($this->region, attributes: ['case_classifications' => ['orphan']]);

    SponsorshipRate::create([
        'kind' => 'orphan', 'amount' => 75_000, 'currency' => 'SYP', 'months' => 12, 'is_active' => true,
    ]);

    HouseholdMember::factory()->count(4)->child()->create([
        'beneficiary_id' => $this->family->id,
        'is_orphan' => true,
    ]);
});

function sponsoringDonor(): User
{
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id]);

    return $user;
}

it('tells a donor how many orphans are free, and nothing about which', function () {
    $payload = (new MaskedCaseResource($this->family->refresh()))->resolve();

    expect($payload['orphans_available'])->toBe(4)
        // Rule 2 — a count, and not one thing about a child.
        ->and(array_diff(array_keys($payload), MaskedCaseResource::ALLOWED_KEYS))->toBe([]);
});

it('closes the count with the same switch that closes the door', function () {
    Setting::put('show_sponsorship_kind', false);

    $payload = (new MaskedCaseResource($this->family->refresh()))->resolve();

    // If the file does not say it is an orphan file, a count of its orphans
    // would say it louder.
    expect($payload['orphans_available'])->toBe(0)
        ->and($payload['sponsorship_kinds'])->toBe([]);
});

it('offers the donor a number up to what is free', function () {
    Livewire::actingAs(sponsoringDonor())
        ->test(SponsorOrphans::class, ['fileNumber' => $this->family->file_number, 'available' => 4])
        ->assertSuccessful()
        ->assertSee(__('sanabel.sponsorships.orphans_available', ['count' => 4]))
        ->assertSee(__('sanabel.sponsorships.sponsor_now'));
});

it('takes the number the donor chose and says how many were written', function () {
    Livewire::actingAs(sponsoringDonor())
        ->test(SponsorOrphans::class, ['fileNumber' => $this->family->file_number, 'available' => 4])
        ->set('count', 3)
        ->call('sponsor')
        ->assertSet('error', null)
        ->assertSet('notice', __('sanabel.sponsorships.taken', ['count' => 3]))
        // The count on the card falls by itself: one child left.
        ->assertSet('available', 1);

    expect(Sponsorship::count())->toBe(3)
        ->and(Sponsorship::pluck('household_member_id')->unique())->toHaveCount(3)
        ->and(Sponsorship::first()->amount)->toBe(75_000);
});

it('asks a visitor with no account to sign in, and writes nothing', function () {
    Livewire::test(SponsorOrphans::class, ['fileNumber' => $this->family->file_number, 'available' => 4])
        ->set('count', 2)
        ->call('sponsor')
        ->assertSet('error', __('sanabel.sponsorships.needs_account'));

    expect(Sponsorship::count())->toBe(0);
});

it('answers a donor whose page went stale with what is actually left', function () {
    // Somebody else took three while this donor's page sat open.
    app(SponsorshipService::class)->sponsorOrphans(
        $this->family, Donor::factory()->create(), 3
    );

    Livewire::actingAs(sponsoringDonor())
        ->test(SponsorOrphans::class, ['fileNumber' => $this->family->file_number, 'available' => 4])
        ->set('count', 4)
        ->call('sponsor')
        ->assertSet('error', __('sanabel.sponsorships.not_that_many_orphans', ['available' => 1]))
        // And the screen corrects itself rather than leaving the stale four.
        ->assertSet('available', 1);

    expect(Sponsorship::count())->toBe(3);
});

it('says plainly when a family’s orphans are all sponsored', function () {
    app(SponsorshipService::class)->sponsorOrphans(
        $this->family, Donor::factory()->create(), 4
    );

    Livewire::actingAs(sponsoringDonor())
        ->test(SponsorOrphans::class, ['fileNumber' => $this->family->file_number, 'available' => 0])
        ->assertSee(__('sanabel.sponsorships.all_taken'))
        ->assertDontSee(__('sanabel.sponsorships.sponsor_now'));
});

it('draws the family once on the sponsorships page, with its number inside', function () {
    $this->get(route('sponsorships.browse'))
        ->assertOk()
        ->assertSee(__('sanabel.sponsorships.orphans_available', ['count' => 4]))
        // One card for the household, never one per child: three cards
        // sharing a file number would let any visitor count its orphans.
        ->assertSeeTextInOrder([$this->family->file_number])
        ->assertDontSee('name_ar');
});
