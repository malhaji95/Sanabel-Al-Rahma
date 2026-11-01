<?php

use App\Livewire\AddToBasket;
use App\Livewire\BrowseCases;
use App\Livewire\PledgeToCampaign;
use App\Models\BasketItem;
use App\Models\Campaign;
use App\Models\Donor;
use App\Models\User;
use App\Services\CoverageService;

/*
 | T-220 — «تقصد حساب عيلة وبدها تتبرع؟ هاد الخيار مرفوض وغير ممكن»,
 | 1 November.
 |
 | T-194 landed a household's account on its own page and took the donor
 | portal out of its menu. That removed the signpost and left the door: an
 | account that typed `/portal` was handed the basket, the favourites and a
 | giving history belonging to a donor it is not.
 |
 | Three layers, because a screen that does not offer a thing is not the
 | same as a path that will not do it: the address redirects, the box is
 | not drawn, and the action refuses.
 |
 | A household asking for a donor screen is sent to its own page rather
 | than refused. It is not trespassing — it is in the wrong room of a house
 | it belongs in.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->case = publishedCase($this->region);

    $this->family = userWithRole('beneficiary');
    $this->case->forceFill(['user_id' => $this->family->id])->save();

    $this->donor = userWithRole('donor');
    Donor::factory()->create(['user_id' => $this->donor->id]);
});

it('sends a household asking for the donor portal to its own page', function () {
    $this->actingAs($this->family);

    foreach (['donor.portal', 'donor.basket', 'donor.favourites'] as $name) {
        $this->get(route($name))->assertRedirect(route('family.portal'));
    }
});

it('leaves a donor’s own three screens exactly where they were', function () {
    $this->actingAs($this->donor);

    foreach (['donor.portal', 'donor.basket', 'donor.favourites'] as $name) {
        $this->get(route($name))->assertOk();
    }
});

it('leaves the household’s own page open to the household', function () {
    $this->actingAs($this->family);

    $this->get(route('family.portal'))->assertOk();
});

it('does not draw the giving box on the list for a household', function () {
    $this->actingAs($this->family);

    Livewire\Livewire::actingAs($this->family)
        ->test(BrowseCases::class)
        ->assertDontSeeLivewire(AddToBasket::class);
});

it('still draws it for a donor, which is who it was built for', function () {
    Livewire\Livewire::actingAs($this->donor)
        ->test(BrowseCases::class)
        ->assertSeeLivewire(AddToBasket::class);
});

it('refuses the basket itself, not only the screen that offers it', function () {
    $remaining = app(CoverageService::class)->remainingNeed($this->case);

    Livewire\Livewire::actingAs($this->family)
        ->test(AddToBasket::class, [
            'fileNumber' => $this->case->file_number,
            'remaining' => $remaining,
        ])
        ->set('amount', 1000)
        ->call('add')
        ->assertSet('added', false)
        ->assertSet('notice', __('sanabel.public.family_may_not_give'));

    expect(BasketItem::count())->toBe(0);
});

it('refuses a campaign pledge too, which is the other place money is typed', function () {
    $campaign = Campaign::factory()->create([
        'is_published' => true,
        'surplus_policy_text_ar' => 'ما زاد يُوجَّه إلى حملة مشابهة.',
    ]);

    Livewire\Livewire::actingAs($this->family)
        ->test(PledgeToCampaign::class, ['campaign' => $campaign])
        ->set('amount', 1000)
        ->call('pledge')
        ->assertSet('notice', __('sanabel.public.family_may_not_give'));
});

it('lets every other signed-in account through the door untouched', function () {
    // A delegate is not a donor either, and is refused for that reason and
    // not for this one: this rule names households and nobody else.
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);

    $this->actingAs($delegate)->get(route('donor.portal'))->assertOk();
});

it('is decided by the role, not by whether a donor record happens to exist', function () {
    // A household that somehow carries a donor row is still a household.
    Donor::factory()->create(['user_id' => $this->family->id]);

    expect(User::find($this->family->id)->isFamily())->toBeTrue();

    $this->actingAs($this->family)
        ->get(route('donor.portal'))
        ->assertRedirect(route('family.portal'));
});
