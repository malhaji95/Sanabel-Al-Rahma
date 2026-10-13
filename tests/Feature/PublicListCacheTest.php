<?php

use App\Models\Donor;
use App\Models\Setting;
use App\Services\DonorCaseList;
use Illuminate\Support\Facades\Cache;

/*
 | T-87 — the minute the donor list is held for.
 |
 | A link shared on WhatsApp is the load this platform will actually meet, and
 | it arrives all at once. Holding the figures for a minute turns that from a
 | ranking per visitor into one ranking a minute.
 |
 | What is held is data, never a rendered page: the page is drawn fresh every
 | time with its own session and its own token, and only the figures are
 | shared. And two things are never shared — a settings change takes effect at
 | once, and an account held to its own region never reads anybody else's list.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();

    // phpunit.xml computes every request; this is the test of the cache.
    config(['sanabel.public_list_cache_seconds' => 60]);
    Cache::flush();

    $this->list = app(DonorCaseList::class);
});

it('answers a second time without ranking again', function () {
    publishedCase($this->region);
    publishedCase($this->region);

    expect($this->list->page('monthly', null, 1, 12)['total'])->toBe(2);

    publishedCase($this->region);

    // The third family is published and the list does not know yet, which is
    // the whole point: within the minute, nobody pays for the ranking again.
    expect($this->list->page('monthly', null, 1, 12)['total'])->toBe(2);

    Cache::flush();

    expect($this->list->page('monthly', null, 1, 12)['total'])->toBe(3);
});

it('never serves one list to an account held inside its own region', function () {
    publishedCase($this->region);
    publishedCase($this->region);

    // A visitor fills the cache.
    expect($this->list->page('monthly', null, 1, 12)['total'])->toBe(2);

    publishedCase($this->region);

    // A delegate is held to their own subtree by a global scope, so their list
    // is not the visitor's list and must not be answered from it. If the key
    // ignored that, this would read the visitor's stale 2.
    $this->actingAs(userWithRole('delegate', ['region_id' => $this->region->id]));

    expect($this->list->page('monthly', null, 1, 12)['total'])->toBe(3);
});

it('lets a switch the association closes take effect at once', function () {
    // The wallet's last four digits (T-82) are a card's most sensitive field
    // and they are behind a switch. A minute of stale cache is a minute of
    // showing what the association has just decided to stop showing.
    $case = publishedCase($this->region, attributes: ['wallet_encrypted' => '0912345678']);

    $card = $this->list->page('monthly', null, 1, 12)['cards'][0];

    expect($card['file_number'])->toBe($case->file_number)
        ->and($card['wallet_last4'])->toBe('5678');

    Setting::put('show_wallet_last4', false);

    expect($this->list->page('monthly', null, 1, 12)['cards'][0]['wallet_last4'])->toBeNull();
})->group('AUTH-03');

it('draws the page fresh even when its figures are held', function () {
    publishedCase($this->region);

    $donor = userWithRole('donor', ['region_id' => $this->region->id]);
    Donor::factory()->create(['user_id' => $donor->id]);

    $first = $this->actingAs($donor)->get(route('cases.browse'));
    $second = $this->actingAs($donor)->get(route('cases.browse'));

    $first->assertOk();
    $second->assertOk();

    // Same figures, drawn twice: what is cached is the data behind the page,
    // so each render carries its own CSRF token rather than somebody else's.
    expect($first->getContent())->not->toBe($second->getContent());
});
