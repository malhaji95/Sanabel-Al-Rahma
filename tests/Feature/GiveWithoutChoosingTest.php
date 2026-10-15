<?php

use App\Livewire\GeneralDonation;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

/*
 | T-103 — the association's notes of 15 October on the front page and on
 | what a donation screen says it is doing.
 |
 | Three of them are one question asked three ways: a donor must be able to
 | tell what a button will do with their money before they press it. The
 | offer at the top of the page promised an act and delivered a list to
 | choose from; a campaign card showed a project at 40% beside «اكتملت
 | التغطية» for the month and one «تبرع الآن» under both; and the project's
 | own figures were «24,000 / SYP 60,000», which in a page running right to
 | left names neither number.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

function donorWithAccount(): User
{
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id]);

    return $user;
}

it('records a donation that names no family, without asking for a file number', function () {
    Setting::put('platform_wallet', ['number' => '0999111222', 'holder' => 'سنابل الرحمة']);

    Livewire::actingAs(donorWithAccount())
        ->test(GeneralDonation::class)
        ->assertSuccessful()
        ->set('amount', 50_000)
        ->set('transactionRef', 'GEN-0001')
        ->call('record')
        ->assertHasNoErrors();

    $donation = Donation::where('transaction_ref', 'GEN-0001')->first();

    expect($donation)->not->toBeNull()
        // The domain's second kind of donation, which until now had no door.
        ->and($donation->designation)->toBe('general')
        ->and($donation->basket_id)->toBeNull()
        ->and($donation->amount)->toBe(50_000)
        // Rule 5 — it arrives unverified, like every other recorded transfer.
        ->and($donation->status)->toBe('pending');
})->group('AUTH-03');

it('refuses a transfer with no amount or no reference, rather than storing half of one', function () {
    Livewire::actingAs(donorWithAccount())
        ->test(GeneralDonation::class)
        ->call('record')
        ->assertHasErrors(['amount', 'transactionRef']);

    expect(Donation::count())->toBe(0);
});

it('shows the association wallet and never a household account', function () {
    Setting::put('platform_wallet', ['number' => '0999111222', 'holder' => 'سنابل الرحمة']);

    $case = publishedCase($this->region, attributes: ['wallet_encrypted' => '0912345678']);

    Livewire::actingAs(donorWithAccount())
        ->test(GeneralDonation::class)
        ->assertSee('0999111222')
        ->assertDontSee($case->wallet_encrypted);
})->group('AUTH-03');

it('keeps the screen behind the door, like every other way of giving', function () {
    $this->get(route('donate.general'))->assertRedirect(route('login'));
});

it('says plainly that the money reaches the association and not the family', function () {
    Livewire::actingAs(donorWithAccount())
        ->test(GeneralDonation::class)
        // Note 7 of 15 October: the old name «الدفع المباشر» read as a
        // transfer going straight to the household, outside the books.
        ->assertSee(__('sanabel.public.general_donation_body'));
});

it('names the three figures on a project instead of printing a fraction', function () {
    $campaign = Campaign::factory()->create([
        'goal_amount' => 60_000,
        'is_published' => true,
        'beneficiary_id' => null,
        'surplus_policy_text_ar' => 'يوجه الفائض لأسرة أخرى ضمن نفس المنطقة.',
    ]);

    $body = $this->get(route('campaigns.public'))->assertOk()->getContent();

    expect($body)->toContain(__('sanabel.public.goal'))
        ->and($body)->toContain(__('sanabel.public.collected'))
        ->and($body)->toContain(__('sanabel.public.remaining'))
        // The project is fenced off from the family's month beside it.
        ->and($body)->toContain(__('sanabel.public.project_block'))
        ->and($campaign->goal_amount)->toBe(60_000);
});

it('offers the donate button once the page has been scrolled, and not on the page it leads to', function () {
    $home = $this->get(route('home'))->assertOk()->getContent();

    expect($home)->toContain(route('donate.general'))
        ->and($home)->toContain(__('sanabel.public.donate_sticky'));

    // Not drawn on its own destination: a button that reloads the page the
    // visitor is already reading is noise.
    $donate = $this->actingAs(donorWithAccount())->get(route('donate.general'))->assertOk()->getContent();

    expect(substr_count($donate, __('sanabel.public.donate_sticky')))->toBe(0);
});

it('puts the whole-list link under the cases rather than above them', function () {
    publishedCase($this->region);

    $home = $this->get(route('home'))->assertOk()->getContent();

    $heading = strpos($home, __('sanabel.public.needs_you_today'));
    $allCases = strpos($home, __('sanabel.public.all_cases'));

    expect($heading)->not->toBeFalse()
        ->and($allCases)->not->toBeFalse()
        // Asked for on 15 October: a visitor asks for more after reading what
        // is in front of them, not before.
        ->and($allCases)->toBeGreaterThan($heading);
});
