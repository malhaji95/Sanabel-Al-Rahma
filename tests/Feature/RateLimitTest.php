<?php

use Illuminate\Support\Facades\Route;

/*
 | T-57 — APP-04 and IAM-03 of the security roadmap. Before this, one route in
 | the whole application was rate limited: the national-ID lookup.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('limits the public login form, which had no limit at all', function () {
    $post = fn () => $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);

    for ($i = 0; $i < config('sanabel.security.rate_limits.login'); $i++) {
        // Each one is a failed login, not a limit refusal.
        expect($post()->status())->not->toBe(429);
    }

    $post()->assertStatus(429);
});

it('counts a login attempt against the email tried, not only the address', function () {
    $attempts = config('sanabel.security.rate_limits.login');

    for ($i = 0; $i < $attempts; $i++) {
        $this->post('/login', ['email' => 'one@example.test', 'password' => 'wrong']);
    }

    // Guessing at one account is spent; a different account from the same
    // address is still answered, so one locked-out email cannot deny the
    // service to a whole office behind a shared address.
    $this->post('/login', ['email' => 'one@example.test', 'password' => 'wrong'])->assertStatus(429);
    expect($this->post('/login', ['email' => 'two@example.test', 'password' => 'wrong'])->status())
        ->not->toBe(429);
});

it('limits every API route, reads tighter on the writes', function () {
    $limits = config('sanabel.security.rate_limits');

    // Every route under /api carries a limiter: none is left open.
    $unlimited = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->reject(fn ($route) => collect($route->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))
        ->map(fn ($route) => $route->uri())
        ->values();

    expect($unlimited)->toBeEmpty();

    // And a write is capped below the read it sits beside.
    expect($limits['api_write'])->toBeLessThan($limits['api']);
});

it('gives the field app a looser limit than the other writes', function () {
    $limits = config('sanabel.security.rate_limits');

    // A delegate coming back online pushes a whole queue of visits at once.
    expect($limits['sync'])->toBeGreaterThan($limits['api_write']);

    $sync = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'api/visits/sync');

    expect($sync->gatherMiddleware())->toContain('throttle:sync');
});

it('keeps the national-ID lookup the tightest route of all', function () {
    $limits = config('sanabel.security.rate_limits');

    expect($limits['national_id'])->toBeLessThan($limits['api_write']);

    $lookup = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'api/coordination/lookup');

    expect($lookup->gatherMiddleware())->toContain('throttle:national-id');
});

it('counts a signed-in account separately from an address', function () {
    // Otherwise one delegate on a shared office line spends the whole
    // allowance and the rest of the office is refused.
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);

    $this->actingAs($delegate);
    expect($this->getJson('/api/cases')->status())->not->toBe(429);

    for ($i = 0; $i < config('sanabel.security.rate_limits.api') + 1; $i++) {
        $this->getJson('/api/cases');
    }

    $this->getJson('/api/cases')->assertStatus(429);

    // A second account from the same address still gets through.
    $other = userWithRole('officer', ['region_id' => $this->region->id]);
    $this->actingAs($other);

    expect($this->getJson('/api/cases')->status())->not->toBe(429);
});
