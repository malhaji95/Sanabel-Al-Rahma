<?php

use App\Filament\Resources\DonationResource;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Fund;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;

/*
 | T-142 — the association's notes 6 and 7 of 28 October: «بخصوص امين الصندوق
 | بصفحة تعديل التبرع يجب السماح باختيار الصندوق العام, وما المقصود بتبرع
 | مباشر او عبر المنصة؟ وموضوع تعديل المبالغ ليش في تعديل؟» and «ممكن عنده
 | كشف حساب وعم يراجعهم: حوالة, حوالة, وبعدها بيعطي تأكيد … ممكن يأكد 20 حبة
 | او اكثر».
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** `$count` transfers waiting to be matched against a statement. */
function pendingTransfers(int $count): Collection
{
    $donor = Donor::factory()->create();

    return collect(range(1, $count))->map(fn (int $i) => Donation::factory()->create([
        'donor_id' => $donor->id,
        'status' => 'pending',
        'transaction_ref' => 'TRX-STMT-'.$i,
        'amount' => 10_000 + $i,
    ]));
}

it('confirms a whole statement of transfers in one pass', function () {
    $transfers = pendingTransfers(20);

    Livewire\Livewire::actingAs(userWithRole('treasurer'))
        ->test(DonationResource\Pages\ListDonations::class)
        ->callTableBulkAction('verifySelected', $transfers)
        ->assertSuccessful();

    expect(Donation::where('status', 'verified')->count())->toBe(20)
        ->and(Donation::where('status', 'pending')->count())->toBe(0);
});

it('leaves alone what it may not confirm, and says how many', function () {
    $pending = pendingTransfers(3);

    // One of the selection has already been verified by somebody else.
    $already = $pending->first();
    $already->forceFill(['status' => 'verified', 'verified_at' => now()])->save();

    Livewire\Livewire::actingAs(userWithRole('treasurer'))
        ->test(DonationResource\Pages\ListDonations::class)
        ->callTableBulkAction('verifySelected', $pending)
        ->assertSuccessful();

    expect(Donation::where('status', 'verified')->count())->toBe(3)
        // Nothing was verified twice: the already-verified row kept its own
        // verification, and the other two gained theirs.
        ->and(Donation::whereNotNull('verified_at')->count())->toBe(3);
});

it('refuses the whole action to an account that may not verify money', function () {
    pendingTransfers(2);

    /*
     | A seat on the board of trustees: it opens the donations list to oversee
     | it and writes nothing anywhere (hard rule 1). A case officer is not the
     | example here because the list does not open for them at all.
     */
    Livewire\Livewire::actingAs(userWithRole('council'))
        ->test(DonationResource\Pages\ListDonations::class)
        ->assertSuccessful()
        ->assertTableBulkActionHidden('verifySelected');

    expect(Donation::where('status', 'pending')->count())->toBe(2);
})->group('AUTH-02');

it('names the general fund the way the association names it', function () {
    expect(Fund::byKey(Fund::OPERATIONAL)->name_ar)->toContain('العام');
});

it('says on the screen what the route and the designation mean', function () {
    $donation = pendingTransfers(1)->first();

    $html = Livewire\Livewire::actingAs(userWithRole('treasurer'))
        ->test(DonationResource\Pages\EditDonation::class, ['record' => $donation->getKey()])
        ->assertSuccessful()
        ->html();

    expect($html)->toContain('عبر المنصة')
        ->toContain('سجّلها المتبرع من الموقع بنفسه')
        // And why an amount can be corrected at all, and until when.
        ->toContain('قبل التأكيد فقط');
});

it('closes the edit screen on money that has been verified', function () {
    // Rule 5, which is the other half of the answer about editing amounts.
    $donation = pendingTransfers(1)->first();
    $donation->forceFill(['status' => 'verified', 'verified_at' => now()])->save();

    expect(userWithRole('treasurer')->can('update', $donation->refresh()))->toBeFalse();
})->group('PAY-02');
