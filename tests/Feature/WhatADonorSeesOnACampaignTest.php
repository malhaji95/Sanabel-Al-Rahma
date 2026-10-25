<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Media;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 | T-123 — the association's notes of 26 October on what a donor is shown.
 |
 | A campaign with no picture, a progress bar that said nothing about how far
 | along it was, a donate button a visitor never saw, and a transfer screen
 | that asked for a reference and offered no way to send the slip.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** A verified gift against a campaign, which is the only thing that moves its bar. */
function fundCampaign(Campaign $campaign, int $amount): void
{
    $donor = Donor::factory()->create();
    $basket = app(App\Services\BasketService::class)->openFor($donor);

    app(App\Services\BasketService::class)->addCampaign($basket, $campaign, $amount);
    app(App\Services\BasketService::class)->reserve($basket);

    $donation = app(App\Services\DonationService::class)->record([
        'donor_id' => $donor->id,
        'basket_id' => $basket->id,
        'amount' => $amount,
        'transaction_ref' => 'CAMP-BAND-'.$campaign->id.'-'.$amount,
    ]);

    app(App\Services\DonationService::class)->verify($donation, userWithRole('finance')->id);
}

function publishedCampaign(array $attributes = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'beneficiary_id' => null,
        'title_ar' => 'ترميم سقف',
        'goal_amount' => 100_000,
        'status' => 'active',
        'is_published' => true,
        'surplus_policy_text_ar' => 'يوجه الفائض لحملة مماثلة.',
    ], $attributes));
}

it('shows the picture the association uploaded for a campaign', function () {
    $campaign = publishedCampaign(['image' => 'campaigns/roof.jpg']);

    expect($campaign->imageUrl())->toContain('campaigns/roof.jpg');

    $body = $this->get(route('campaigns.public'))->assertOk()->getContent();

    expect($body)->toContain('campaigns/roof.jpg');

    // And a campaign without one is drawn without an empty frame.
    publishedCampaign(['title_ar' => 'حملة بلا صورة'])->refresh();

    expect(publishedCampaign()->imageUrl())->toBeNull();
});

it('colours the bar by how far along it is, in the four bands they named', function () {
    /*
     | «تحت 40 بالمية اللون الاحمر، ومن 41 الى 80 باللون البرتقالي، ومن 81 الى
     | 99 اخضر فاتح، اما المكتملة تكون باللون الاخضر الغامق».
     */
    // Attached to a family, because a basket holds money against a file and
    // that is the only way a campaign's bar ever moves.
    $campaign = publishedCampaign(['beneficiary_id' => publishedCase($this->region)->id]);

    expect($campaign->progressBand(0))->toBe('low')
        ->and($campaign->progressBand(40))->toBe('low')
        ->and($campaign->progressBand(41))->toBe('mid')
        ->and($campaign->progressBand(80))->toBe('mid')
        ->and($campaign->progressBand(81))->toBe('high')
        ->and($campaign->progressBand(99))->toBe('high')
        ->and($campaign->progressBand(100))->toBe('complete')
        // Over-collection is still complete, not a fifth state.
        ->and($campaign->progressBand(140))->toBe('complete');

    // On the page itself: a campaign half funded wears the middle band.
    fundCampaign($campaign, 50_000);

    $body = $this->get(route('campaigns.public'))->assertOk()->getContent();

    expect($body)->toContain('meter-fill meter-fill-mid');

    // And each band has a colour of its own on both schemes.
    $css = file_get_contents(resource_path('css/app.css'));

    foreach (['low', 'mid', 'high', 'complete'] as $band) {
        expect($css)->toContain(".meter-fill-{$band}")
            ->and($css)->toContain("--meter-{$band}:");
    }
});

