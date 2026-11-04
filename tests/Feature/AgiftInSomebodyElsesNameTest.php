<?php

/*
 | T-241 — «باب الهدايا», 3 November, step for step off the association's own
 | reference model.
 |
 | What is held here is their ten requirements, minus the one that cannot be
 | built without an account they do not have: WhatsApp and SMS delivery. The
 | card is issued and addressed; who carries it is the donor, for now.
 */

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Gift;
use App\Models\GiftCard;
use App\Models\Setting;
use App\Models\User;
use App\Services\DonationService;
use App\Services\GiftService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedCore();
    $this->card = GiftCard::factory()->create();
    $this->donor = Donor::factory()->create();
    $this->verifier = userWithRole('finance');
});

function giftRow(array $overrides = []): array
{
    return array_merge([
        'gift_card_id' => test()->card->id,
        'campaign_id' => null,
        'giver_name_ar' => 'أبو محمد',
        'recipient_name_ar' => 'أم خالد',
        'recipient_country_code' => '+963',
        'recipient_phone' => '0912345678',
        'show_amount' => true,
        'amount' => 100000,
        'send_at' => null,
    ], $overrides);
}

function pendingGiftDonation(int $amount = 100000): Donation
{
    return Donation::factory()->create([
        'donor_id' => test()->donor->id,
        'amount' => $amount,
        'status' => 'pending',
        'route' => 'platform',
    ]);
}

it('writes one card per gift against one transfer', function () {
    $donation = pendingGiftDonation(300000);

    $gifts = app(GiftService::class)->attach($donation, [
        giftRow(['amount' => 100000, 'recipient_name_ar' => 'الأول']),
        giftRow(['amount' => 100000, 'recipient_name_ar' => 'الثاني']),
        giftRow(['amount' => 100000, 'recipient_name_ar' => 'الثالث']),
    ]);

    // Requirement 7 — more than one card inside the same operation, and
    // still one transfer with one reference (rule 1).
    expect($gifts)->toHaveCount(3)
        ->and($donation->gifts()->count())->toBe(3)
        ->and(Donation::count())->toBe(1);
});

it('refuses cards that do not add up to the transfer', function () {
    $donation = pendingGiftDonation(100000);

    expect(fn () => app(GiftService::class)->attach($donation, [
        giftRow(['amount' => 60000]),
        giftRow(['amount' => 60000]),
    ]))->toThrow(RuntimeException::class, __('sanabel.gift.cards_do_not_add_up'));

    expect(Gift::count())->toBe(0);
});

it('takes only a design the association is offering', function () {
    $withdrawn = GiftCard::factory()->create(['is_published' => false]);
    $donation = pendingGiftDonation();

    expect(fn () => app(GiftService::class)->attach($donation, [
        giftRow(['gift_card_id' => $withdrawn->id]),
    ]))->toThrow(RuntimeException::class, __('sanabel.gift.card_not_offered'));
});

it('keeps the recipient’s number out of the database in clear', function () {
    $donation = pendingGiftDonation();

    $gift = app(GiftService::class)->attach($donation, [giftRow()])->first();

    $raw = DB::table('gifts')
        ->where('id', $gift->id)->value('recipient_phone_encrypted');

    // Rule 10 — stored encrypted, read back by the model, and never the
    // digits themselves on the row.
    expect($raw)->not->toContain('0912345678')
        ->and($gift->refresh()->recipient_phone_encrypted)->toBe('0912345678');
});

it('keeps the recipient’s number out of the audit log too', function () {
    $donation = pendingGiftDonation();

    $gift = app(GiftService::class)->attach($donation, [giftRow()])->first();

    $entry = AuditLog::where('entity_type', Gift::class)
        ->where('entity_id', $gift->id)->first();

    expect($entry)->not->toBeNull()
        ->and(json_encode($entry->after_json))->not->toContain('0912345678');
});

