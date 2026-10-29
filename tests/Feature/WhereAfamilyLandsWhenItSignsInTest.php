<?php

use App\Models\Beneficiary;

/*
 | T-194 — «صفحة الأسرة مو جاهزة, ظاهرة وكأنها صفحة المتبرع», 29 October,
 | over a screen headed «حسابي — أسرة تجريبية» showing «تبرعاتي».
 |
 | The page was ready; nothing led to it. Login sent everybody who was not a
 | delegate to the donor portal, and the account menu offered a basket and a
 | favourites list to a household that has neither.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** A household and the account it signs in with. */
function familyWithAnAccount($region): array
{
    $user = userWithRole('beneficiary');
    $case = familyOf($region, adults: 1, children: 0);
    $case->forceFill(['user_id' => $user->id])->save();

    return [$user, $case];
}

it('lands a household on its own page, not on the donor’s', function () {
    [$user] = familyWithAnAccount($this->region);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('family.portal'));
});

it('still lands a donor on the donor’s page', function () {
    $user = userWithRole('donor');

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('donor.portal'));
});

it('offers a household its file and not a basket it has no use for', function () {
    [$user] = familyWithAnAccount($this->region);

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertSee(__('sanabel.portal.my_file'))
        ->assertDontSee(__('sanabel.favourites.title'));
});

it('leaves the donor’s own menu as it was', function () {
    $user = userWithRole('donor');

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertSee(__('sanabel.public.account_home'))
        ->assertSee(__('sanabel.favourites.title'));
});

it('shows the household its own file once it is there', function () {
    [$user, $case] = familyWithAnAccount($this->region);

    $this->actingAs($user)->get(route('family.portal'))
        ->assertOk()
        ->assertSee($case->file_number);

    expect(Beneficiary::withoutGlobalScopes()->where('user_id', $user->id)->exists())->toBeTrue();
});
