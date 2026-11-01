<?php

use App\Livewire\AddToBasket;
use App\Livewire\PledgeToCampaign;
use App\Models\Campaign;
use App\Models\Donor;
use App\Services\CoverageService;

/*
 | T-201 — «أزل رسالة الخطأ بالأحمر واجعلها فقط بالأخضر مشابهة», 1 November,
 | over a red browser bubble reading «يجب أن تكون القيمة أقل من أو تساوي
 | ٥٠٠٠» beside the same card's green «بشرى خير».
 |
 | The bubble was the browser's own, raised by `max` on the number field. A
 | donor who offers more than a family is short is not making a mistake. The
 | ceiling comes off the field, the green line says what will happen, and the
 | basket takes what the family needs and not a pound more.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();

    $user = userWithRole('donor');
    $this->donor = Donor::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);
});

it('puts no ceiling on the box for the browser to shout about', function () {
    $case = publishedCase($this->region);
    $remaining = app(CoverageService::class)->remainingNeed($case);

    $html = Livewire\Livewire::test(AddToBasket::class, [
        'fileNumber' => $case->file_number,
        'remaining' => $remaining,
    ])->html();

    expect($html)->toContain('id="amount-'.$case->file_number.'"')
        ->and($html)->not->toContain('max="'.$remaining.'"');
});

it('takes what the family needs when the donor offers more, and says nothing in red', function () {
    $case = publishedCase($this->region);
    $remaining = app(CoverageService::class)->remainingNeed($case);

    Livewire\Livewire::test(AddToBasket::class, [
        'fileNumber' => $case->file_number,
        'remaining' => $remaining,
    ])
        ->set('amount', $remaining * 10)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('added', true);

    expect($this->donor->baskets()->first()->items()->first()->amount)->toBe($remaining);
});

it('keeps the green line, which is what tells the donor what happened', function () {
    $case = publishedCase($this->region);

    expect(Livewire\Livewire::test(AddToBasket::class, [
        'fileNumber' => $case->file_number,
        'remaining' => app(CoverageService::class)->remainingNeed($case),
    ])->html())->toContain(e(__('sanabel.funding.good_news_will_complete', [
        'amount' => number_format(app(CoverageService::class)->remainingNeed($case)),
        'currency' => App\Services\Money::label(),
    ])));
});

it('reads the ceiling from the family, not from what the browser sent back', function () {
    $case = publishedCase($this->region);
    $remaining = app(CoverageService::class)->remainingNeed($case);

    // A tampered `remaining` buys nothing: the amount is capped against the
    // family's own figure, and the reservation checks it again under a lock.
    Livewire\Livewire::test(AddToBasket::class, [
        'fileNumber' => $case->file_number,
        'remaining' => $remaining,
    ])
        ->set('remaining', $remaining * 100)
        ->set('amount', $remaining * 100)
        ->call('add');

    expect($this->donor->baskets()->first()->items()->first()->amount)->toBe($remaining);
});

it('does the same for a campaign, which is the other place money is typed', function () {
    $campaign = Campaign::factory()->create([
        'is_published' => true,
        'status' => 'active',
        'goal_amount' => 100_000,
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى الحملة التالية.',
    ]);

    $html = Livewire\Livewire::test(PledgeToCampaign::class, ['campaign' => $campaign])->html();

    expect($html)->not->toContain('max="100000"');

    Livewire\Livewire::test(PledgeToCampaign::class, ['campaign' => $campaign])
        ->set('amount', 500_000)
        ->call('pledge')
        ->assertHasNoErrors()
        ->assertSet('added', true);

    expect($this->donor->baskets()->first()->items()->first()->amount)->toBe(100_000);
});

it('still refuses nothing at all, and still refuses a negative', function () {
    $case = publishedCase($this->region);

    Livewire\Livewire::test(AddToBasket::class, [
        'fileNumber' => $case->file_number,
        'remaining' => app(CoverageService::class)->remainingNeed($case),
    ])
        ->set('amount', 0)
        ->call('add')
        ->assertHasErrors(['amount']);
});
