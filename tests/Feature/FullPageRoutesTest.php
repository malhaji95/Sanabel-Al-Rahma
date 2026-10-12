<?php

use App\Models\Beneficiary;

/*
 | T-67 — the full-page Livewire routes, opened the way a browser opens them.
 |
 | The suite tested these components with Livewire::test(), which renders the
 | component and never applies a page layout. So a component with no layout
 | rendered green in the suite and answered 500 in a browser. A real browser
 | run found it on /portal/family; this covers all of them over HTTP.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('serves the public case list', function () {
    $this->get('/cases')->assertOk();
});

it('serves the donor portal and its basket', function () {
    $donor = userWithRole('donor', ['region_id' => $this->region->id]);

    $this->actingAs($donor)->get('/portal')->assertOk();
    $this->actingAs($donor)->get('/portal/basket')->assertOk();
});

it('serves the household its own page', function () {
    $case = publishedCase($this->region);
    $account = userWithRole('beneficiary', ['region_id' => $this->region->id]);
    $case->forceFill(['user_id' => $account->getKey()])->save();

    $this->actingAs($account)->get('/portal/family')->assertOk();
});

it('serves the household page to an account with no file, without failing', function () {
    // The empty state, which is the path a new account takes.
    $account = userWithRole('beneficiary', ['region_id' => $this->region->id]);

    $this->actingAs($account)->get('/portal/family')->assertOk();
});

it('renders a real page, not a bare component', function () {
    // What the layout actually buys: the document shell, the RTL direction and
    // the Arabic locale that CLAUDE.md §5 requires on every page. A component
    // rendered without a layout has none of it, and that is invisible to
    // Livewire::test().
    $account = userWithRole('beneficiary', ['region_id' => $this->region->id]);
    $case = publishedCase($this->region);
    $case->forceFill(['user_id' => $account->getKey()])->save();

    $html = $this->actingAs($account)->get('/portal/family')->getContent();

    expect($html)->toContain('<!DOCTYPE html>')
        ->and($html)->toContain('dir="rtl"')
        ->and($html)->toContain('lang="ar"');
})->group('APP-06');

it('holds every full-page component to the same contract', function () {
    $donor = userWithRole('donor', ['region_id' => $this->region->id]);
    $account = userWithRole('beneficiary', ['region_id' => $this->region->id]);
    $case = publishedCase($this->region);
    $case->forceFill(['user_id' => $account->getKey()])->save();

    foreach ([
        ['/cases', null],
        ['/portal', $donor],
        ['/portal/basket', $donor],
        ['/portal/family', $account],
    ] as [$path, $user]) {
        $response = $user ? $this->actingAs($user)->get($path) : $this->get($path);

        $response->assertOk();
        expect($response->getContent())->toContain('dir="rtl"');
    }
})->group('APP-06');
