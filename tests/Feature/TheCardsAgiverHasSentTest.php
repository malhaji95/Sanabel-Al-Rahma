<?php

use App\Livewire\DonorPortal;
use App\Models\Donor;
use App\Models\Gift;
use App\Models\GiftCard;
use App\Services\DonationService;
use App\Services\GiftService;
use Livewire\Livewire;

/*
 | T-268 — found in the general audit of 8 November.
 |
 | `GiftService::shareLink()` has built the giver's prefilled WhatsApp
 | message since T-241, and nothing in the application ever called it. The
 | giver was told in a notification that their card was ready and handed no
 | list, no card and no way to send it on — so the only channel phase one
 | has for a gift, the donor's own telephone, was never opened.
 */
beforeEach(function () {
    seedCore();
    $this->design = GiftCard::factory()->create();
});

function giverWithGift(array $overrides = []): array
{
    $user = userWithRole('donor');
    $donor = Donor::factory()->create(['user_id' => $user->id]);

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => 100_000,
        'transaction_ref' => 'GIFT-SHARE-'.uniqid(),
    ]);

    $gift = app(GiftService::class)->attach($donation, [array_merge([
        'gift_card_id' => test()->design->id,
        'giver_name_ar' => 'أبو محمد',
        'recipient_name_ar' => 'أم خالد',
        'recipient_country_code' => '+963',
        'recipient_phone' => '0912345678',
        'amount' => 100_000,
        'show_amount' => true,
        'send_at' => null,
    ], $overrides)])->first();

    return [$user, $donation, $gift];
}

it('shows the giver a card once the desk has verified the transfer', function () {
    [$user, $donation, $gift] = giverWithGift();

    Livewire::actingAs($user)->test(DonorPortal::class)
        ->assertDontSee(__('sanabel.gift.my_cards'));

    app(DonationService::class)->verify($donation, userWithRole('executive_director')->id);
    app(GiftService::class)->issueDue();

    Livewire::actingAs($user)->test(DonorPortal::class)
        ->assertSee(__('sanabel.gift.my_cards'))
        ->assertSee($gift->refresh()->reference_no)
        ->assertSee('أم خالد');
});

it('hands them the WhatsApp message that was written and never used', function () {
    [$user, $donation, $gift] = giverWithGift();

    app(DonationService::class)->verify($donation, userWithRole('executive_director')->id);
    app(GiftService::class)->issueDue();

    $body = Livewire::actingAs($user)->test(DonorPortal::class)->html();

    // The recipient's own number, which is the point of the link.
    expect($body)->toContain('wa.me/963912345678')
        ->and($body)->toContain(__('sanabel.gift.send_whatsapp'))
        ->and($body)->toContain($gift->refresh()->token);
});

it('offers nothing for a card the desk has not verified yet', function () {
    [$user] = giverWithGift();

    // Recorded, unverified, so unissued: a link to it would answer 404.
    Livewire::actingAs($user)->test(DonorPortal::class)
        ->assertDontSee(__('sanabel.gift.send_whatsapp'));
});

it('never shows one giver the cards of another', function () {
    [, $donation] = giverWithGift();
    app(DonationService::class)->verify($donation, userWithRole('executive_director')->id);
    app(GiftService::class)->issueDue();

    $stranger = userWithRole('donor');
    Donor::factory()->create(['user_id' => $stranger->id]);

    Livewire::actingAs($stranger)->test(DonorPortal::class)
        ->assertDontSee('أم خالد')
        ->assertDontSee(__('sanabel.gift.my_cards'));
});

it('keeps the recipient number off the card s own public page', function () {
    [, $donation, $gift] = giverWithGift();

    app(DonationService::class)->verify($donation, userWithRole('executive_director')->id);
    app(GiftService::class)->issueDue();

    $body = $this->get(route('gift.card', ['token' => $gift->refresh()->token]))
        ->assertOk()->getContent();

    // The share link is the giver's, on the giver's own page. The card is
    // forwarded to strangers and carries no number of anybody.
    expect($body)->not->toContain('wa.me')
        ->and($body)->not->toContain('912345678');
});
