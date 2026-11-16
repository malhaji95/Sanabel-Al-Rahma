<?php

use App\Filament\Resources\DonationResource;
use App\Filament\Resources\DonationResource\Pages\ListDonations;
use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\Donor;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 | T-307 — «أكّدنا الطلب, تم. طيب الإجراء اللي بعده؟ شو أسوي من بعدين؟»,
 | 16 November, and «لازم يكون موجود رقم الطلب, ورقم الملف», 28 October.
 |
 | Nothing in the chain is missing. T-143 built the path from a payment
 | request to the treasurer and on to the executive director; T-215 sends the
 | household its message when the transfer is recorded and not a moment
 | earlier; T-234 reads the family's standing again at that moment; and the
 | association's rule of 8 October — silence is followed up, never read as a
 | confirmation — is what closes or escalates the line afterwards.
 |
 | What is missing is a sentence. The donations screen says how a transfer
 | becomes verified, which is what T-124 added, and then stops — so the
 | account that verified it is left holding money and no next step, and asks
 | the same question twice three weeks apart.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** The line the screen shows above the table, read as that role reads it. */
function donationsSubheading(string $role): string
{
    return (string) Livewire::actingAs(userWithRole($role))
        ->test(ListDonations::class)
        ->instance()
        ->getSubheading();
}

it('tells the account that verified the money where the next step lives', function () {
    expect(donationsSubheading('treasurer'))
        ->toContain(__('sanabel.donations.verify_help'))
        ->toContain(__('sanabel.donations.after_verify_help'));
});

it('says it to an account that may not verify, which reads the same screen', function () {
    // T-124 gave that account its own half of the sentence. The second half
    // is the same for everybody: where the money goes is not a permission.
    expect(donationsSubheading('data_supervisor'))
        ->toContain(__('sanabel.donations.verify_help_readonly'))
        ->toContain(__('sanabel.donations.after_verify_help'));
});

it('names the three places, so the sentence can actually be followed', function () {
    /*
     | A signpost that says «there is more to do» and not where is the
     | complaint, not the answer. These are the three screen names as they
     | are written in the navigation.
     */
    $said = __('sanabel.donations.after_verify_help');

    expect($said)->toContain(__('sanabel.general.title'))
        ->and($said)->toContain(__('sanabel.disbursement.plural'))
        ->and($said)->toContain(__('sanabel.disbursement.execute'));
});

it('puts the file number on a transfer that names a family', function () {
    $family = publishedCase($this->region);

    $donation = Donation::factory()->create([
        'donor_id' => Donor::factory()->create()->id,
        'designation' => 'earmarked',
        'status' => 'verified',
    ]);

    DonationAllocation::create([
        'donation_id' => $donation->getKey(),
        'beneficiary_id' => $family->getKey(),
        'amount' => 10_000,
        'currency' => config('sanabel.currency'),
    ]);

    Livewire::actingAs(userWithRole('treasurer', ['region_id' => $this->region->id]))
        ->test(ListDonations::class)
        // The screen opens on «قيد المراجعة»; a verified transfer is read
        // under its own filter, which is where this question is asked.
        ->set('tableFilters.status.value', 'verified')
        ->assertSuccessful()
        ->assertSee($family->file_number);
});

it('says a general transfer has no file yet, rather than leaving the cell blank', function () {
    /*
     | «ما يُدخَل يدويًّا يكون عامًّا حتى توزّعه الجمعية». An empty cell reads
     | as a missing figure; this one is a state the treasurer acts on.
     */
    Donation::factory()->create([
        'donor_id' => Donor::factory()->create()->id,
        'designation' => 'general',
        'status' => 'verified',
    ]);

    Livewire::actingAs(userWithRole('treasurer', ['region_id' => $this->region->id]))
        ->test(ListDonations::class)
        ->set('tableFilters.status.value', 'verified')
        ->assertSuccessful()
        ->assertSee(__('sanabel.donation.files_none'));
});

it('reads the files once for the page, not once for every row', function () {
    $this->actingAs(userWithRole('treasurer', ['region_id' => $this->region->id]));

    expect(array_keys(DonationResource::getEloquentQuery()->getEagerLoads()))
        ->toContain('allocations.beneficiary');
});
