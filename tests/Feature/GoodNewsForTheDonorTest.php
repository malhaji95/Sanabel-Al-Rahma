<?php

use App\Http\Resources\MaskedCaseResource;
use App\Livewire\AddToBasket;
use App\Services\Money;
use Illuminate\Support\Facades\Blade;

/*
 | T-139 — «نحتاج لما يجيي بده يتبرع بمبلغ اكبر يكتب بشرى خير هذه العائلة
 | يكتمل تمويلها 32656 ومازالت أبواب العطاء مفتوحة لعائلات أخرى. ولما يكتمل
 | كامل المبلغ يتم الكتابة فوق نسبة التغطية (بُشرى خير! هذه العائلة اكتمل
 | تمويلها, لكن خلف كل عائلة تم تمويلها, عائلة أخرى تنتظر فرحة التمويل.
 | ساهم في صناعة الفرحة القادمة)», 28 October.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('tells a donor whose amount completes the family, with the figure', function () {
    $family = publishedCase($this->region);
    $card = (new MaskedCaseResource($family))->resolve();
    $remaining = $card['remaining_amount'];

    expect($remaining)->toBeGreaterThan(0);

    $html = Livewire\Livewire::test(AddToBasket::class, [
        'fileNumber' => $family->file_number,
        'remaining' => $remaining,
    ])->html();

    expect($html)->toContain(__('sanabel.funding.good_news_will_complete', [
        'amount' => number_format($remaining),
        'currency' => Money::label(),
    ]))
        // Shown the moment the digit is typed, not a round trip later.
        ->and($html)->toContain('typed >= remaining');
});

it('writes the association sentence above the coverage, once the month is answered', function () {
    $family = publishedCase($this->region);

    $card = (new MaskedCaseResource($family))->resolve();
    $card['is_covered'] = true;
    $card['coverage_percent'] = 100;

    $html = Blade::render('<x-masked-case-card :case="$case" />', ['case' => $card]);

    expect($html)->toContain(__('sanabel.funding.good_news_complete'))
        ->toContain(__('sanabel.funding.good_news_browse'))
        // Above the percentage, which is where the eye lands.
        ->and(strpos($html, __('sanabel.funding.good_news_complete')))
        ->toBeLessThan(strpos($html, __('sanabel.beneficiary.coverage')));
});

it('says nothing of the kind while the family is still waiting', function () {
    $family = publishedCase($this->region);

    $card = (new MaskedCaseResource($family))->resolve();
    $card['is_covered'] = false;
    $card['coverage_percent'] = 40;

    $html = Blade::render('<x-masked-case-card :case="$case" />', ['case' => $card]);

    expect($html)->not->toContain(__('sanabel.funding.good_news_complete'));
});

it('keeps the sentences the association wrote, word for word', function () {
    expect(__('sanabel.funding.good_news_complete'))
        ->toContain('خلف كل عائلة تم تمويلها')
        ->toContain('ساهم في صناعة الفرحة القادمة')
        ->and(__('sanabel.funding.good_news_will_complete', ['amount' => '1', 'currency' => 'x']))
        ->toContain('ما زالت أبواب العطاء مفتوحة لعائلات أخرى');
});
