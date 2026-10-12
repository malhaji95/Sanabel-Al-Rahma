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
})->group('IAM-03');

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
})->group('IAM-03');

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
})->group('APP-04');

it('gives the field app a looser limit than the other writes', function () {
    $limits = config('sanabel.security.rate_limits');

    // A delegate coming back online pushes a whole queue of visits at once.
    expect($limits['sync'])->toBeGreaterThan($limits['api_write']);

    $sync = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'api/visits/sync');

    expect($sync->gatherMiddleware())->toContain('throttle:sync');
})->group('APP-04');

it('keeps the national-ID lookup the tightest route of all', function () {
    $limits = config('sanabel.security.rate_limits');

    expect($limits['national_id'])->toBeLessThan($limits['api_write']);

    $lookup = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'api/coordination/lookup');

    expect($lookup->gatherMiddleware())->toContain('throttle:national-id');
})->group('APP-04');

it('limits the Livewire endpoint, which the api/ check missed', function () {
    // T-66. The browser does not write through api/: the donor portal, the
    // family portal and every panel screen write through /livewire/update,
    // and the public case list reaches it without signing in at all. It was
    // the one unthrottled write path left after T-57, and the test that was
    // meant to catch that only looked at routes under api/.
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'livewire/update');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:livewire')
        // 'web' has to stay: dropping it takes the session and CSRF with it.
        ->and($route->gatherMiddleware())->toContain('web');
})->group('APP-04');

it('leaves no POST route in the application unlimited', function () {
    // The api/-only version of this check is what let the Livewire endpoint
    // through. A logout is exempt: refusing to let somebody sign out is worse
    // than the request it would have refused.
    $open = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('POST', $r->methods(), true))
        ->reject(fn ($r) => str_ends_with($r->uri(), 'logout'))
        ->reject(fn ($r) => collect($r->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle')))
        ->map(fn ($r) => $r->uri())
        ->values();

    expect($open)->toBeEmpty();
})->group('APP-04');

it('keeps the Livewire limit generous enough for a clerk on a long file', function () {
    // A live form sends a request per field, so a clerk working through a
    // family file legitimately makes hundreds in a few minutes. The point is
    // to stop a script, not to slow the office down.
    $limits = config('sanabel.security.rate_limits');

    expect($limits['livewire'])->toBeGreaterThan($limits['api']);
})->group('APP-04');

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
})->group('IAM-03');