it('offers a visitor a way to give, not only a signed-in donor', function () {
    // «هل المفروض تظهر كلمة التبرع لدى كل حملة؟» — it was shown to a donor
    // with an account and to nobody else, so a visitor read a goal and a bar
    // and was offered nothing.
    publishedCampaign();

    $body = $this->get(route('campaigns.public'))->assertOk()->getContent();

    expect($body)->toContain(__('sanabel.public.donate_now'))
        ->and($body)->toContain(route('donate.general'))
        // And it says plainly what an account is for.
        ->and($body)->toContain(__('sanabel.public.pledge_needs_account'));
});

it('says where general money goes in the association own words', function () {
    expect(__('sanabel.designation.general_help'))->toBe('عطاؤك يُوجَّه إلى حيث تكون الحاجة أولى.');
});

it('shows the wallet as a code to photograph as well as a number to read', function () {
    Setting::put('platform_wallet', ['number' => '0933123456', 'holder' => 'جمعية سنابل الوفاء']);

    $body = $this->get(route('donate.general'))->assertOk()->getContent();

    expect($body)->toContain('0933123456')
        ->and($body)->toContain('<svg')
        ->and($body)->toContain(__('sanabel.transfer.qr_help'));

    // The code carries the wallet number and nothing else — no name, no
    // amount, and nothing about the donor. Drawn at the size the screen
    // draws it, so this is the same markup and not merely a similar one.
    $qr = app(App\Services\QrCode::class)->svg('0933123456', moduleSize: 4);

    expect($body)->toContain($qr);
});

it('takes the slip for a transfer, from a guest as readily as from an account', function () {
    Setting::put('platform_wallet', ['number' => '0933123456', 'holder' => 'جمعية سنابل الوفاء']);

    Livewire::test(App\Livewire\GeneralDonation::class)
        ->set('name', 'أبو محمد')
        ->set('amount', 50_000)
        ->set('transactionRef', 'REF-WITH-SLIP')
        ->set('receipt', UploadedFile::fake()->image('slip.jpg'))
        ->call('record')
        ->assertHasNoErrors();

    $donation = Donation::where('transaction_ref', 'REF-WITH-SLIP')->firstOrFail();

    expect($donation->receipt_media_id)->not->toBeNull();

    $media = Media::find($donation->receipt_media_id);

    expect($media->kind)->toBe('receipt')
        // Nobody signed in, so nobody is named as the uploader — and the row
        // still says what was uploaded and against which donation.
        ->and($media->uploaded_by)->toBeNull()
        ->and($media->owner_id)->toBe($donation->id);
});

it('refuses a file that is not an image or a PDF', function () {
    Livewire::test(App\Livewire\GeneralDonation::class)
        ->set('name', 'أبو محمد')
        ->set('amount', 50_000)
        ->set('transactionRef', 'REF-BAD-FILE')
        ->set('receipt', UploadedFile::fake()->create('ledger.exe', 12))
        ->call('record')
        ->assertHasErrors(['receipt']);

    expect(Donation::where('transaction_ref', 'REF-BAD-FILE')->exists())->toBeFalse();
})->group('FILE-01');

it('keeps the donation when the slip is the only thing that fails', function () {
    /*
     | The money has already moved. A picture that cannot be stored is worth
     | saying so about; it is not worth losing the record of the transfer.
     */
    $donor = Donor::factory()->create();
    $user = User::factory()->create(['role_id' => App\Models\Role::where('key', 'donor')->value('id')]);
    $donor->update(['user_id' => $user->id]);

    config()->set('sanabel.media.max_bytes', 10);

    Livewire::actingAs($user)
        ->test(App\Livewire\GeneralDonation::class)
        ->set('amount', 50_000)
        ->set('transactionRef', 'REF-BIG-SLIP')
        ->set('receipt', UploadedFile::fake()->image('slip.jpg')->size(64))
        ->call('record');

    $donation = Donation::where('transaction_ref', 'REF-BIG-SLIP')->first();

    expect($donation)->not->toBeNull()
        ->and($donation->receipt_media_id)->toBeNull();
});