it('issues no card while the transfer is only a claim', function () {
    $donation = pendingGiftDonation();
    app(GiftService::class)->attach($donation, [giftRow()]);

    // Requirement 9 — after the donation is verified, never before.
    expect(app(GiftService::class)->issueDue())->toBe(0)
        ->and(Gift::where('status', 'pending')->count())->toBe(1);
});

it('issues the card the moment the transfer is verified', function () {
    $donation = pendingGiftDonation();
    $gift = app(GiftService::class)->attach($donation, [giftRow()])->first();

    app(DonationService::class)->verify($donation, $this->verifier->id);

    expect($gift->refresh()->status)->toBe('issued')
        ->and($gift->issued_at)->not->toBeNull();
});

it('holds a scheduled card until its hour, then issues it', function () {
    $donation = pendingGiftDonation();
    $gift = app(GiftService::class)->attach($donation, [
        giftRow(['send_at' => now()->addDays(2)->toDateTimeString()]),
    ])->first();

    app(DonationService::class)->verify($donation, $this->verifier->id);

    // Requirement 8 — verified, but not yet its hour.
    expect($gift->refresh()->status)->toBe('pending');

    $this->travelTo(now()->addDays(3));

    $this->artisan('sanabel:issue-gifts')->assertSuccessful();

    expect($gift->refresh()->status)->toBe('issued');
});

it('treats an hour already past as «send it now»', function () {
    $donation = pendingGiftDonation();

    $gift = app(GiftService::class)->attach($donation, [
        giftRow(['send_at' => now()->subHour()->toDateTimeString()]),
    ])->first();

    expect($gift->send_at)->toBeNull();
});

it('earmarks a gift aimed at a project to that project', function () {
    $campaign = Campaign::factory()->create([
        'is_published' => true,
        'goal_amount' => 1000000,
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى باب الخير العام.',
    ]);
    $donation = pendingGiftDonation(100000);

    app(GiftService::class)->attach($donation, [giftRow(['campaign_id' => $campaign->id])]);
    app(DonationService::class)->verify($donation, $this->verifier->id);

    // Requirement 10 — the money keeps its designation, through the
    // allocation table the system already had.
    $allocation = $donation->refresh()->allocations()->first();

    expect($allocation)->not->toBeNull()
        ->and($allocation->campaign_id)->toBe($campaign->id)
        ->and($allocation->beneficiary_id)->toBeNull()
        ->and($allocation->amount)->toBe(100000);
});

it('allocates nothing for a general gift', function () {
    $donation = pendingGiftDonation();

    app(GiftService::class)->attach($donation, [giftRow()]);
    app(DonationService::class)->verify($donation, $this->verifier->id);

    expect($donation->refresh()->allocations()->count())->toBe(0);
});

it('refuses a project the association is not offering', function () {
    $hidden = Campaign::factory()->create(['is_published' => false]);
    $donation = pendingGiftDonation();

    expect(fn () => app(GiftService::class)->attach($donation, [
        giftRow(['campaign_id' => $hidden->id]),
    ]))->toThrow(RuntimeException::class, __('sanabel.gift.project_not_offered'));
});

it('shows the card at its own private address, and only once issued', function () {
    $donation = pendingGiftDonation();
    $gift = app(GiftService::class)->attach($donation, [giftRow()])->first();

    $this->get(route('gift.card', ['token' => $gift->token]))->assertNotFound();

    app(DonationService::class)->verify($donation, $this->verifier->id);

    $this->get(route('gift.card', ['token' => $gift->token]))
        ->assertOk()
        ->assertSee('أبو محمد')
        ->assertSee('أم خالد')
        // Requirement 6 — the amount, because this donor chose to show it.
        ->assertSee(number_format(100000))
        // Rule 10 — never the recipient's number, on a page open to anybody
        // holding the link.
        ->assertDontSee('0912345678');
});

it('hides the amount on the card when the donor said to hide it', function () {
    $donation = pendingGiftDonation();
    $gift = app(GiftService::class)->attach($donation, [giftRow(['show_amount' => false])])->first();

    app(DonationService::class)->verify($donation, $this->verifier->id);

    $this->get(route('gift.card', ['token' => $gift->token]))
        ->assertOk()
        ->assertSee('أم خالد')
        ->assertDontSee(number_format(100000));
});

