<?php

use App\Livewire\DonorFavourites;
use App\Livewire\FavouriteToggle;
use App\Models\Donor;
use App\Services\CoverageService;
use App\Services\DonationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 | T-180 — «المفضلة هي الحالات التي يتم حفظها من قبل المتبرع للعودة لها, وعند
 | السداد لهم تبقى في المفضلة للعودة لها متى ما شاء», 29 October — the
 | association's answer to what «المفضلة» on the donor's menu actually holds.
 |
 | The second half of their sentence is the part that decides the shape:
 | paying does not take a family off the list. A donor who funded a household
 | in March and wants to look in on them in June is the case this is for.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();

    $user = userWithRole('donor');
    $this->donor = Donor::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);
});

it('keeps a family when the donor asks, and lets go when they ask again', function () {
    $case = publishedCase($this->region);

    $toggle = Livewire\Livewire::test(FavouriteToggle::class, ['fileNumber' => $case->file_number])
        ->assertSet('saved', false)
        ->call('toggle')
        ->assertSet('saved', true);

    expect($this->donor->favourites()->count())->toBe(1);

    $toggle->call('toggle')->assertSet('saved', false);

    expect($this->donor->favourites()->count())->toBe(0);
});

it('leaves the family on the list after the donor has paid for them', function () {
    // Their own sentence: «وعند السداد لهم تبقى في المفضلة للعودة لها متى ما شاء».
    $case = publishedCase($this->region);
    $this->donor->favourites()->attach($case->getKey());

    fundFor($case, app(CoverageService::class)->needAmount($case));

    Livewire\Livewire::test(DonorFavourites::class)->assertSee($case->file_number);

    expect($this->donor->fresh()->favourites()->count())->toBe(1);
});

it('keeps one row however many times the same family is saved', function () {
    $case = publishedCase($this->region);

    // The unique index decides, not the screen: two taps arriving together
    // cannot leave two rows behind. Asked inside a savepoint of its own,
    // because a refusal from PostgreSQL poisons the transaction around it and
    // the count afterwards would be answered «transaction is aborted».
    $this->donor->favourites()->attach($case->getKey());

    $refused = false;

    try {
        DB::transaction(fn () => DB::table('donor_favourites')->insert([
            'donor_id' => $this->donor->getKey(),
            'beneficiary_id' => $case->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    } catch (QueryException) {
        $refused = true;
    }

    expect($refused)->toBeTrue()
        ->and($this->donor->favourites()->count())->toBe(1);
});

it('shows a kept family masked, exactly as the browse list does', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['first_name' => 'سعاد', 'family_name' => 'الحلبي'])->save();
    $this->donor->favourites()->attach($case->getKey());

    Livewire\Livewire::test(DonorFavourites::class)
        ->assertSee($case->file_number)
        ->assertDontSee('سعاد')
        ->assertDontSee('الحلبي');
})->group('AUTH-03');

it('rests a family whose file is no longer published, and says how many', function () {
    $published = publishedCase($this->region);
    $withdrawn = publishedCase($this->region);

    $this->donor->favourites()->attach([$published->getKey(), $withdrawn->getKey()]);
    $withdrawn->forceFill(['status' => 'closed'])->save();

    Livewire\Livewire::test(DonorFavourites::class)
        ->assertSee($published->file_number)
        ->assertDontSee($withdrawn->file_number)
        ->assertSee(__('sanabel.favourites.resting', ['count' => 1]));

    // The row itself stays, so the family comes back if the file does.
    expect($this->donor->favourites()->count())->toBe(2);
});

it('takes no family from a file nobody has published', function () {
    $draft = familyOf($this->region);

    Livewire\Livewire::test(FavouriteToggle::class, ['fileNumber' => $draft->file_number])
        ->call('toggle')
        ->assertSet('saved', false);

    expect($this->donor->favourites()->count())->toBe(0);
});

it('shows one donor nothing of another donor’s list', function () {
    $case = publishedCase($this->region);
    Donor::factory()->create()->favourites()->attach($case->getKey());

    Livewire\Livewire::test(DonorFavourites::class)
        ->assertSee(__('sanabel.favourites.empty'))
        ->assertDontSee($case->file_number);
})->group('AUTH-02');

it('offers no button at all to somebody with no donor account', function () {
    $case = publishedCase($this->region);

    $this->actingAs(userWithRole('case_officer', ['region_id' => $this->region->id]));

    Livewire\Livewire::test(FavouriteToggle::class, ['fileNumber' => $case->file_number])
        ->assertDontSee(__('sanabel.favourites.add'))
        ->call('toggle');

    expect(DB::table('donor_favourites')->count())->toBe(0);
});

it('does not let a donation to one family quietly keep it', function () {
    // Keeping is the donor's own act and nothing else's: a family they paid
    // for but never kept does not appear on this list.
    $case = publishedCase($this->region);

    app(DonationService::class)->record([
        'donor_id' => $this->donor->getKey(),
        'amount' => 10_000,
        'transaction_ref' => 'TRX-NOT-A-FAVOURITE',
    ]);

    Livewire\Livewire::test(DonorFavourites::class)
        ->assertSee(__('sanabel.favourites.empty'))
        ->assertDontSee($case->file_number);
});
