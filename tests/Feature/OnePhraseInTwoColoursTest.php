<?php

use App\Livewire\GiveAgift;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Gift;
use App\Models\GiftCard;
use App\Models\Setting;
use App\Models\User;
use App\Services\DonationService;
use Livewire\Livewire;

/*
 | T-264 — «نحتاج يقدر ينتقل بين بنرين, لأنه كل عبارة بتكون عندنا بلونين
 | وليس لون واحد», 8 November, with the إحسان page they sent: one phrase,
 | two cards side by side under «شكل البطاقة».
 |
 | What is held here is the thing that would otherwise rot: the colour the
 | giver chose is kept on the gift, so the card the recipient opens months
 | later is the one that was chosen, whatever the association has done to
 | the design in between.
 */
beforeEach(function () {
    seedCore();
    Setting::put('platform_wallet', ['number' => '0999111222', 'holder' => 'سنابل الوفاء']);

    $this->pair = GiftCard::factory()->create([
        'name_ar' => 'بوركتم الموهود',
        'image_path' => 'gift-cards/newborn-blue.webp',
        'image_path_alt' => 'gift-cards/newborn-pink.webp',
        'variant_ar' => 'أزرق',
        'variant_alt_ar' => 'وردي',
    ]);

    $this->single = GiftCard::factory()->create([
        'name_ar' => 'مبارك النجاح',
        'image_path' => 'gift-cards/success.webp',
        'image_path_alt' => null,
    ]);
});

function colourGiver(): User
{
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id]);

    return $user;
}

function giftCardRow(GiftCard $design, string $variant): array
{
    return [
        'kind' => 'general',
        'campaign_id' => null,
        'gift_card_id' => $design->id,
        'card_variant' => $variant,
        'giver_name_ar' => 'أبو محمد',
        'recipient_name_ar' => 'أم خالد',
        'recipient_country_code' => '+963',
        'recipient_phone' => '0912345678',
        'amount' => 100000,
        'show_amount' => true,
        'schedule' => false,
        'send_at' => null,
    ];
}

it('knows a phrase that has a second colour from one that has not', function () {
    expect($this->pair->hasAlternate())->toBeTrue()
        ->and($this->single->hasAlternate())->toBeFalse()
        ->and($this->pair->imageUrlFor('b'))->toContain('newborn-pink')
        ->and($this->pair->imageUrlFor('a'))->toContain('newborn-blue')
        // A design with one picture answers with it whatever is asked for.
        ->and($this->single->imageUrlFor('b'))->toContain('success');
});

it('offers the switch only where there are two to switch between', function () {
    $page = Livewire::test(GiveAgift::class);

    $page->set('cards.0.gift_card_id', $this->single->id)
        ->assertDontSee(__('sanabel.gift.card_colour'));

    $page->set('cards.0.gift_card_id', $this->pair->id)
        ->assertSee(__('sanabel.gift.card_colour'))
        // The association's own words for the two, where they wrote them.
        ->assertSee('أزرق')
        ->assertSee('وردي');
});

it('keeps the colour the giver chose on the gift', function () {
    Livewire::actingAs(colourGiver())
        ->test(GiveAgift::class)
        ->set('cards', [giftCardRow($this->pair, 'b')])
        ->set('transactionRef', 'GIFT-COLOUR-1')
        ->call('record')
        ->assertHasNoErrors();

    expect(Gift::first()->card_variant)->toBe('b');
});

it('forgets the colour when the giver changes the phrase', function () {
    Livewire::test(GiveAgift::class)
        ->set('cards.0.gift_card_id', $this->pair->id)
        ->set('cards.0.card_variant', 'b')
        ->set('cards.0.gift_card_id', $this->single->id)
        ->assertSet('cards.0.card_variant', 'a');
});

it('refuses a second colour on a design that has only one', function () {
    Livewire::actingAs(colourGiver())
        ->test(GiveAgift::class)
        ->set('cards', [giftCardRow($this->single, 'b')])
        ->set('transactionRef', 'GIFT-COLOUR-2')
        ->call('record')
        ->assertHasNoErrors();

    // Not an error to the giver: there is one picture, so that is the one
    // they are given, and the row says so rather than pointing at nothing.
    expect(Gift::first()->card_variant)->toBe('a');
});

it('draws the chosen colour on the card the recipient opens', function () {
    Livewire::actingAs(colourGiver())
        ->test(GiveAgift::class)
        ->set('cards', [giftCardRow($this->pair, 'b')])
        ->set('transactionRef', 'GIFT-COLOUR-3')
        ->call('record');

    $donation = Donation::where('transaction_ref', 'GIFT-COLOUR-3')->firstOrFail();
    app(DonationService::class)->verify($donation, userWithRole('executive_director')->id);

    $gift = Gift::first()->refresh();

    $body = $this->get(route('gift.card', ['token' => $gift->token]))->assertOk()->getContent();

    expect($body)->toContain('newborn-pink')
        ->and($body)->not->toContain('newborn-blue');
});
