<?php

use App\Filament\Resources\SponsorshipRateResource;
use App\Livewire\BrowseCases;
use App\Livewire\BrowseSponsorships;
use App\Models\SponsorshipRate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 | T-210 — «كل كفالة يظهر فيها مبلغها الشهري المحدد ومدتها والمبلغ المتبقي
 | لتغطيتها، ولا نريد أن يظهر مبلغ احتياج الأسرة الكامل على أنه قيمة الكفالة»
 | and «تحديد مبلغ كل نوع من الكفالات يكون من صلاحيات الإدارة وفق ضوابط
 | معتمدة», 1 November.
 |
 | A sponsorship had no price. Every donor screen read a family's monthly
 | need — what that household is short — and the sponsorships page would have
 | put that figure under the heading «كفالة يتيم», which is the one thing
 | they wrote to stop. So the price is a row of its own, per door, with the
 | hand and the date behind it, and the page reads it.
 |
 | «المبلغ المتبقي لتغطيتها» is not here: what remains on a sponsorship
 | depends on how a donor takes one up, and that is a question still out
 | with the association.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->director = userWithRole('executive_director');
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    Cache::flush();
});

/** A published file the association classified under these doors. */
function fileUnder($region, array $classifications)
{
    $case = publishedCase($region);
    $case->forceFill(['case_classifications' => $classifications])->save();

    return $case->refresh();
}

/** The price of one door, as the management would set it. */
function priceFor(string $kind, int $amount = 150_000, int $months = 12, bool $active = true): SponsorshipRate
{
    return SponsorshipRate::create([
        'kind' => $kind,
        'amount' => $amount,
        'months' => $months,
        'is_active' => $active,
    ]);
}

it('lets the management set a monthly amount and a duration for a door', function () {
    $this->actingAs($this->director);

    $rate = priceFor('orphan', 200_000, 12);

    expect($rate->amount)->toBe(200_000)
        ->and($rate->months)->toBe(12)
        // Rule 9 — an integer in the smallest unit beside its currency.
        ->and($rate->amount)->toBeInt()
        ->and($rate->currency)->toBe(config('sanabel.currency'));
});

it('names the hand that set it and when, like every other decision', function () {
    $this->actingAs($this->director);

    $rate = priceFor('widowed');

    expect($rate->created_by)->toBe($this->director->id)
        ->and($rate->created_at)->not->toBeNull();
});

it('keeps one published price per door and refuses a second', function () {
    priceFor('orphan');

    // A database constraint, not a form rule (§3): two prices for «كفالة
    // يتيم» is not a thing the system can hold at all.
    expect(fn () => priceFor('orphan', 90_000))->toThrow(QueryException::class);
});

it('refuses a price of nothing', function () {
    expect(fn () => priceFor('orphan', 0))->toThrow(QueryException::class);
});

it('refuses a duration of no months', function () {
    expect(fn () => priceFor('orphan', 150_000, 0))->toThrow(QueryException::class);
});

it('opens the screen to the accounts that hold the association’s own figures', function () {
    $this->actingAs($this->director);

    expect(SponsorshipRateResource::canAccess())->toBeTrue();
});

it('closes it to everybody else, a case officer included', function () {
    $this->actingAs($this->officer);

    expect(SponsorshipRateResource::canAccess())->toBeFalse();
});

it('offers only the doors the association publishes, not every classification', function () {
    // «فقير» is a ground for a payment, not a kind of sponsorship, so there
    // is no price to set for it.
    expect(array_keys(SponsorshipRateResource::kindOptions()))
        ->toBe(['orphan', 'widowed', 'student']);
});

it('shows the door’s own monthly amount and duration on the sponsorships page', function () {
    fileUnder($this->region, ['orphan']);
    priceFor('orphan', 175_000, 12);

    Livewire::test(BrowseSponsorships::class)
        ->assertSee('كفالة يتيم')
        ->assertSee('قيمة الكفالة الشهريّة')
        ->assertSee('175,000')
        ->assertSee('12 شهرًا');
});

it('says in so many words that this is not the family’s need', function () {
    fileUnder($this->region, ['orphan']);
    priceFor('orphan');

    Livewire::test(BrowseSponsorships::class)
        ->assertSee('قيمة الكفالة غير احتياج الأسرة');
});

it('says a door has no price yet rather than leaving the need to be read as one', function () {
    fileUnder($this->region, ['orphan']);

    // No rate set at all. The need is still on the card, under its own
    // label; what must not happen is that it reads as the sponsorship.
    Livewire::test(BrowseSponsorships::class)
        ->assertSee('كفالة يتيم')
        ->assertSee('لم تُحدَّد قيمة هذا الباب بعد');
});

it('draws no sponsorship block at all on the list of cases', function () {
    fileUnder($this->region, ['orphan']);
    priceFor('orphan', 175_000);

    Livewire::test(BrowseCases::class)
        ->assertDontSee('قيمة الكفالة الشهريّة')
        ->assertDontSee('175,000');
});

it('hides a price the management has switched off', function () {
    fileUnder($this->region, ['orphan']);
    priceFor('orphan', 175_000, 12, active: false);

    Livewire::test(BrowseSponsorships::class)
        ->assertDontSee('175,000')
        // And the door itself is still there, saying it has no price yet.
        ->assertSee('لم تُحدَّد قيمة هذا الباب بعد');
});

it('prices each door on its own, so one door’s amount is not the other’s', function () {
    fileUnder($this->region, ['orphan']);
    fileUnder($this->region, ['widowed']);
    priceFor('orphan', 175_000, 12);
    priceFor('widowed', 240_000, 6);

    Livewire::test(BrowseSponsorships::class)
        ->assertSee('175,000')
        ->assertSee('240,000');
});
