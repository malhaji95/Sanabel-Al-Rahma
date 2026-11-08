<?php

use App\Filament\Pages\AllocateGeneralMoney;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Services\BasketService;
use App\Services\CoverageService;
use App\Services\DonationService;
use App\Services\GeneralMoneyService;
use Filament\Facades\Filament;

/*
 | T-263 — «الأبواب وأرقام ملفاتها والمبالغ».
 |
 | «مثلا كفالة حلقة القرآن وين راح يروح المبلغ, وبأي مسمى يجب أن يكون مقيد
 | للباب ... والمبالغ تروح عل رقم ملف الباب, تكون مقيدة له.»
 |
 | A door is a campaign that names no family: T-190 made the basket able to
 | hold one, after the association's «هذه ليست حالات فقراء, هذه الحملات». What
 | nobody had built was the other end — money that reaches a door had no way
 | out of it, and a pledge to one brought the treasurer's verification down
 | altogether.
 |
 | The rule these hold is the association's own: money given to a door stays
 | restricted to that door until the association places it on a family, and
 | what is placed can never exceed what was given.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->admin = userWithRole('executive_director');
    $this->money = app(GeneralMoneyService::class);
});

function door(string $title = 'كفالة حلقة قرآن'): Campaign
{
    return Campaign::factory()->create([
        // The whole of what makes it a door: it names no family.
        'beneficiary_id' => null,
        'goal_amount' => 0,
        'status' => 'active',
        'is_published' => true,
        'surplus_policy_text_ar' => 'يوجّه الفائض إلى الباب نفسه في الشهر التالي.',
        'title_ar' => $title,
    ]);
}

function giveToDoor(Campaign $door, int $amount): Donation
{
    static $ref = 0;

    $donor = Donor::factory()->create();
    $basket = app(BasketService::class)->openFor($donor);

    app(BasketService::class)->addCampaign($basket, $door, $amount);
    app(BasketService::class)->reserve($basket);

    return app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'basket_id' => $basket->id,
        'amount' => $amount,
        'transaction_ref' => 'DOOR-'.(++$ref),
    ]);
}

it('takes a pledge to a door through verification without falling over', function () {
    $door = door();
    $donation = giveToDoor($door, 500_000);

    app(DonationService::class)->verify($donation, $this->admin->id);

    $allocation = DonationAllocation::where('donation_id', $donation->id)->firstOrFail();

    expect($allocation->campaign_id)->toBe($door->id)
        // Nobody's family: the money is on the door and on nothing else yet.
        ->and($allocation->beneficiary_id)->toBeNull()
        ->and($allocation->amount)->toBe(500_000);
});

it('holds the money on the door until the association places it', function () {
    $door = door();
    $donation = giveToDoor($door, 500_000);

    expect($this->money->doorBalance($door))->toBe(0, 'unverified money is not a balance');

    app(DonationService::class)->verify($donation, $this->admin->id);

    expect($this->money->doorBalance($door))->toBe(500_000)
        // And it is not general money: nobody may spend it from that screen.
        ->and($this->money->balance())->toBe(0);
});

it('places part of it on a family and keeps the rest on the door', function () {
    $door = door();
    $case = publishedCase($this->region);

    app(DonationService::class)->verify(giveToDoor($door, 500_000), $this->admin->id);

    $this->money->allocateFromDoor(
        $door, $case, 300_000, app(CoverageService::class)->currentMonth(), $this->admin
    );

    expect($this->money->doorBalance($door))->toBe(200_000)
        ->and(app(CoverageService::class)->confirmedSupport($case))->toBe(300_000)
        // The door's own total never moved: placing is not spending twice.
        ->and(app(CoverageService::class)->campaignConfirmed($door))->toBe(500_000);
});

it('refuses to place more than the door holds', function () {
    $door = door();
    $case = publishedCase($this->region);

    app(DonationService::class)->verify(giveToDoor($door, 100_000), $this->admin->id);

    expect(fn () => $this->money->allocateFromDoor(
        $door, $case, 150_000, app(CoverageService::class)->currentMonth(), $this->admin
    ))->toThrow(RuntimeException::class);

    expect($this->money->doorBalance($door))->toBe(100_000);
});

it('draws across two gifts when one does not cover the placement', function () {
    $door = door();
    $case = publishedCase($this->region);

    app(DonationService::class)->verify(giveToDoor($door, 100_000), $this->admin->id);
    app(DonationService::class)->verify(giveToDoor($door, 100_000), $this->admin->id);

    $this->money->allocateFromDoor(
        $door, $case, 150_000, app(CoverageService::class)->currentMonth(), $this->admin
    );

    expect($this->money->doorBalance($door))->toBe(50_000)
        ->and(app(CoverageService::class)->confirmedSupport($case))->toBe(150_000);
});

it('carries a file number of its own, in the association own scheme', function () {
    $door = door();

    // SWA-0001 is a family (T-191). A door is SWB-0001, same scheme, and the
    // association reads the letter rather than having to remember an id.
    expect($door->reference_no)->toMatch('/^SWB-\d{4,}$/');
});

it('never lets a door s money be spent as general money', function () {
    $door = door();
    app(DonationService::class)->verify(giveToDoor($door, 400_000), $this->admin->id);

    // The general screen lists what may be spent at the association's own
    // discretion. A door's money was given for the door.
    expect(app(GeneralMoneyService::class)->undistributed())->toBeEmpty();
});

it('lists the door with its balance on the money screen, and places from it', function () {
    $door = door();
    $case = publishedCase($this->region);

    app(DonationService::class)->verify(giveToDoor($door, 400_000), $this->admin->id);

    passTwoFactor();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $page = Livewire\Livewire::test(AllocateGeneralMoney::class);

    $page->assertSee($door->reference_no)
        ->assertSee($door->title_ar)
        ->assertSee(number_format(400_000));

    $page->call('selectDoor', $door->id)
        ->set('data.beneficiary_id', $case->id)
        ->set('data.coverage_month', app(CoverageService::class)->currentMonth()->format('Y-m'))
        ->set('data.amount', 250_000)
        ->call('allocateFromDoor');

    expect(app(GeneralMoneyService::class)->doorBalance($door->refresh()))->toBe(150_000)
        ->and(app(CoverageService::class)->confirmedSupport($case))->toBe(250_000);
});

it('names the door on the donor receipt, with the number the money was asked for by', function () {
    $door = door();
    $donation = giveToDoor($door, 400_000);

    app(DonationService::class)->verify($donation, $this->admin->id);

    $body = $this->get(route('donation.receipt', ['token' => $donation->receipt_token]))
        ->assertOk()->getContent();

    expect($body)->toContain($door->title_ar)
        ->and($body)->toContain($door->reference_no)
        // Not the words for money the donor aimed at files they picked.
        ->and($body)->not->toContain(__('sanabel.donation_receipt.kinds.earmarked'));
});
