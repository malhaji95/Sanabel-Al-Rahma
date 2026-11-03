<?php

/*
 | T-242 — the page a donor fills in to give a gift: the association's ten
 | steps on one screen, in their order.
 |
 | The money underneath it is T-241's and is held by its own tests. What is
 | held here is the screen: that each of their steps reaches the row it is
 | meant to, that one transfer can carry several cards, and that nothing is
 | issued before the finance desk has seen the transfer.
 */

use App\Livewire\GiveAgift;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Gift;
use App\Models\GiftCard;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    seedCore();
    Setting::put('platform_wallet', ['number' => '0999111222', 'holder' => 'سنابل الوفاء']);
    $this->design = GiftCard::factory()->create(['name_ar' => 'تصميم العيد']);
});

function giftDonor(): User
{
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id]);

    return $user;
}

function filledCard(array $overrides = []): array
{
    return array_merge([
        'kind' => 'general',
        'campaign_id' => null,
        'gift_card_id' => test()->design->id,
        'giver_name_ar' => 'أبو محمد',
        'recipient_name_ar' => 'أم خالد',
        'recipient_country_code' => '+963',
        'recipient_phone' => '0912345678',
        'amount' => 100000,
        'show_amount' => true,
        'schedule' => false,
        'send_at' => null,
    ], $overrides);
}

it('opens with one card and the association’s own designs on it', function () {
    Livewire::test(GiveAgift::class)
        ->assertSuccessful()
        ->assertSee('تصميم العيد')
        ->assertCount('cards', 1);
});

it('records the transfer and writes the card behind it', function () {
    Livewire::actingAs(giftDonor())
        ->test(GiveAgift::class)
        ->set('cards', [filledCard()])
        ->set('transactionRef', 'GIFT-0001')
        ->call('record')
        ->assertHasNoErrors();

    $donation = Donation::where('transaction_ref', 'GIFT-0001')->first();
    $gift = Gift::first();

    expect($donation)->not->toBeNull()
        // Rule 5 — it arrives unverified, like every other recorded transfer.
        ->and($donation->status)->toBe('pending')
        ->and($donation->amount)->toBe(100000)
        ->and($gift->donation_id)->toBe($donation->id)
        ->and($gift->giver_name_ar)->toBe('أبو محمد')
        ->and($gift->recipient_name_ar)->toBe('أم خالد')
        ->and($gift->recipient_country_code)->toBe('+963')
        // Requirement 9 — nothing is issued at the screen.
        ->and($gift->status)->toBe('pending');
});

it('carries three cards on one transfer, and asks for their total', function () {
    Livewire::actingAs(giftDonor())
        ->test(GiveAgift::class)
        ->set('cards', [
            filledCard(['amount' => 100000, 'recipient_name_ar' => 'الأول']),
            filledCard(['amount' => 50000, 'recipient_name_ar' => 'الثاني']),
            filledCard(['amount' => 25000, 'recipient_name_ar' => 'الثالث']),
        ])
        ->set('transactionRef', 'GIFT-0002')
        ->call('record')
        ->assertHasNoErrors();

    // Requirement 7, and rule 1 untouched: one reference for the operation.
    expect(Donation::count())->toBe(1)
        ->and(Donation::first()->amount)->toBe(175000)
        ->and(Gift::count())->toBe(3);
});

it('adds and removes a card, and never goes down to none', function () {
    Livewire::test(GiveAgift::class)
        ->call('addCard')
        ->assertCount('cards', 2)
        ->call('removeCard', 1)
        ->assertCount('cards', 1)
        // A gift with no card is not a gift.
        ->call('removeCard', 0)
        ->assertCount('cards', 1);
});

it('earmarks the card the donor aimed at a project', function () {
    $project = Campaign::factory()->create([
        'is_published' => true,
        'goal_amount' => 1000000,
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى باب الخير العام.',
    ]);

    Livewire::actingAs(giftDonor())
        ->test(GiveAgift::class)
        ->set('cards', [filledCard(['kind' => 'project', 'campaign_id' => $project->id])])
        ->set('transactionRef', 'GIFT-0003')
        ->call('record')
        ->assertHasNoErrors();

    expect(Gift::first()->campaign_id)->toBe($project->id);
});

it('drops a project the donor picked and then changed their mind about', function () {
    $project = Campaign::factory()->create([
        'is_published' => true,
        'goal_amount' => 1000000,
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى باب الخير العام.',
    ]);

    Livewire::actingAs(giftDonor())
        ->test(GiveAgift::class)
        // The project is still in the row; the kind says general.
        ->set('cards', [filledCard(['kind' => 'general', 'campaign_id' => $project->id])])
        ->set('transactionRef', 'GIFT-0004')
        ->call('record')
        ->assertHasNoErrors();

    expect(Gift::first()->campaign_id)->toBeNull();
});

