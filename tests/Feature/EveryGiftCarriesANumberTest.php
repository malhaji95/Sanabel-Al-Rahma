<?php

use App\Livewire\DonorPortal;
use App\Models\Donation;
use App\Models\Donor;
use App\Services\BasketService;
use App\Services\CoverageService;

/*
 | T-203 — «ضروري كل تبرع ياخذ رقم تذكرة أو طلب», 1 November.
 | T-204 — «المفروض رقم ملف الفقير يكون ظاهر عنده, لو بدون تأكيد الطلب; إذا
 | ما كان موجود رح يسبب لنا مشكلة», the same day, over a row marked «مخصص
 | لملفات محددة» with a dash where the file numbers belong.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();

    $user = userWithRole('donor');
    $this->donor = Donor::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);
});

it('numbers a gift the moment it is written, by whatever path', function () {
    $donation = Donation::factory()->create(['donor_id' => $this->donor->id]);

    expect($donation->reference_no)->toMatch('/^DN-\d{2}-\d{5}$/');
});

it('gives the next gift the next number', function () {
    $first = Donation::factory()->create(['donor_id' => $this->donor->id])->reference_no;
    $second = Donation::factory()->create(['donor_id' => $this->donor->id])->reference_no;

    expect($second)->not->toBe($first);
});

it('leaves the bank’s own reference exactly where it was', function () {
    $donation = Donation::factory()->create([
        'donor_id' => $this->donor->id,
        'transaction_ref' => 'SHAM-9001',
    ]);

    // Rule 1 — the bank's number is the unique one the money is matched on,
    // and the platform's number is a second thing, not a replacement.
    expect($donation->transaction_ref)->toBe('SHAM-9001')
        ->and($donation->reference_no)->not->toBe('SHAM-9001');
});

it('refuses to hand the same number to two gifts', function () {
    $taken = Donation::factory()->create(['donor_id' => $this->donor->id])->reference_no;

    expect(fn () => Donation::factory()->create([
        'donor_id' => $this->donor->id,
        'reference_no' => $taken,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('shows the donor the number on their own page', function () {
    $donation = Donation::factory()->create(['donor_id' => $this->donor->id, 'status' => 'pending']);

    Livewire\Livewire::test(DonorPortal::class)
        ->assertSee($donation->reference_no)
        ->assertSee(__('sanabel.donation.reference_no'));
});

/*
 | T-204 — the file numbers, before the treasurer has matched anything.
 */
it('names the families on a transfer the treasurer has not confirmed yet', function () {
    $case = publishedCase($this->region);

    $basket = app(BasketService::class)->openFor($this->donor);
    app(BasketService::class)->addItem(
        $basket,
        $case,
        app(CoverageService::class)->remainingNeed($case),
        app(CoverageService::class)->currentMonth(),
    );

    $donation = Donation::factory()->create([
        'donor_id' => $this->donor->id,
        'basket_id' => $basket->getKey(),
        'designation' => 'earmarked',
        'status' => 'pending',
    ]);

    expect($donation->allocations()->count())->toBe(0);

    Livewire\Livewire::test(DonorPortal::class)->assertSee($case->file_number);
});

it('still shows nothing on a gift that names no family', function () {
    Donation::factory()->create([
        'donor_id' => $this->donor->id,
        'designation' => 'general',
        'status' => 'pending',
    ]);

    // A general gift has no files behind it, and a dash is the honest answer.
    Livewire\Livewire::test(DonorPortal::class)->assertSee('—');
});
