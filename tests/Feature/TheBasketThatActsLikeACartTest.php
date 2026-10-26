<?php

use App\Livewire\AddToBasket;
use App\Livewire\BasketCount;
use App\Livewire\DonorBasket;
use App\Models\Basket;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\User;
use App\Services\BasketService;

/*
 | T-147 — «المفروض عند اضافة اي مبلغ للسله يضل بنفس المكان مع اعطاءه خيار
 | مواصلة التبرع او اتمام عملية التبرع … موجود بالسله منتج ولكنه مايظهر شي …
 | استبدال كلمة حجز السلة بـ تأكيد التبرع ومانحتاح في السلة تظهر كامل الحاله
 | يكفي يظهر رقم الملف المبلغ المتبرع به مع الاستطاعه انه يخفض المبلغ او يزيده
 | مع نسبة التغطيه … مثل سلة المشتريات», 29 October.
 |
 | Underneath the wording was a page that did not work at all. The box under
 | each family is a nested Livewire component; a second copy of Alpine, started
 | from resources/js/app.js, walked the page before Livewire's own and left
 | nothing for it to initialise, so every nested component on the funding list
 | was skipped. The browser submitted the form itself, the page reloaded to the
 | top, and nothing reached the basket. That is the «يطالعني الى اعلى الصفحة»
 | and the «مايظهر شي» in one cause, and it is covered here by the assertion
 | that the page loads one Alpine and no more.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** A signed-in donor account with a donor row. */
function cartDonor(): User
{
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id]);

    return $user->refresh();
}

it('loads Alpine once, so the boxes nested in the list are alive', function () {
    // Livewire ships Alpine and starts it with its own plugins bound. The
    // layout must not start a second copy: when it did, `wire:submit` on the
    // nested box was never bound and the browser submitted the form itself.
    expect(file_get_contents(base_path('resources/js/app.js')))
        ->not->toContain("from 'alpinejs'");
});

it('stays where it is after an addition, and offers the two ways on', function () {
    $donor = cartDonor();
    $family = publishedCase($this->region);

    $page = Livewire\Livewire::actingAs($donor)
        ->test(AddToBasket::class, [
            'fileNumber' => $family->file_number,
            'remaining' => 50_000,
        ])
        ->set('amount', 12_000)
        ->call('add');

    expect($page->get('added'))->toBeTrue();

    $page->assertSee(__('sanabel.public.added_to_basket'))
        ->assertSee(__('sanabel.public.continue_donating'))
        ->assertSee(__('sanabel.public.complete_donation'));

    // «مواصلة التبرع» puts the box back without leaving the list.
    $page->call('keepGiving');

    expect($page->get('added'))->toBeFalse();
    $page->assertDontSee(__('sanabel.public.complete_donation'));
});

it('counts what is in the basket where the donor can see it', function () {
    $donor = cartDonor();
    $family = publishedCase($this->region);

    Livewire\Livewire::actingAs($donor)
        ->test(BasketCount::class)
        ->assertDontSee(__('sanabel.public.basket_items'));

    $baskets = app(BasketService::class);
    $baskets->addItem($baskets->currentFor($donor->donor), $family, 7_000);

    Livewire\Livewire::actingAs($donor)
        ->test(BasketCount::class)
        ->assertSee(__('sanabel.public.basket_items'))
        ->assertSee('1');
});

it('reads the basket without creating one', function () {
    $donor = cartDonor();

    Livewire\Livewire::actingAs($donor)->test(BasketCount::class);

    expect(Basket::where('donor_id', $donor->donor->id)->count())->toBe(0);
});

it('shows a line and not a whole file: number, amount, coverage', function () {
    $donor = cartDonor();
    $family = publishedCase($this->region);

    $baskets = app(BasketService::class);
    $baskets->addItem($baskets->currentFor($donor->donor), $family, 9_000);

    $page = Livewire\Livewire::actingAs($donor)->test(DonorBasket::class);

    $page->assertSee($family->file_number)
        ->assertSee(__('sanabel.beneficiary.coverage'))
        // The button says what it does, which is the association's own wording.
        ->assertSee(__('sanabel.public.reserve'))
        // And the whole file is not spread over the basket.
        ->assertDontSee(__('sanabel.masked.family_composition'));

    expect(__('sanabel.public.reserve'))->toBe('تأكيد التبرع');
});

it('lets the donor lower the amount on the line, and refuses more than the need', function () {
    $donor = cartDonor();
    $family = publishedCase($this->region);

    $baskets = app(BasketService::class);
    $item = $baskets->addItem($baskets->currentFor($donor->donor), $family, 9_000);

    $page = Livewire\Livewire::actingAs($donor)
        ->test(DonorBasket::class)
        ->set("amounts.{$item->id}", 4_000);

    expect($item->refresh()->amount)->toBe(4_000)
        ->and($page->get('notice'))->toBe(__('sanabel.public.amount_updated'));

    // Above the family's remaining need the line is put back as it was.
    $page->set("amounts.{$item->id}", 99_000_000);

    expect($item->refresh()->amount)->toBe(4_000)
        ->and($page->get('error'))->toBe(__('sanabel.basket.exceeds_remaining'))
        ->and($page->get("amounts.{$item->id}"))->toBe(4_000);
});

it('does not let one donor edit another donor\'s line', function () {
    $mine = cartDonor();
    $theirs = cartDonor();
    $family = publishedCase($this->region);

    $baskets = app(BasketService::class);
    $item = $baskets->addItem($baskets->currentFor($theirs->donor), $family, 9_000);

    Livewire\Livewire::actingAs($mine)
        ->test(DonorBasket::class)
        ->set("amounts.{$item->id}", 1_000);

    expect($item->refresh()->amount)->toBe(9_000);
});

it('gives the donor a basket they can see once the transfer is recorded', function () {
    $donor = cartDonor();
    $first = publishedCase($this->region);
    $second = publishedCase($this->region);

    $baskets = app(BasketService::class);
    $paid = $baskets->currentFor($donor->donor);
    $baskets->addItem($paid, $first, 5_000);
    $baskets->reserve($paid);

    Donation::factory()->create([
        'donor_id' => $donor->donor->id,
        'basket_id' => $paid->id,
        'designation' => 'earmarked',
        'status' => 'pending',
    ]);

    // The held basket is waiting on the finance desk, not on the donor. The
    // next family they add goes into a basket they can still see — before
    // this, it went into a new open basket behind the paid one, and the
    // screen kept showing the paid one.
    $next = $baskets->currentFor($donor->donor);
    $baskets->addItem($next, $second, 3_000);

    expect($next->id)->not->toBe($paid->id);

    Livewire\Livewire::actingAs($donor)
        ->test(DonorBasket::class)
        ->assertSee($second->file_number)
        ->assertDontSee($first->file_number);
});