it('keeps the hour on a scheduled card, and none on a direct one', function () {
    $hour = now()->addDays(3)->startOfHour();

    Livewire::actingAs(giftDonor())
        ->test(GiveAgift::class)
        ->set('cards', [
            filledCard(['schedule' => true, 'send_at' => $hour->toDateTimeString(), 'recipient_name_ar' => 'المجدولة']),
            filledCard(['schedule' => false, 'send_at' => $hour->toDateTimeString(), 'recipient_name_ar' => 'المباشرة']),
        ])
        ->set('transactionRef', 'GIFT-0005')
        ->call('record')
        ->assertHasNoErrors();

    expect(Gift::where('recipient_name_ar', 'المجدولة')->first()->send_at->toDateTimeString())
        ->toBe($hour->toDateTimeString())
        // The box was unticked, so the hour left in the row is not an
        // instruction: requirement 8 is a choice, not a leftover.
        ->and(Gift::where('recipient_name_ar', 'المباشرة')->first()->send_at)->toBeNull();
});

it('carries the donor’s choice to hide the amount', function () {
    Livewire::actingAs(giftDonor())
        ->test(GiveAgift::class)
        ->set('cards', [filledCard(['show_amount' => false])])
        ->set('transactionRef', 'GIFT-0006')
        ->call('record')
        ->assertHasNoErrors();

    expect(Gift::first()->show_amount)->toBeFalse();
});

it('asks for what it needs before it records anything', function () {
    Livewire::actingAs(giftDonor())
        ->test(GiveAgift::class)
        ->set('cards', [filledCard(['recipient_name_ar' => '', 'amount' => null])])
        ->set('transactionRef', '')
        ->call('record')
        ->assertHasErrors(['cards.0.recipient_name_ar', 'cards.0.amount', 'transactionRef']);

    expect(Donation::count())->toBe(0)
        ->and(Gift::count())->toBe(0);
});

it('lets a guest give a gift without signing in', function () {
    Livewire::test(GiveAgift::class)
        ->set('cards', [filledCard()])
        ->set('name', 'متبرع بلا حساب')
        ->set('phone', '0944123456')
        ->set('transactionRef', 'GIFT-0007')
        ->call('record')
        ->assertHasNoErrors();

    // T-108 — a gift given without signing in still belongs to somebody.
    $donor = Donor::where('name_ar', 'متبرع بلا حساب')->first();

    expect($donor)->not->toBeNull()
        ->and($donor->user_id)->toBeNull()
        ->and(Gift::count())->toBe(1);
});

it('says the reference is taken rather than failing, and writes nothing', function () {
    Donation::factory()->create(['transaction_ref' => 'GIFT-TAKEN']);

    Livewire::actingAs(giftDonor())
        ->test(GiveAgift::class)
        ->set('cards', [filledCard()])
        ->set('transactionRef', 'GIFT-TAKEN')
        ->call('record')
        ->assertHasNoErrors()
        ->assertSet('thanks', false)
        // Rule 1 — refused with a message asking for review, and the
        // reference named in it so the donor knows which one (T-135).
        ->assertSet('error', fn ($error) => str_contains($error, 'رقم الحوالة مستخدم من قبل')
            && str_contains($error, 'GIFT-TAKEN'));

    expect(Gift::count())->toBe(0);
});

it('leaves no orphan transfer in the queue when the cards are refused', function () {
    $withdrawn = GiftCard::factory()->create(['is_published' => false]);

    Livewire::actingAs(giftDonor())
        ->test(GiveAgift::class)
        ->set('cards', [filledCard(['gift_card_id' => $withdrawn->id])])
        ->set('transactionRef', 'GIFT-0008')
        ->call('record')
        ->assertSet('error', __('sanabel.gift.card_not_offered'));

    // The cards are the point of the page: a transfer that could not carry
    // them is not left sitting in the verifier's queue pretending to be an
    // ordinary donation.
    expect(Gift::count())->toBe(0)
        ->and(Donation::whereNull('deleted_at')->count())->toBe(0);
});

it('says so plainly while the association has uploaded no design', function () {
    GiftCard::query()->delete();

    Livewire::test(GiveAgift::class)
        ->assertSuccessful()
        ->assertSee(__('sanabel.gift.no_designs'))
        ->assertDontSee(__('sanabel.gift.add_card'));
});

it('offers the ready amounts the association set, and a box for any other', function () {
    Setting::put('gift_amounts', [7000, 9000]);

    Livewire::test(GiveAgift::class)
        ->assertSee(number_format(7000))
        ->assertSee(number_format(9000))
        ->assertSee(__('sanabel.gift.custom_amount'));
});
