<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ListBeneficiaries;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 | T-223 — «يكون محجوب, يظهر له متل رسالة انه هو بغير منطقة محجوب عنك»,
 | 1 November.
 |
 | A delegate in Izra' types a file number from Bosra. The region scope
 | takes the row out of the query and the screen answers «لا توجد نتائج» —
 | the very words it uses for a number nobody ever wrote down. So the
 | delegate reads «this family is not in the system», rings the office, and
 | the office tells them it is and that they may not see it.
 |
 | The file stays hidden. Only the reason is published, and the association
 | weighed that sentence themselves: a file with this number exists and
 | belongs to another area. No name, no area, no figure, no row.
 */

beforeEach(function () {
    seedCore();
    $this->mine = regionWithRates();
    $this->theirs = regionWithRates();

    $this->delegate = userWithRole('delegate', ['region_id' => placeIn($this->mine, 'village')->id]);

    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** The delegate's own list, searched for a file number. */
function searchAs($user, string $term)
{
    return Livewire::actingAs($user)
        ->test(ListBeneficiaries::class)
        ->set('tableSearch', $term);
}

it('says the file is elsewhere rather than saying there is no file', function () {
    $theirs = publishedCase($this->theirs);

    searchAs($this->delegate, $theirs->file_number)
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertSee(__('sanabel.beneficiary.file_in_another_region'));
});

it('shows the row itself to nobody, which is the whole point', function () {
    $theirs = publishedCase($this->theirs);
    $theirs->forceFill(['first_name' => 'زينب', 'family_name' => 'الرفاعي'])->save();

    $html = searchAs($this->delegate, $theirs->file_number)->html();

    expect($html)->not->toContain('زينب')
        ->and($html)->not->toContain('الرفاعي')
        ->and($html)->not->toContain($this->theirs->name_ar);
});

it('says nothing of the sort for a number nobody ever wrote down', function () {
    publishedCase($this->theirs);

    searchAs($this->delegate, 'SWA-9999')
        ->assertDontSee(__('sanabel.beneficiary.file_in_another_region'));
});

it('says nothing when the delegate finds their own file, because there is a row', function () {
    $mine = publishedCase($this->mine);

    searchAs($this->delegate, $mine->file_number)
        ->assertCanSeeTableRecords([$mine])
        ->assertDontSee(__('sanabel.beneficiary.file_in_another_region'));
});

it('says nothing to an account the region scope does not hold', function () {
    $theirs = publishedCase($this->theirs);

    // An officer reads every area, so an empty list already means there is
    // nothing, and the sentence would be a lie rather than a kindness.
    $officer = userWithRole('case_officer');

    searchAs($officer, 'SWA-9999')
        ->assertDontSee(__('sanabel.beneficiary.file_in_another_region'));

    searchAs($officer, $theirs->file_number)
        ->assertCanSeeTableRecords([$theirs]);
});

it('matches the whole number and not a fragment of one', function () {
    $theirs = publishedCase($this->theirs);

    // A loose search would turn the box into a way of asking «how many
    // files begin SWA-00», which is a different question entirely.
    searchAs($this->delegate, mb_substr($theirs->file_number, 0, 5))
        ->assertDontSee(__('sanabel.beneficiary.file_in_another_region'));
});

it('says nothing at all when the box is empty', function () {
    publishedCase($this->theirs);

    Livewire::actingAs($this->delegate)
        ->test(ListBeneficiaries::class)
        ->assertDontSee(__('sanabel.beneficiary.file_in_another_region'));
});
