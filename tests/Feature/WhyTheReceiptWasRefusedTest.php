<?php

use App\Livewire\DonorPortal;
use App\Models\Donor;
use App\Services\DonationService;

/*
 | T-181 — «نعم نحتاج إضافة غير المؤكدة ومعها تاريخ والمبلغ والحالة, ويعرض سبب
 | الرفض والإيصال», 29 October.
 |
 | Half of it was already there: a transfer still waiting has been on the
 | donor's page with its date, its amount and «قيد المراجعة» since the page was
 | written. The two that were missing are the ones that matter on a refusal —
 | why, and the slip the treasurer was reading. «مرفوضة» on its own leaves a
 | donor with no idea whether to send the money again or to photograph the
 | paper again.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();

    $user = userWithRole('donor');
    $this->donor = Donor::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);
});

it('shows a transfer that is still waiting, with its date and its amount', function () {
    app(DonationService::class)->record([
        'donor_id' => $this->donor->id,
        'amount' => 25_000,
        'transaction_ref' => 'TRX-WAITING-1',
    ]);

    Livewire\Livewire::test(DonorPortal::class)
        ->assertSee('TRX-WAITING-1')
        ->assertSee(number_format(25_000))
        ->assertSee(__('sanabel.donations.pending'));
});

it('says why a receipt was refused, in the treasurer’s own words', function () {
    $donation = app(DonationService::class)->record([
        'donor_id' => $this->donor->id,
        'amount' => 15_000,
        'transaction_ref' => 'TRX-REFUSED-1',
    ]);

    app(DonationService::class)->reject(
        $donation,
        userWithRole('finance')->id,
        'صورة الإيصال غير واضحة, الرجاء إعادة تصويرها.',
    );

    Livewire\Livewire::test(DonorPortal::class)
        ->assertSee(__('sanabel.donations.rejected'))
        ->assertSee('صورة الإيصال غير واضحة, الرجاء إعادة تصويرها.');
});

it('keeps a stale reason off a transfer that was accepted', function () {
    $donation = app(DonationService::class)->record([
        'donor_id' => $this->donor->id,
        'amount' => 15_000,
        'transaction_ref' => 'TRX-VERIFIED-1',
    ]);

    /*
     | A reason left on the row from an earlier look at it. A sentence about a
     | refusal, printed beside money that arrived, reads like a complaint about
     | the money — so the page shows it on a refusal and on nothing else.
     */
    $donation->forceFill(['reject_reason' => 'سبب قديم'])->saveQuietly();
    app(DonationService::class)->verify($donation->fresh(), userWithRole('finance')->id);

    Livewire\Livewire::test(DonorPortal::class)
        ->assertSee(__('sanabel.donations.verified'))
        ->assertDontSee('سبب قديم');
});

it('offers the donor their own slip, and says so plainly when there is none', function () {
    app(DonationService::class)->record([
        'donor_id' => $this->donor->id,
        'amount' => 15_000,
        'transaction_ref' => 'TRX-NO-SLIP',
    ]);

    Livewire\Livewire::test(DonorPortal::class)
        ->assertSee(__('sanabel.donation.receipt'))
        ->assertSee(__('sanabel.donation.receipt_none'));
});

it('shows one donor nothing of another donor’s transfers', function () {
    $other = Donor::factory()->create();

    app(DonationService::class)->record([
        'donor_id' => $other->id,
        'amount' => 99_000,
        'transaction_ref' => 'TRX-SOMEBODY-ELSE',
    ]);

    Livewire\Livewire::test(DonorPortal::class)
        ->assertDontSee('TRX-SOMEBODY-ELSE');
})->group('AUTH-02');
