<?php

use App\Livewire\BrowseCases;
use App\Livewire\BrowseSponsorships;
use App\Models\Setting;
use App\Services\DonorCaseList;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 | T-228 — «مابدنا الكفالات، تظهر في الحالات / ولكن، في صفحة الكفالات لازم
 | يظهر كل كفاله، ايش نوعها يتيم ارمله طالب», 1 November.
 |
 | T-208 had put the row of doors on /cases, which is the one place they
 | said not to have it. So it moves: a page of its own, open on every door
 | at once, each card carrying the kind it is.
 |
 | The families themselves are not moved. A family classified as an orphan
 | file is still on the list of cases, where every published family is; the
 | sponsorships page is a second way in, not a partition. Nothing is hidden
 | from a donor by this, which is the whole reason it is safe to do.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Cache::flush();
});

/** A published file carrying the association's classifications. */
function sponsored($region, array $classifications)
{
    $case = publishedCase($region);
    $case->forceFill(['case_classifications' => $classifications])->save();

    return $case->refresh();
}

it('draws no row of doors on the list of cases any more', function () {
    sponsored($this->region, ['orphan']);

    Livewire::test(BrowseCases::class)->assertDontSee('باب الكفالة:');
});

it('keeps the family itself on the list of cases, nothing having been hidden', function () {
    $orphan = sponsored($this->region, ['orphan']);

    Livewire::test(BrowseCases::class)->assertSee($orphan->file_number);
});

it('opens the sponsorships page on every door at once', function () {
    $orphan = sponsored($this->region, ['orphan']);
    $widow = sponsored($this->region, ['widowed']);
    $student = sponsored($this->region, ['student']);

    Livewire::test(BrowseSponsorships::class)
        ->assertSee('باب الكفالة:')
        ->assertSee($orphan->file_number)
        ->assertSee($widow->file_number)
        ->assertSee($student->file_number);
});

it('says on each card which kind of sponsorship it is', function () {
    sponsored($this->region, ['widowed']);

    // T-205 wrote the badge; the page they asked for is where it is read.
    Livewire::test(BrowseSponsorships::class)->assertSee('أرملة');
});

it('leaves out a family that carries no kind of sponsorship', function () {
    $orphan = sponsored($this->region, ['orphan']);
    $poor = sponsored($this->region, ['poor']);

    Livewire::test(BrowseSponsorships::class)
        ->assertSee($orphan->file_number)
        // «فقير» is a ground for a payment, not a kind of sponsorship. The
        // family is on the list of cases, where it belongs.
        ->assertDontSee($poor->file_number);
});

it('offers no door that is a zakat category rather than a sponsorship', function () {
    sponsored($this->region, ['orphan']);
    sponsored($this->region, ['poor', 'needy', 'debtor']);

    expect(array_keys(app(DonorCaseList::class)->sponsorshipDoors()))
        ->toBe(['orphan']);
});

it('narrows the page to the door a donor chose', function () {
    $orphan = sponsored($this->region, ['orphan']);
    $widow = sponsored($this->region, ['widowed']);

    Livewire::test(BrowseSponsorships::class)
        ->set('kind', 'orphan')
        ->assertSee($orphan->file_number)
        ->assertDontSee($widow->file_number);
});

it('lets go of a door typed into the address that nobody declared', function () {
    $orphan = sponsored($this->region, ['orphan']);

    // Not «no families match»: the question was never a question, so the
    // page answers the one it was asked before it.
    Livewire::test(BrowseSponsorships::class)
        ->set('kind', 'nonsense_key')
        ->assertSet('kind', null)
        ->assertSee($orphan->file_number);
});

it('lets go of a real classification that is not one of the doors', function () {
    $orphan = sponsored($this->region, ['orphan']);
    $poor = sponsored($this->region, ['poor']);

    Livewire::test(BrowseSponsorships::class)
        ->set('kind', 'poor')
        ->assertSet('kind', null)
        ->assertSee($orphan->file_number)
        ->assertDontSee($poor->file_number);
});

it('sends the visitor to the list of cases when the association closes the words', function () {
    sponsored($this->region, ['orphan']);

    Setting::updateOrCreate(['key' => 'show_sponsorship_kind'], ['value_json' => false]);
    Setting::forgetLoaded();

    /*
     | Rule 2 — to be shown a family under «يتيم» is to be told that family
     | is an orphan file, which is the disclosure this switch governs. With
     | it closed the page would publish exactly that and call itself a page,
     | so there is no page: the visitor is sent where every one of these
     | families already is.
     */
    Livewire::test(BrowseSponsorships::class)
        ->assertRedirect(route('cases.browse'));
});

it('sends the visitor away when no published file carries any kind either', function () {
    sponsored($this->region, ['poor']);

    Livewire::test(BrowseSponsorships::class)
        ->assertRedirect(route('cases.browse'));
});

it('answers on its own address', function () {
    sponsored($this->region, ['orphan']);

    $this->get(route('sponsorships.browse'))
        ->assertOk()
        ->assertSee('الكفالات');
});

it('says which door emptied the page when the chosen one has nobody in the region', function () {
    sponsored($this->region, ['orphan']);
    sponsored(regionWithRates(), ['widowed']);

    Livewire::test(BrowseSponsorships::class)
        ->set('regionId', $this->region->id)
        ->set('kind', 'widowed')
        ->assertSee('لا توجد حالياً حالة منشورة تحت باب «أرملة»');
});
