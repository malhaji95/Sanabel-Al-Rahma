<?php

use App\Models\Donor;
use App\Models\User;

/*
 | T-120 — «انا مسجل دخول الان من حساب المتبرع لكن لا يوجد زر تسجيل خروج من
 | الحساب», 25 October.
 |
 | The route has existed since the login did, and a test even called it. What
 | nobody had checked is whether any page offers it: no template in the
 | application rendered a control that posts to it, so every account signed in
 | on the public site stayed signed in until the session expired on its own.
 |
 | On a borrowed or shared phone that is not an inconvenience, it is the next
 | person reading a donor's giving history — so what these hold is the button
 | and not only the endpoint.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

function donorAccount(): User
{
    $user = userWithRole('donor');

    Donor::factory()->create(['user_id' => $user->id, 'email' => $user->email]);

    return $user;
}

it('offers a signed-in donor a way out, on every page of the site', function () {
    $donor = donorAccount();

    // The pages a donor actually sits on, all drawn by the same shell.
    foreach ([route('home'), route('cases.browse'), route('donor.portal')] as $url) {
        $body = $this->actingAs($donor)->get($url)->assertOk()->getContent();

        expect($body)->toContain(__('sanabel.public.logout'))
            ->and($body)->toContain('action="'.route('logout').'"');
    }
});

it('ends the session rather than only moving the visitor somewhere else', function () {
    $donor = donorAccount();

    $this->actingAs($donor)
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    expect(auth()->check())->toBeFalse();

    // And the page the account was on is a guest's page again.
    expect($this->get(route('home'))->getContent())
        ->toContain(__('sanabel.public.login'))
        ->not->toContain('action="'.route('logout').'"');
});

it('cannot be triggered by loading a page, only by pressing the button', function () {
    /*
     | A link would do: a preloaded page, an image with that address, anything
     | a browser fetches on its own would sign the account out. It is a form
     | carrying the token, so it is a POST and nothing else.
     */
    $donor = donorAccount();

    // Not found rather than not allowed, because the CMS catches every path
    // it does not recognise — but either way, the account is still signed in.
    $this->actingAs($donor)->get(route('logout'))->assertNotFound();

    expect(auth()->check())->toBeTrue();
})->group('APP-03');

it('shows a guest the way in, and no way out', function () {
    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain(__('sanabel.public.login'))
        ->and($body)->not->toContain('action="'.route('logout').'"');
});

it('gives the delegate the same button on the phone the visits are entered on', function () {
    // The device most likely to be lent or lost, and it had no way out either.
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);

    $body = $this->actingAs($delegate)->get(route('field'))->assertOk()->getContent();

    expect($body)->toContain('action="'.route('logout').'"')
        ->and($body)->toContain(__('sanabel.public.logout'));
})->group('IAM-02');
