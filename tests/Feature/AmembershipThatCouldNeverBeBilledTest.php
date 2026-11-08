<?php

use App\Filament\Resources\MemberResource\Pages\EditMember;
use App\Filament\Resources\MemberResource\RelationManagers\SubscriptionsRelationManager;
use App\Models\Media;
use App\Models\Member;
use App\Models\Subscription;
use App\Services\MembershipService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/*
 | T-269 — found in the general audit of 8 November.
 |
 | `generateSubscriptions()` and `markPaid()` were both written with T-32 and
 | neither was ever called from the application: the member screen edited a
 | name and a category and stopped. So a membership could be opened and never
 | billed, no subscription ever existed, and the membership fund was a fund
 | nothing could reach.
 */
beforeEach(function () {
    seedCore();
    $this->member = Member::create([
        'membership_no' => 'MEM-0001',
        'name_ar' => 'أبو أحمد',
        'category' => 'عضو عامل',
        'status' => 'active',
        'joined_at' => now()->startOfYear(),
    ]);
});

function memberDesk(): void
{
    passTwoFactor();
    test()->actingAs(userWithRole('board_director'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

it('bills a member for every month in the range, and the money is the membership fund s', function () {
    memberDesk();

    Livewire\Livewire::test(SubscriptionsRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])->callTableAction('generate', data: [
        'from' => now()->startOfYear()->toDateString(),
        'to' => now()->startOfYear()->addMonths(2)->toDateString(),
        'amount' => 50_000,
        'cycle' => 'monthly',
    ]);

    $subscriptions = $this->member->subscriptions()->orderBy('period')->get();

    expect($subscriptions)->toHaveCount(3)
        ->and($subscriptions->first()->amount)->toBe(50_000)
        ->and($subscriptions->first()->status)->toBe('due')
        // Rule §13 — never a family's coverage.
        ->and($subscriptions->first()->fund->key)->toBe('membership');
});

it('adds nothing and loses nothing when the same months are generated twice', function () {
    $run = fn () => app(MembershipService::class)->generateSubscriptions(
        $this->member, now()->startOfYear(), now()->startOfYear()->addMonth(), 50_000
    );

    $run();
    $run();

    expect($this->member->subscriptions()->count())->toBe(2);
});

it('records a payment against its proof and moves the member back to active', function () {
    app(MembershipService::class)->generateSubscriptions(
        $this->member,
        Carbon::now()->subMonths(2)->startOfMonth(),
        Carbon::now()->subMonths(2)->startOfMonth(),
        50_000,
    );

    // One month overdue, so the member is not «نشط» until it is settled.
    app(MembershipService::class)->refreshMemberStatus($this->member);
    expect($this->member->refresh()->status)->toBe('overdue');

    memberDesk();

    $subscription = $this->member->subscriptions()->firstOrFail();

    Livewire\Livewire::test(SubscriptionsRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])->callTableAction('markPaid', $subscription, [
        'proof' => UploadedFile::fake()->image('receipt.jpg'),
    ]);

    expect($subscription->refresh()->status)->toBe('paid')
        ->and($subscription->payment_media_id)->not->toBeNull()
        ->and($this->member->refresh()->status)->toBe('active');
});

it('keeps the receipt on the private disk, like every other paper', function () {
    app(MembershipService::class)->generateSubscriptions(
        $this->member, now()->startOfMonth(), now()->startOfMonth(), 50_000
    );

    memberDesk();

    $subscription = $this->member->subscriptions()->firstOrFail();

    Livewire\Livewire::test(SubscriptionsRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])->callTableAction('markPaid', $subscription, [
        'proof' => UploadedFile::fake()->image('receipt.jpg'),
    ]);

    $media = $subscription->refresh()->payment_media_id;

    expect(Media::findOrFail($media)->storage_key)->not->toContain('public');
});

it('offers neither button to an account that does not manage members', function () {
    app(MembershipService::class)->generateSubscriptions(
        $this->member, now()->startOfMonth(), now()->startOfMonth(), 50_000
    );

    passTwoFactor();
    $this->actingAs(userWithRole('delegate'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire\Livewire::test(SubscriptionsRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])
        ->assertTableActionHidden('generate')
        ->assertTableActionHidden('markPaid', $this->member->subscriptions()->firstOrFail());
});