it('gives nothing away at a token nobody issued', function () {
    $this->get(route('gift.card', ['token' => 'cHJldGVuZHRva2Vu']))->assertNotFound();
});

it('tells the donor the card is ready, because the donor carries it', function () {
    $user = User::factory()->create();
    $this->donor->forceFill(['user_id' => $user->id])->save();

    $donation = pendingGiftDonation();
    $gift = app(GiftService::class)->attach($donation, [giftRow()])->first();

    app(DonationService::class)->verify($donation, $this->verifier->id);

    $notification = AppNotification::where('recipient_id', $user->id)
        ->where('template_key', 'gift_card_ready')->first();

    // Rule 10 — the card's token, never the recipient's name or number.
    expect($notification)->not->toBeNull()
        ->and($notification->payload_json)->toBe(['ref' => $gift->token]);
});

it('builds the donor’s own WhatsApp message, with the country key on the number', function () {
    $donation = pendingGiftDonation();
    $gift = app(GiftService::class)->attach($donation, [giftRow()])->first();

    $link = app(GiftService::class)->shareLink($gift->refresh());

    // «رقم الهاتف مع مفتاح الدولة» — the key, then the number with its
    // leading zero dropped, which is how a number is dialled from abroad.
    expect($link)->toStartWith('https://wa.me/963912345678?text=')
        ->and(urldecode($link))->toContain(route('gift.card', ['token' => $gift->token]));
});

it('reads the ready amounts off the settings, not off the code', function () {
    expect(GiftService::amounts())->toBe(config('sanabel.setting_defaults.gift_amounts'));

    Setting::put('gift_amounts', [1000, 2000]);

    expect(GiftService::amounts())->toBe([1000, 2000]);
});

it('refuses a gift of nothing at the database itself', function () {
    $donation = pendingGiftDonation();

    expect(fn () => Gift::create([
        'donation_id' => $donation->id,
        'gift_card_id' => $this->card->id,
        'giver_name_ar' => 'أبو محمد',
        'recipient_name_ar' => 'أم خالد',
        'recipient_country_code' => '+963',
        'recipient_phone_encrypted' => '0912345678',
        'amount' => 0,
        'currency' => 'SYP',
    ]))->toThrow(QueryException::class);
});

/*
 | T-244 — the three halves of their question about scheduling, answered
 | where the answers actually live.
 */
it('keeps the card unreachable until its hour, and reachable after it', function () {
    $donation = pendingGiftDonation();
    $gift = app(GiftService::class)->attach($donation, [
        giftRow(['send_at' => now()->addDays(2)->toDateTimeString()]),
    ])->first();

    app(DonationService::class)->verify($donation, $this->verifier->id);

    // Verified, but not yet its hour: the address gives nothing away.
    $this->get(route('gift.card', ['token' => $gift->token]))->assertNotFound();

    $this->travelTo(now()->addDays(3));
    $this->artisan('sanabel:issue-gifts')->assertSuccessful();

    $this->get(route('gift.card', ['token' => $gift->token]))->assertOk();
});

it('tells the donor at the hour, not when the transfer was verified', function () {
    $user = User::factory()->create();
    $this->donor->forceFill(['user_id' => $user->id])->save();

    $donation = pendingGiftDonation();
    app(GiftService::class)->attach($donation, [
        giftRow(['send_at' => now()->addDays(2)->toDateTimeString()]),
    ]);

    app(DonationService::class)->verify($donation, $this->verifier->id);

    $told = fn () => AppNotification::where('recipient_id', $user->id)
        ->where('template_key', 'gift_card_ready')->exists();

    // Nothing yet: the donor is reminded when the card goes out, which is
    // the whole point of asking for an hour.
    expect($told())->toBeFalse();

    $this->travelTo(now()->addDays(3));
    $this->artisan('sanabel:issue-gifts')->assertSuccessful();

    expect($told())->toBeTrue();
});
