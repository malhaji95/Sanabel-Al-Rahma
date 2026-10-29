<?php

use App\Livewire\PlatformCounters;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Setting;
use App\Services\PlatformStats;
use Illuminate\Support\Facades\Cache;

/*
 | T-183 — «نعم نريده أن يتحرك أمام العين بدون تحميل, ونحن مع فكرة المبالغ لا
 | تظهر إلا بعد تأكيد أمين الصندوق», 29 October.
 |
 | Two halves. The strip now asks its questions again while the page is open,
 | and the money on it still counts verified transfers only — which it has
 | done since T-99, and which polling must not loosen.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Cache::forget('platform.stats');
});

/** A transfer in whatever state the test needs it. */
function transferOf(int $amount, string $status): Donation
{
    return Donation::factory()->create([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => $amount,
        'status' => $status,
    ]);
}

it('counts a verified transfer on the front page', function () {
    transferOf(50_000, 'verified');

    expect(app(PlatformStats::class)->all()['donated'])->toBe(50_000);
});

it('counts nothing the treasurer has not confirmed', function () {
    transferOf(90_000, 'pending');

    $stats = app(PlatformStats::class)->all();

    expect($stats['donated'])->toBe(0)
        ->and($stats['donations'])->toBe(0);
});

it('moves without the page being loaded again', function () {
    $strip = Livewire\Livewire::test(PlatformCounters::class)
        ->assertSee(__('sanabel.public.stat_donated'))
        ->assertSee('0');

    transferOf(75_000, 'verified');
    Cache::forget('platform.stats');

    // The poll is a re-render of the same component, which is what the
    // browser does every fifteen seconds while the strip is on the screen.
    $strip->call('$refresh')->assertSee(number_format(75_000));
});

it('polls only while the strip is on the screen', function () {
    expect(Livewire\Livewire::test(PlatformCounters::class)->html())
        ->toContain('wire:poll.visible');
});

it('answers the database once for every visitor rather than once each', function () {
    transferOf(10_000, 'verified');

    $first = app(PlatformStats::class)->all();

    transferOf(10_000, 'verified');

    // Within the cached breath, the second visitor is served the first
    // visitor's answer rather than a second set of queries.
    expect(app(PlatformStats::class)->all())->toBe($first);
});

it('leaves a figure out when the association switches it off', function () {
    Setting::updateOrCreate(['key' => 'stat_donated'], ['value_json' => false]);
    Setting::forgetLoaded();

    Livewire\Livewire::test(PlatformCounters::class)
        ->assertDontSee(__('sanabel.public.stat_donated'));
});

it('draws the strip on the front page itself', function () {
    $this->get('/')->assertOk()->assertSeeLivewire(PlatformCounters::class);
});
