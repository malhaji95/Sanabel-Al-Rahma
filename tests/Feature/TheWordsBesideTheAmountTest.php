<?php

use App\Http\Resources\MaskedCaseResource;
use App\Livewire\DonorBasket;
use App\Models\Donation;
use App\Models\Donor;
use App\Services\BasketService;
use App\Services\Money;
use Illuminate\Support\Facades\Blade;

/*
 | T-136 — «استبدل SYP بـ نحتاج نكتبها بالعربي جنب المبلغ (ليرة سورية جديدة)
 | لاننا نخاف يجيي ياكد الطلب بباله قديمه ويسدد جديدة, هون مشكلة هيك»,
 | 28 October.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('writes the words a donor reads, and keeps the code the column holds', function () {
    expect(Money::label())->toBe('ليرة سورية جديدة')
        ->and(Money::short())->toBe('ل.س.ج')
        // Rule 9 — the stored code does not move.
        ->and(config('sanabel.currency'))->toBe('SYP')
        // A currency nobody has named is printed as it is, not guessed at.
        ->and(Money::label('EUR'))->toBe('EUR');
});

it('says it in full on the card a donor reads', function () {
    $family = publishedCase($this->region);

    $card = (new MaskedCaseResource($family))->resolve();

    $html = Blade::render(
        '<x-masked-case-card :case="$case" />',
        ['case' => $card],
    );

    expect($html)->toContain('ليرة سورية جديدة')
        // The stored code is never the word on the screen.
        ->and($html)->not->toContain('>SYP<');
});

it('says it in full in the basket, where the transfer is about to be made', function () {
    $user = userWithRole('donor');
    $donor = Donor::factory()->create(['user_id' => $user->id]);
    $family = publishedCase($this->region);

    $baskets = app(BasketService::class);
    $basket = $baskets->currentFor($donor);
    $baskets->addItem($basket, $family, 5_000);

    $html = Livewire\Livewire::actingAs($user->refresh())
        ->test(DonorBasket::class)
        ->html();

    expect($html)->toContain('ليرة سورية جديدة');
});

it('says it on the home page and on the way a donation is still stored', function () {
    publishedCase($this->region);

    $html = $this->get('/')->assertSuccessful()->getContent();

    expect($html)->toContain('ليرة سورية جديدة');

    // And a recorded gift still carries the code, not the words.
    $donation = Donation::factory()->create(['donor_id' => Donor::factory()->create()->id]);

    expect($donation->currency)->toBe('SYP');
});
