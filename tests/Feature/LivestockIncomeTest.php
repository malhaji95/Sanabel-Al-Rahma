<?php

use App\Models\Livestock;
use App\Services\NeedEngine;

/*
 | T-52 — approved 11 October: a real, steady, repeating net income from
 | animals is stable income and enters the need calculation; a seasonal or
 | intermittent one is recorded and deducted from nothing.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->family = familyOf($this->region, adults: 1, children: 1);
    $this->engine = app(NeedEngine::class);
});

function animals($family, int $income, ?bool $stable): Livestock
{
    return Livestock::create([
        'beneficiary_id' => $family->getKey(),
        'kind' => 'sheep',
        'head_count' => 12,
        'income_kind' => 'partial_income',
        'monthly_income' => $income,
        'currency' => config('sanabel.currency'),
        'is_stable' => $stable,
    ]);
}

it('counts a steady net income as stable income', function () {
    $before = $this->engine->compute($this->family);
    animals($this->family, 40_000, stable: true);
    $after = $this->engine->compute($this->family);

    expect($after['stable_income'])->toBe($before['stable_income'] + 40_000)
        ->and($after['snapshot']['livestock_income'])->toBe(40_000)
        ->and($after['monthly_need'])->toBe($before['monthly_need'])
        ->and($after['f'])->toBeLessThan($before['f']);
});

it('records a seasonal income and deducts nothing', function () {
    $before = $this->engine->compute($this->family);
    animals($this->family, 40_000, stable: false);
    $after = $this->engine->compute($this->family);

    expect($after['stable_income'])->toBe($before['stable_income'])
        ->and($after['snapshot']['livestock_income'])->toBe(0)
        ->and($after['f'])->toBe($before['f']);
});

it('deducts nothing while the question is unanswered', function () {
    $before = $this->engine->compute($this->family);
    animals($this->family, 40_000, stable: null);

    expect($this->engine->compute($this->family)['stable_income'])->toBe($before['stable_income']);
});

it('ignores a steady flag with no amount behind it', function () {
    $before = $this->engine->compute($this->family);
    animals($this->family, 0, stable: true);

    expect($this->engine->compute($this->family)['stable_income'])->toBe($before['stable_income']);
});

it('adds up several steady herds and leaves the seasonal one out', function () {
    $before = $this->engine->compute($this->family);
    animals($this->family, 15_000, stable: true);
    animals($this->family, 10_000, stable: true);
    animals($this->family, 90_000, stable: false);

    expect($this->engine->compute($this->family)['snapshot']['livestock_income'])->toBe(25_000)
        ->and($this->engine->compute($this->family)['stable_income'])->toBe($before['stable_income'] + 25_000);
});

it('keeps the figure it used in the snapshot when the row changes later', function () {
    $herd = animals($this->family, 40_000, stable: true);
    $snapshot = $this->engine->compute($this->family)['snapshot'];

    $herd->update(['monthly_income' => 500_000]);

    expect($snapshot['livestock_income'])->toBe(40_000);
});
