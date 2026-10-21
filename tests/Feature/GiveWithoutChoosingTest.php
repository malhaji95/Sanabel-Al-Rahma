<?php

use App\Livewire\GeneralDonation;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\ContentCard;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Page;
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
    Setting::put('platform_wallet', ['number' => '0999111222', 'holder' => 'سنابل الوفاء']);

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
    Setting::put('platform_wallet', ['number' => '0999111222', 'holder' => 'سنابل الوفاء']);

    $case = publishedCase($this->region, attributes: ['wallet_encrypted' => '0912345678']);

    Livewire::actingAs(donorWithAccount())
        ->test(GeneralDonation::class)
        ->assertSee('0999111222')
        ->assertDontSee($case->wallet_encrypted);
})->group('AUTH-03');

it('opens the screen to somebody who has never signed in', function () {
    /*
     | T-108 — «تبرع سريع … ما يحتاج يدخل ايميل وكلمة مرور، مباشرة ندخل على
     | صفحة التبرع», 17 October. It used to sit behind auth.
     |
     | What an account was never doing: the transfer happens at the donor's
     | own bank, and the reference of that transfer is what identifies it.
     | Nothing counts until the finance desk matches it against the
     | statement, so a signed-out record is a line in a queue exactly like a
     | signed-in one.
     */
    $this->get(route('donate.general'))
        ->assertOk()
        ->assertSee(__('sanabel.donation.donor'))
        ->assertSee(__('sanabel.public.general_donation'));
});

it('records a gift from a guest, and keeps a donor record to thank', function () {
    Setting::put('platform_wallet', ['number' => '0999111222', 'holder' => 'سنابل الوفاء']);

    Livewire::test(GeneralDonation::class)
        ->set('name', 'أبو محمد')
        ->set('phone', '0944111222')
        ->set('amount', 25_000)
        ->set('transactionRef', 'GUEST-0001')
        ->call('record')
        ->assertHasNoErrors();

    $donation = Donation::where('transaction_ref', 'GUEST-0001')->first();

    expect($donation)->not->toBeNull()
        ->and($donation->designation)->toBe('general')
        ->and($donation->status)->toBe('pending')
        ->and($donation->donor->name_ar)->toBe('أبو محمد')
        // A donor record with nobody to log in as.
        ->and($donation->donor->user_id)->toBeNull();
})->group('AUTH-03');

it('refuses a guest gift with no name on it', function () {
    Livewire::test(GeneralDonation::class)
        ->set('amount', 25_000)
        ->set('transactionRef', 'GUEST-0002')
        ->call('record')
        ->assertHasErrors(['name']);

    expect(Donation::count())->toBe(0);
});

it('treats the same number giving twice as the same donor', function () {
    Setting::put('platform_wallet', ['number' => '0999111222', 'holder' => 'سنابل الوفاء']);

    foreach (['GUEST-A', 'GUEST-B'] as $ref) {
        Livewire::test(GeneralDonation::class)
            ->set('name', 'أبو محمد')
            ->set('phone', '0944111222')
            ->set('amount', 10_000)
            ->set('transactionRef', $ref)
            ->call('record')
            ->assertHasNoErrors();
    }

    // The number is encrypted on the row, so the match is made on the keyed
    // hash beside it — the same answer as a family's national ID.
    expect(Donor::whereNull('user_id')->count())->toBe(1)
        ->and(Donation::count())->toBe(2);
});

it('does not ask a signed-in donor for a name it already has', function () {
    Livewire::actingAs(donorWithAccount())
        ->test(GeneralDonation::class)
        ->assertDontSee(__('sanabel.donation.phone_help'));
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

    /*
     | Not drawn on its own destination: a button that reloads the page the
     | visitor is already reading is noise.
     |
     | Measured on the floating wrapper rather than on its words, because
     | T-108 renamed the button to «تبرع سريع» — which is also the heading of
     | the page it leads to, so counting the words would count the heading.
     */
    $floating = 'fixed bottom-5 end-5';

    expect($home)->toContain($floating);

    $donate = $this->actingAs(donorWithAccount())->get(route('donate.general'))->assertOk()->getContent();

    expect($donate)->not->toContain($floating);
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

/*
 | T-104 — note 6 of 15 October: «قبل النسخة النهائية بدنا تنظيف كامل لأي
 | شيء تجريبي». A promise to remember is worth nothing on the day, so the
 | question is asked of the database instead.
 */

it('names every piece of demonstration content still in the system', function () {
    Page::create([
        'slug' => 'about',
        'title_ar' => 'من نحن',
        'body_ar' => 'هذه الصفحة بانتظار نصّها من الجمعية.',
        'is_published' => true,
    ]);

    ContentCard::create([
        'block' => 'tile',
        'title_ar' => 'صدقة جارية',
        'icon' => 'mosque',
        'is_published' => true,
    ]);

    publishedCase($this->region, attributes: ['file_number' => 'DEMO-9001']);

    $this->artisan('launch:check')
        ->expectsOutputToContain('DEMO-')
        ->expectsOutputToContain('من نحن')
        ->expectsOutputToContain('صدقة جارية')
        ->assertFailed();
});

it('passes once there is nothing demonstration left', function () {
    config()->set('sanabel.demo_seed', false);

    $this->artisan('launch:check')
        ->expectsOutputToContain(__('sanabel.launch.clean'))
        ->assertSuccessful();
});

it('only ever looks, so it can be run against the live site', function () {
    publishedCase($this->region, attributes: ['file_number' => 'DEMO-9002']);

    $before = Beneficiary::withTrashed()->count();

    $this->artisan('launch:check');

    // Rule 3 admits no deletion, and this command is the one most likely to
    // be reached for on a day when somebody wants the demo gone.
    expect(Beneficiary::withTrashed()->count())->toBe($before);
});
