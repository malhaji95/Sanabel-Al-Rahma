<?php

use App\Models\Provider;
use App\Models\ReferralRequest;
use App\Services\HealthPathService;

/*
 | T-314 — from the association's health-path document V5.1 of 23 November:
 | §4.3 «استبعاد الاتفاقيات غير الفعالة» and §6 «التحقق من الصلاحيات وعزل
 | السجلات على الخادم, API، وليس عبر إخفاء الأزرار في JavaScript».
 |
 | Both halves were already true on the screen and neither on the server. The
 | provider picker left a suspended provider out of its list — a list, not a
 | rule, and the service it called read the provider's type and nothing else.
 | The agreement's own end date was read by nothing anywhere: a card went out
 | under an agreement that had expired months before.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->family = publishedCase($this->region);
    $this->officer = userWithRole('case_officer');
});

function askingFor(string $type): ReferralRequest
{
    return app(HealthPathService::class)->apply(test()->family, $type, 'جراحة');
}

function hospital(array $attributes = []): Provider
{
    return Provider::factory()->create(array_merge([
        'type' => 'hospital',
        'status' => 'active',
        'region_id' => test()->region->id,
    ], $attributes));
}

it('refuses a card to a provider that is not active, on the server', function () {
    $request = askingFor('hospital');

    expect(fn () => app(HealthPathService::class)
        ->issue($request, hospital(['status' => 'suspended']), $this->officer))
        ->toThrow(RuntimeException::class, __('sanabel.health_path.provider_not_active'));

    // And the asking is still open, so it can be answered by somebody else.
    expect($request->refresh()->status)->toBe('open');
})->group('AUTH-01');

it('refuses a card under an agreement whose term has run out', function () {
    $request = askingFor('hospital');
    $expired = hospital(['valid_until' => now()->subMonths(3)->toDateString()]);

    expect(fn () => app(HealthPathService::class)->issue($request, $expired, $this->officer))
        ->toThrow(RuntimeException::class, $expired->valid_until->toDateString());

    expect($request->refresh()->status)->toBe('open');
});

it('issues against an agreement still running, and against one with no term at all', function () {
    $running = app(HealthPathService::class)->issue(
        askingFor('hospital'),
        hospital(['valid_until' => now()->addMonth()->toDateString()]),
        $this->officer,
    );

    expect($running->status)->toBe('issued');

    // A provider with no end date is one whose agreement does not expire, not
    // one whose agreement expired at the beginning of time.
    $openEnded = app(HealthPathService::class)->issue(
        askingFor('hospital'),
        hospital(['valid_until' => null]),
        $this->officer,
    );

    expect($openEnded->status)->toBe('issued');
});

it('refuses on the day after the term ends, and allows the last day itself', function () {
    $lastDay = app(HealthPathService::class)->issue(
        askingFor('hospital'),
        hospital(['valid_until' => now()->toDateString()]),
        $this->officer,
    );

    expect($lastDay->status)->toBe('issued');

    expect(fn () => app(HealthPathService::class)->issue(
        askingFor('hospital'),
        hospital(['valid_until' => now()->subDay()->toDateString()]),
        $this->officer,
    ))->toThrow(RuntimeException::class);
});
