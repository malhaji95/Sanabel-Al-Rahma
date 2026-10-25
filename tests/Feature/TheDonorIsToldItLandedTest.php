<?php

use App\Livewire\DonorBasket;
use App\Livewire\GeneralDonation;
use App\Models\Donor;
use App\Models\User;
use App\Services\BasketService;

/*
 | T-135 — «من صفحة المتبرع في الواجهة عند ادخال التبرع يجب ان تعطي رسالة
 | نجاح عملية التبرع. هي وصلت على لوحة التحكم لكن لم تظهر اي رسالة للمتبرع
 | عند الضغط على زر التسجيل», 28 October.
 |
 | The message was there and nobody saw it: it is drawn at the top of a long
 | page and the button that triggers it is at the bottom, so the donor pressed
 | «تسجيل», the page stayed where it was, and nothing appeared to happen. Every
 | confirmation and every refusal now brings itself into view.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** A signed-in donor account. */
function givingDonor(): User
{
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id]);

    return $user->refresh();
}

it('confirms a transfer from the basket, where the donor is looking', function () {
    $donor = givingDonor();
    $family = publishedCase($this->region);

    $baskets = app(BasketService::class);
    $basket = $baskets->currentFor($donor->donor);
    $baskets->addItem($basket, $family, 5_000);
    $baskets->reserve($basket);

    $page = Livewire\Livewire::actingAs($donor)
        ->test(DonorBasket::class)
        ->set('transactionRef', 'TRX-BASKET-SEEN')
        ->call('recordTransfer');

    expect($page->get('notice'))->toBe(__('sanabel.public.transfer_recorded'))
        ->and($page->get('thanks'))->toBeTrue();

    // And it is brought into view rather than left at the top of the page.
    expect($page->html())
        ->toContain(__('sanabel.public.transfer_recorded'))
        ->toContain('scrollIntoView');
});

it('confirms a general transfer the same way', function () {
    $page = Livewire\Livewire::test(GeneralDonation::class)
        ->set('amount', 25_000)
        ->set('transactionRef', 'TRX-GENERAL-SEEN')
        ->set('name', 'أبو محمد')
        ->call('record');

    expect($page->get('notice'))->toBe(__('sanabel.public.transfer_recorded'));

    expect($page->html())
        ->toContain(__('sanabel.public.thanks_blessing'))
        ->toContain('scrollIntoView');
});

it('brings a refusal into view too, so a duplicate reference is not typed twice', function () {
    Livewire\Livewire::test(GeneralDonation::class)
        ->set('amount', 10_000)
        ->set('transactionRef', 'TRX-SAME')
        ->set('name', 'أبو محمد')
        ->call('record');

    $page = Livewire\Livewire::test(GeneralDonation::class)
        ->set('amount', 10_000)
        ->set('transactionRef', 'TRX-SAME')
        ->set('name', 'أبو محمد')
        ->call('record');

    expect($page->get('error'))->not->toBeNull()
        ->and($page->html())->toContain('scrollIntoView');
})->group('PAY-01');

it('says plainly that the coverage follows the association verifying it', function () {
    // The donor is not told their gift is counted before anybody checked it.
    expect(__('sanabel.public.transfer_recorded'))->toContain('بعد التحقق');
});
