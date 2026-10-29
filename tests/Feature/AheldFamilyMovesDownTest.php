<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Donor;
use App\Services\BasketService;
use App\Services\CoverageService;
use App\Services\RankingService;

/*
 | T-186 — «الحالة التي يقوم المتبرع بإجراء عملية تحويل لها تبقى في أعلى مع
 | حذف قيمة المبلغ, تظهر مثلًا 0 … وهذا مشكلة: الحالة في أعلى الصفحة وقيمتها 0
 | واللون أو الدرجة ما تغيّرت», 29 October, filmed twice.
 |
 | `remainingNeed()` has always subtracted what is held in a basket — it has
 | to, or two donors fund one family and the second transfer has nowhere to
 | go. The bar, the badge and the list order did not, so one card said
 | «المتبقي: 0» and «نسبة التغطية: 0%» at the same time and sat at the top
 | asking for money somebody had already promised.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->coverage = app(CoverageService::class);
});

/** Holds the whole of this month's remainder for a family. */
function holdWholeMonth($case): void
{
    $baskets = app(BasketService::class);
    $basket = $baskets->openFor(Donor::factory()->create());

    $baskets->addItem($basket, $case, app(CoverageService::class)->remainingNeed($case));
    $baskets->reserve($basket);
}

it('stops calling a family urgent once its month is promised', function () {
    $case = publishedCase($this->region);

    expect($this->coverage->priorityBand($case))->toBe('critical');

    holdWholeMonth($case);

    // The badge answers «who still needs money». This family does not.
    expect($this->coverage->priorityBand($case->fresh()))->toBe('complete');
});

it('keeps the percentage on verified money, which is the only money', function () {
    $case = publishedCase($this->region);
    holdWholeMonth($case);

    $card = (new MaskedCaseResource($case->fresh()))->resolve();

    // Nothing has been confirmed, so nothing is covered …
    expect($card['coverage_percent'])->toBe(0)
        // … and the card says what the rest of the bar is.
        ->and($card['held_percent'])->toBe(100)
        // … and the two agree with the figure beside them, which is the whole
        // point: «المتبقي: 0» and «0%» cannot both be the end of the story.
        ->and($card['remaining_amount'])->toBe(0);
});

it('drops a promised family below one still waiting', function () {
    $promised = publishedCase($this->region);
    $waiting = publishedCase($this->region);

    holdWholeMonth($promised);

    $order = app(RankingService::class)
        ->fundingList('monthly', withCovered: true)
        ->pluck('file_number')
        ->all();

    $promisedAt = array_search($promised->file_number, $order, true);
    $waitingAt = array_search($waiting->file_number, $order, true);

    expect($promisedAt)->toBeGreaterThan($waitingAt);
});

it('takes a promised family off the plain list altogether', function () {
    $promised = publishedCase($this->region);
    $waiting = publishedCase($this->region);

    holdWholeMonth($promised);

    $plain = app(RankingService::class)->fundingList('monthly')->pluck('file_number')->all();

    expect($plain)->toContain($waiting->file_number)
        ->not->toContain($promised->file_number);
});

it('gives the family back when the hold runs out', function () {
    $case = publishedCase($this->region);
    holdWholeMonth($case);

    expect($this->coverage->priorityBand($case->fresh()))->toBe('complete');

    // A basket that was never paid for lets go of what it was holding, and
    // the family is an opportunity again — nothing about it has changed.
    $case->basketItems()->each(fn ($item) => $item->basket->forceFill([
        'reserved_until' => now()->subMinute(),
    ])->save());

    expect($this->coverage->priorityBand($case->fresh()))->toBe('critical')
        ->and((new MaskedCaseResource($case->fresh()))->resolve()['held_percent'])->toBe(0);
});

it('draws no held segment on a family nobody has held', function () {
    $card = (new MaskedCaseResource(publishedCase($this->region)))->resolve();

    expect($card['held_percent'])->toBe(0);
});

it('says only a share, never an amount and never whose basket', function () {
    $case = publishedCase($this->region);
    holdWholeMonth($case);

    $card = (new MaskedCaseResource($case->fresh()))->resolve();

    expect(array_diff(array_keys($card), MaskedCaseResource::ALLOWED_KEYS))->toBeEmpty()
        ->and($card['held_percent'])->toBeInt();
})->group('AUTH-03');
