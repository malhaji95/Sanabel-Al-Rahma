<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Widgets\OverviewStats;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;

/*
 | T-126 — «دخلت على حساب مندوب ميداني وبلوحة التحكم ضمن بطاقات KPI Cards
 | تظهر له معلومات تخص المدير التنفيذي, انا اريد ان يظهر له الملفات المنشورة
 | بانتظار الاعتماد وهي الامور التي تخصه هو بمنطقته», 27 October.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** The cards the widget would draw for this account. */
function cardsFor(User $user): Collection
{
    test()->actingAs($user);

    $method = new ReflectionMethod(OverviewStats::class, 'getStats');
    $method->setAccessible(true);

    return collect($method->invoke(new OverviewStats))
        ->mapWithKeys(fn ($stat) => [strip_tags((string) $stat->getLabel()) => $stat]);
}

it('shows a delegate their own files and not the association money', function () {
    $delegate = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);
    $other = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);

    // Two of mine waiting on a visit, one of them sent back with a note, and
    // one that belongs to the delegate at the next desk.
    foreach ([[$delegate, 'pending_visit', null], [$delegate, 'pending_visit', 'ينقص إثبات الإيجار'], [$other, 'pending_visit', null]] as [$owner, $status, $note]) {
        familyOf($this->region)->forceFill([
            'created_by' => $owner->id, 'status' => $status, 'reject_reason_ar' => $note,
        ])->save();
    }

    familyOf($this->region)->forceFill(['created_by' => $delegate->id, 'status' => 'verified'])->save();

    Donation::factory()->create(['donor_id' => Donor::factory()->create()->id, 'status' => 'pending']);

    $cards = cardsFor($delegate);

    // His own work, counted on his own files.
    expect($cards)->toHaveKey(__('sanabel.dashboard.my_awaiting_visit'))
        ->and($cards[__('sanabel.dashboard.my_awaiting_visit')]->getValue())->toBe(2)
        ->and($cards[__('sanabel.dashboard.my_awaiting_supervisor')]->getValue())->toBe(1)
        // And the one sent back to him says so on the card itself.
        ->and((string) $cards[__('sanabel.dashboard.my_awaiting_visit')]->getDescription())
        ->toBe(__('sanabel.dashboard.my_returned', ['count' => 1]));

    // Nothing about the association's cash, which he can neither read nor act on.
    expect($cards)->not->toHaveKey(__('sanabel.dashboard.pending_verification'))
        ->and($cards)->not->toHaveKey(__('sanabel.dashboard.earmarked_this_month'))
        ->and($cards)->not->toHaveKey(__('sanabel.dashboard.reserved_now'));
})->group('AUTH-01');

it('sends the delegate from a card to his own files, not to the whole village', function () {
    $delegate = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);

    $url = cardsFor($delegate)[__('sanabel.dashboard.my_published')]->getUrl();

    expect($url)->toContain(BeneficiaryResource::getUrl('index'))
        ->toContain('published')
        ->toContain('mine');
});

it('narrows the list to the files the account opened when that filter is on', function () {
    $delegate = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);
    $other = userWithRole('delegate', ['region_id' => $delegate->region_id]);

    $ours = familyOf($this->region);
    $ours->forceFill(['created_by' => $delegate->id, 'status' => 'published'])->save();

    $theirs = familyOf($this->region);
    $theirs->forceFill(['created_by' => $other->id, 'status' => 'published'])->save();

    Livewire\Livewire::actingAs($delegate)
        ->test(BeneficiaryResource\Pages\ListBeneficiaries::class)
        // Both are in his village, so without the filter he sees both.
        ->assertCanSeeTableRecords([$ours, $theirs])
        ->set('tableFilters.mine.isActive', true)
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('keeps the review queues for the accounts that answer for them', function () {
    familyOf($this->region)->forceFill(['status' => 'verified'])->save();

    $supervisor = cardsFor(userWithRole('area_supervisor', ['region_id' => $this->region->id]));

    expect($supervisor)->toHaveKey(__('sanabel.dashboard.awaiting_supervisor'))
        ->and($supervisor)->toHaveKey(__('sanabel.dashboard.published_cases'))
        // An area supervisor touches no money either.
        ->and($supervisor)->not->toHaveKey(__('sanabel.dashboard.pending_verification'));

    $director = cardsFor(userWithRole('executive_director'));

    expect($director)->toHaveKey(__('sanabel.dashboard.awaiting_supervisor'))
        ->and($director)->toHaveKey(__('sanabel.dashboard.pending_verification'))
        ->and($director)->toHaveKey(__('sanabel.dashboard.reserved_now'));
})->group('AUTH-01');

it('shows the treasurer the money and not the families', function () {
    // view_masked_case, not view_full_case: the money is theirs to read, the
    // review queue is not.
    $cards = cardsFor(userWithRole('treasurer'));

    expect($cards)->toHaveKey(__('sanabel.dashboard.pending_verification'))
        ->and($cards)->not->toHaveKey(__('sanabel.dashboard.published_cases'))
        ->and($cards)->not->toHaveKey(__('sanabel.dashboard.awaiting_delegate'));
})->group('AUTH-02');

it('counts the queue a supervisor is shown inside their own region only', function () {
    $elsewhere = regionWithRates();

    familyOf($this->region)->forceFill(['status' => 'verified'])->save();
    familyOf($elsewhere)->forceFill(['status' => 'verified'])->save();

    $cards = cardsFor(userWithRole('area_supervisor', ['region_id' => $this->region->id]));

    expect($cards[__('sanabel.dashboard.awaiting_supervisor')]->getValue())->toBe(1);
})->group('AUTH-01');
