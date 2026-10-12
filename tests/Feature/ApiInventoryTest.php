<?php

use Illuminate\Support\Facades\Route;

/*
 | T-62 — APP-03 of the security roadmap asks for an inventory of the API
 | surface. A list in a document goes stale the first time somebody adds a
 | route, so the inventory is here, where adding an unguarded one fails the
 | build.
 */

function apiRoutes(): Illuminate\Support\Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->values();
}

it('has an API surface, so this test is testing something', function () {
    expect(apiRoutes())->not->toBeEmpty();
})->group('APP-03');

it('puts every API route behind authentication', function () {
    $open = apiRoutes()
        ->reject(fn ($route) => collect($route->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth:')))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values();

    expect($open)->toBeEmpty();
})->group('APP-03', 'AUTH-02');

it('puts every API route behind a rate limit', function () {
    $open = apiRoutes()
        ->reject(fn ($route) => collect($route->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values();

    expect($open)->toBeEmpty();
})->group('APP-03', 'APP-04');

it('answers an unauthenticated caller with 401 rather than data', function () {
    // The middleware list says it; this checks the routes actually behave.
    foreach ([
        ['get', '/api/cases'],
        ['get', '/api/donor/cases/monthly'],
        ['post', '/api/coordination/lookup'],
        ['post', '/api/visits/sync'],
    ] as [$method, $uri]) {
        $this->{$method.'Json'}($uri)->assertUnauthorized();
    }
})->group('APP-03', 'AUTH-02');

it('states the inventory, so a new route is a deliberate addition', function () {
    // Adding a route makes this fail. That is the point: the list is reviewed
    // when it changes, rather than discovered during a penetration test.
    $inventory = apiRoutes()
        ->map(fn ($route) => collect($route->methods())
            ->reject(fn (string $m) => $m === 'HEAD')
            ->map(fn (string $m) => $m.' '.$route->uri())
            ->all())
        ->flatten()
        ->sort()
        ->values()
        ->all();

    expect($inventory)->toBe([
        'GET api/cases',
        'GET api/cases/{case}',
        'GET api/donor/basket',
        'GET api/donor/campaigns',
        'GET api/donor/campaigns/{campaign}',
        'GET api/donor/case/{case}',
        'GET api/donor/cases/{supportType}',
        'GET api/donor/donations',
        'GET api/donor/jobs',
        'GET api/referrals/{code}',
        'POST api/cases',
        'POST api/cases/{case}/approve',
        'POST api/cases/{case}/change-requests',
        'POST api/cases/{case}/deliveries',
        'POST api/cases/{case}/publish',
        'POST api/cases/{case}/reject',
        'POST api/coordination/lookup',
        'POST api/donations/{donation}/reject',
        'POST api/donations/{donation}/verify',
        'POST api/donor/basket/campaigns',
        'POST api/donor/basket/items',
        'POST api/donor/basket/reserve',
        'POST api/donor/donations',
        'POST api/donor/jobs/{profile}/contact',
        'POST api/referrals/{code}/redeem',
        'POST api/visits/sync',
    ]);
})->group('APP-03');
