<?php

use App\Filament\Resources\ReferralRequestResource;
use App\Livewire\FamilyPortal;
use App\Models\AppNotification;
use App\Models\Provider;
use App\Models\Referral;
use App\Models\ReferralRequest;
use App\Services\HealthPathService;
use Filament\Facades\Filament;

/*
 | T-145 — «ونحتاج يكون عنده تقديم مسار الصحي اللي هو تقديم على اطباء او مشافي
 | او صيدلية», 28 October.
 |
 | Both ends of the path existed: a provider with a discount and an agreed
 | number of cases, and a single-use card the provider redeems. What had no
 | room anywhere was the beginning — a household saying «I need a doctor» —
 | and the card nobody could issue, because the screen that issues them asked
 | for a key only a service provider holds.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** A household with its own portal account, and its file. */
function householdWithAccount(): array
{
    $family = publishedCase(test()->region);
    $user = userWithRole('beneficiary');
    $family->forceFill(['user_id' => $user->id])->save();

    return [$family->refresh(), $user];
}

/** A contracted provider of one kind. */
function providerOfType(string $type): Provider
{
    return Provider::factory()->create([
        'type' => $type,
        'status' => 'active',
        'region_id' => test()->region->id,
    ]);
}

it('lets a household apply from its own page', function () {
    [$family, $user] = householdWithAccount();
    $officer = userWithRole('case_officer');

    Livewire\Livewire::actingAs($user)
        ->test(FamilyPortal::class)
        ->set('healthType', 'doctor')
        ->set('healthSpecialty', 'عيون')
        ->set('healthNote', 'والدتي تحتاج فحص نظر')
        ->call('applyForHealthPath')
        ->assertHasNoErrors();

    $request = ReferralRequest::firstOrFail();

    expect($request->beneficiary_id)->toBe($family->id)
        ->and($request->provider_type)->toBe('doctor')
        ->and($request->specialty_ar)->toBe('عيون')
        ->and($request->status)->toBe('open');

    // And whoever issues the cards is told, with the file number only.
    $told = AppNotification::where('recipient_id', $officer->id)
        ->where('template_key', 'health_path_requested')
        ->first();

    expect($told)->not->toBeNull()
        ->and($told->payload_json)->toBe(['file_number' => $family->file_number])
        // Never the words the family wrote (rule 10).
        ->and(json_encode($told->payload_json, JSON_UNESCAPED_UNICODE))->not->toContain('نظر');
});

it('refuses a second open asking of the same kind', function () {
    [$family, $user] = householdWithAccount();

    app(HealthPathService::class)->apply($family, 'pharmacy');

    $page = Livewire\Livewire::actingAs($user)
        ->test(FamilyPortal::class)
        ->set('healthType', 'pharmacy')
        ->call('applyForHealthPath');

    expect($page->get('error'))->toBe(__('sanabel.health_path.already_open'))
        ->and(ReferralRequest::count())->toBe(1);

    // A different kind is a different need, and it is accepted.
    Livewire\Livewire::actingAs($user)
        ->test(FamilyPortal::class)
        ->set('healthType', 'hospital')
        ->call('applyForHealthPath')
        ->assertHasNoErrors();

    expect(ReferralRequest::count())->toBe(2);
});

it('answers an asking with the card the provider will redeem', function () {
    [$family, $user] = householdWithAccount();
    $officer = userWithRole('case_officer');

    $request = app(HealthPathService::class)->apply($family, 'hospital', 'جراحة');
    $hospital = providerOfType('hospital');

    $referral = app(HealthPathService::class)->issue($request, $hospital, $officer);

    expect($referral)->toBeInstanceOf(Referral::class)
        ->and($referral->beneficiary_id)->toBe($family->id)
        ->and($referral->provider_id)->toBe($hospital->id)
        ->and($referral->status)->toBe('issued')
        ->and($referral->code)->not->toBeEmpty();

    $request->refresh();

    expect($request->status)->toBe('issued')
        ->and($request->referral_id)->toBe($referral->id)
        ->and($request->handled_by)->toBe($officer->id);

    // The household is told, and reads the code on their own page — never in
    // the message.
    $told = AppNotification::where('recipient_id', $user->id)
        ->where('template_key', 'health_path_issued')
        ->first();

    expect($told)->not->toBeNull()
        ->and(json_encode($told->payload_json, JSON_UNESCAPED_UNICODE))->not->toContain($referral->code);

    $html = Livewire\Livewire::actingAs($user)->test(FamilyPortal::class)->html();

    expect($html)->toContain($referral->code);
});

it('refuses a card from the wrong kind of provider', function () {
    [$family] = householdWithAccount();

    $request = app(HealthPathService::class)->apply($family, 'hospital');

    expect(fn () => app(HealthPathService::class)
        ->issue($request, providerOfType('pharmacy'), userWithRole('case_officer')))
        ->toThrow(RuntimeException::class);

    expect($request->refresh()->status)->toBe('open');
});

it('never closes an asking without a reason, and tells the household', function () {
    [$family, $user] = householdWithAccount();

    $request = app(HealthPathService::class)->apply($family, 'lab');
    $officer = userWithRole('case_officer');

    expect(fn () => app(HealthPathService::class)->decline($request, $officer, '   '))
        ->toThrow(RuntimeException::class);

    app(HealthPathService::class)->decline($request, $officer, 'لا يوجد مخبر متعاقد في منطقتكم حاليًا.');

    $request->refresh();

    expect($request->status)->toBe('declined')
        ->and($request->decline_reason_ar)->toContain('متعاقد')
        ->and(AppNotification::where('recipient_id', $user->id)
            ->where('template_key', 'health_path_declined')->exists())->toBeTrue();

    // And the household reads the reason on their own page.
    $html = Livewire\Livewire::actingAs($user)->test(FamilyPortal::class)->html();

    expect($html)->toContain('لا يوجد مخبر متعاقد');
});

it('opens the card screen to the association, which could not reach it at all', function () {
    /*
     | Before T-145 this asked for `verify_referral`, a key only a service
     | provider holds — and a provider cannot open this panel. So no account
     | in the association could issue a referral card.
     */
    expect(userWithRole('case_officer')->can_('issue_referral'))->toBeTrue()
        ->and(userWithRole('executive_director')->can_('issue_referral'))->toBeTrue()
        ->and(userWithRole('service_provider')->can_('issue_referral'))->toBeFalse()
        // And the provider keeps the key for redeeming one at their counter.
        ->and(userWithRole('service_provider')->can_('verify_referral'))->toBeTrue();

    expect(userWithRole('case_officer')->can('viewAny', Referral::class))->toBeTrue();
})->group('AUTH-01');

it('shows the askings in the panel and answers them from there', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();

    [$family] = householdWithAccount();
    $request = app(HealthPathService::class)->apply($family, 'doctor', 'باطنية');
    providerOfType('doctor');

    Livewire\Livewire::actingAs(userWithRole('case_officer'))
        ->test(ReferralRequestResource\Pages\ListReferralRequests::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$request])
        ->assertTableActionVisible('issue', $request)
        ->assertTableActionVisible('decline', $request);
})->group('AUTH-01');

it('keeps the asking out of the hands of an account that cannot answer it', function () {
    [$family] = householdWithAccount();
    $request = app(HealthPathService::class)->apply($family, 'doctor');

    $delegate = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);

    // A delegate reads what their household asked for and does not answer it.
    expect($delegate->can('view', $request))->toBeTrue()
        ->and($delegate->can('handle', $request))->toBeFalse()
        ->and(userWithRole('donor')->can('viewAny', ReferralRequest::class))->toBeFalse();
})->group('AUTH-02');
