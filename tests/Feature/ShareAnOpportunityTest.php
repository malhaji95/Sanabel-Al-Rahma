<?php

use App\Models\Donor;
use App\Services\BasketService;
use App\Services\CoverageService;
use App\Services\DonationService;
use App\Services\GeneralMoneyService;

/*
 | An opportunity can be sent on: it has an address of its own, and the card a
 | messaging app draws beside the link carries the platform's name, not a
 | family's.
 */

beforeEach(function () {
    seedCore();
});

it('gives a published family file an address of its own', function () {
    $case = publishedCase(regionWithRates());

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee($case->file_number);
});

it('puts nothing identifying in the card a messaging app draws', function () {
    $region = regionWithRates();
    $case = publishedCase($region);
    $case->update(['first_name' => 'محمود', 'family_name' => 'العلي']);

    $body = $this->get(route('opportunity', $case->file_number))->assertOk()->getContent();

    // The preview block exists, and is the platform's own.
    expect($body)->toContain('og:image')
        ->toContain('brand/logo-full.png')
        // Neither the name nor any contact detail travels with the link.
        ->not->toContain('محمود')
        ->not->toContain('العلي');
});

it('answers a link to a file that is already covered without taking a donation', function () {
    /*
     | T-120 — this used to fund the current month only, and read as covered
     | because the current month was the only one open. It is not, for the
     | last seven days of every month: the association asked on 13 October
     | that the next month open before this one ends so a family is not left
     | with a gap, so from the 25th of a 31-day month a donor may fund either.
     |
     | The test passed for twenty-four days a month and failed for seven, and
     | it went off on 25 October. What it means to say is that a file with
     | nothing left to fund says so — so it funds every month that is open,
     | whichever day of the month the suite is run on.
     */
    $region = regionWithRates();
    $case = publishedCase($region);
    $coverage = app(CoverageService::class);

    $admin = userWithRole('admin');
    $need = $coverage->needAmount($case);

    foreach ($coverage->openMonths() as $index => $month) {
        $donation = app(DonationService::class)->record([
            'donor_id' => Donor::factory()->create()->id,
            'amount' => $need,
            'transaction_ref' => 'REF-COVERED-'.$index,
            'designation' => 'general',
        ]);

        app(DonationService::class)->verify($donation, $admin->id);
        app(GeneralMoneyService::class)->allocate($donation, $case, $need, $month, $admin);
    }

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.share.closed_title'))
        ->assertSee(__('sanabel.share.browse_others'));
});

it('answers a link to a file that was never published the same way', function () {
    $this->get(route('opportunity', 'NO-SUCH-FILE'))
        ->assertOk()
        ->assertSee(__('sanabel.share.closed_title'));
});

it('offers the donor the nudge and thanks them in the association voice', function () {
    $region = regionWithRates();
    $case = publishedCase($region);
    $donor = Donor::factory()->create(['user_id' => userWithRole('donor')->id]);

    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($donor);
    $baskets->addItem($basket, $case, 10_000);
    $baskets->reserve($basket);

    $this->actingAs($donor->user);

    \Livewire\Livewire::test(\App\Livewire\DonorBasket::class)
        ->assertSee(__('sanabel.public.nudge_title'))
        ->set('transactionRef', 'REF-THANKS')
        ->call('recordTransfer')
        ->assertSee(__('sanabel.public.thanks_blessing'));
});

/*
 | The association's switch over its own links, and the zakat classification
 | (decisions of 5 October).
 */

it('drops the share button on a file the association has closed to sharing', function () {
    $case = publishedCase(regionWithRates());

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.share.action'));

    $case->update(['is_shareable' => false]);

    // Still published, still fundable — only the link stops being handed around.
    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.public.donate_now'))
        ->assertDontSee(__('sanabel.share.action'));

    $this->get(route('cases.browse'))->assertOk()->assertDontSee(__('sanabel.share.action'));
});

it('tells a donor that zakat may be paid, and never which category', function () {
    $case = publishedCase(regionWithRates());

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertDontSee(__('sanabel.zakat.accepts'));

    $case->update(['zakat_category' => 'debtors']);

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.zakat.accepts'))
        // The category describes the household, so it stays behind rule 2.
        ->assertDontSee(__('sanabel.zakat.categories.debtors'));
});

it('keeps the category out of the masked payload entirely', function () {
    $case = publishedCase(regionWithRates());
    $case->update(['zakat_category' => 'debtors']);

    $payload = (new App\Http\Resources\MaskedCaseResource($case))->toArray(request());

    expect($payload)->toHaveKey('accepts_zakat')
        ->and($payload['accepts_zakat'])->toBeTrue()
        ->and(json_encode($payload))->not->toContain('debtors')
        ->and(array_diff(array_keys($payload), App\Http\Resources\MaskedCaseResource::ALLOWED_KEYS))->toBeEmpty();
});
