<?php

use App\Models\Campaign;
use App\Models\Complaint;
use App\Models\Distribution;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Provider;
use App\Models\Referral;
use Database\Seeders\RegionSeeder;
use Filament\Facades\Filament;

/*
 | Every internal screen is loaded as a real user. A page that throws on mount
 | is a broken screen, and this is what catches it.
 */

beforeEach(function () {
    seedCore();
    (new RegionSeeder)->run();
    $this->region = regionWithRates();

    // The 2FA gate is SecurityTest's subject; here it is a precondition.
    passTwoFactor();
});

it('loads every admin resource list page', function (string $resource) {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs(userWithRole('admin'))
        ->get($resource::getUrl('index'))
        ->assertSuccessful();
})->with([
    App\Filament\Resources\BeneficiaryResource::class,
    App\Filament\Resources\DonationResource::class,
    App\Filament\Resources\CampaignResource::class,
    App\Filament\Resources\SponsorshipResource::class,
    App\Filament\Resources\DistributionResource::class,
    App\Filament\Resources\ChangeRequestResource::class,
    App\Filament\Resources\MemberResource::class,
    App\Filament\Resources\ProviderResource::class,
    App\Filament\Resources\ReferralResource::class,
    App\Filament\Resources\JobProfileResource::class,
    App\Filament\Resources\ComplaintResource::class,
    App\Filament\Resources\RegionResource::class,
    App\Filament\Resources\RegionRateResource::class,
    App\Filament\Resources\RegionRentReferenceResource::class,
    App\Filament\Resources\AdjustmentCatalogResource::class,
    App\Filament\Resources\ScoringWeightResource::class,
    App\Filament\Resources\PageResource::class,
    App\Filament\Resources\PostResource::class,
    App\Filament\Resources\BannerResource::class,
    App\Filament\Resources\UserResource::class,
]);

it('loads every admin resource create page', function (string $resource) {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs(userWithRole('admin'))
        ->get($resource::getUrl('create'))
        ->assertSuccessful();
})->with([
    App\Filament\Resources\BeneficiaryResource::class,
    App\Filament\Resources\DonationResource::class,
    App\Filament\Resources\CampaignResource::class,
    App\Filament\Resources\SponsorshipResource::class,
    App\Filament\Resources\DistributionResource::class,
    App\Filament\Resources\ProviderResource::class,
    App\Filament\Resources\ReferralResource::class,
    App\Filament\Resources\ComplaintResource::class,
    App\Filament\Resources\RegionRateResource::class,
    App\Filament\Resources\UserResource::class,
]);

it('loads the admin dashboard with its widgets', function () {
    publishedCase($this->region);
    Donation::factory()->create();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs(userWithRole('admin'))
        ->get(Filament::getPanel('admin')->getUrl())
        ->assertSuccessful();

    Livewire\Livewire::actingAs(userWithRole('admin'))
        ->test(App\Filament\Widgets\OverviewStats::class)
        ->assertSuccessful();

    Livewire\Livewire::actingAs(userWithRole('admin'))
        ->test(App\Filament\Widgets\CoverageByRegion::class)
        ->assertSuccessful();
});

it('loads the settings page and saves a value', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire\Livewire::actingAs(userWithRole('admin'))
        ->test(App\Filament\Pages\ManageSettings::class)
        ->assertSuccessful()
        ->fillForm(['basket_hold_hours' => 12])
        ->call('save');

    expect(App\Models\Setting::value('basket_hold_hours'))->toBe(12);
});

it('keeps the settings page away from roles without edit_config', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs(userWithRole('council'));

    expect(App\Filament\Pages\ManageSettings::canAccess())->toBeFalse();

    $this->actingAs(userWithRole('admin'));

    expect(App\Filament\Pages\ManageSettings::canAccess())->toBeTrue();
});

it('loads an edit page for each record-bearing resource', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = userWithRole('admin');

    $case = publishedCase($this->region);
    $campaign = Campaign::factory()->create();
    $distribution = Distribution::factory()->create(['region_id' => $this->region->id]);
    $complaint = Complaint::factory()->create();
    $donation = Donation::factory()->create();

    $this->actingAs($admin);

    $this->get(App\Filament\Resources\BeneficiaryResource::getUrl('edit', [$case]))->assertSuccessful();
    $this->get(App\Filament\Resources\CampaignResource::getUrl('edit', [$campaign]))->assertSuccessful();
    $this->get(App\Filament\Resources\DistributionResource::getUrl('edit', [$distribution]))->assertSuccessful();
    $this->get(App\Filament\Resources\ComplaintResource::getUrl('edit', [$complaint]))->assertSuccessful();
    // A pending donation is still editable; a verified one is not.
    $this->get(App\Filament\Resources\DonationResource::getUrl('edit', [$donation]))->assertSuccessful();
});

it('loads the association panel and its coordination lookup', function () {
    Filament::setCurrentPanel(Filament::getPanel('association'));
    $association = userWithRole('association', ['region_id' => $this->region->id]);

    $case = publishedCase($this->region);
    $case->forceFill(['created_by' => $association->id])->save();

    $this->actingAs($association)
        ->get(App\Filament\Association\Resources\CaseResource::getUrl('index'))
        ->assertSuccessful();

    Livewire\Livewire::actingAs($association)
        ->test(App\Filament\Association\Pages\CoordinationLookup::class)
        ->assertSuccessful()
        ->fillForm(['national_id' => $case->national_id_encrypted])
        ->call('lookup')
        ->assertSet('result.registered', true);
});

it('loads the provider panel and verifies a card through it', function () {
    Filament::setCurrentPanel(Filament::getPanel('provider'));

    $user = userWithRole('service_provider');
    $provider = Provider::factory()->create(['user_id' => $user->id, 'region_id' => $this->region->id]);
    $case = publishedCase($this->region);
    $referral = Referral::factory()->create([
        'beneficiary_id' => $case->id,
        'provider_id' => $provider->id,
    ]);

    $this->actingAs($user)
        ->get(App\Filament\Provider\Resources\OfferResource::getUrl('index'))
        ->assertSuccessful();

    Livewire\Livewire::actingAs($user)
        ->test(App\Filament\Provider\Pages\VerifyCard::class)
        ->assertSuccessful()
        ->fillForm(['code' => $referral->code])
        ->call('verify')
        ->assertSet('card.file_number', $case->file_number)
        ->assertSet('card.valid', true);
});

it('lets only the right roles reach each panel', function () {
    $admin = Filament::getPanel('admin');
    $association = Filament::getPanel('association');
    $provider = Filament::getPanel('provider');

    expect(userWithRole('admin')->canAccessPanel($admin))->toBeTrue()
        ->and(userWithRole('council')->canAccessPanel($admin))->toBeTrue()
        ->and(userWithRole('donor')->canAccessPanel($admin))->toBeFalse()
        ->and(userWithRole('association')->canAccessPanel($admin))->toBeFalse()
        ->and(userWithRole('association')->canAccessPanel($association))->toBeTrue()
        ->and(userWithRole('delegate')->canAccessPanel($association))->toBeFalse()
        ->and(userWithRole('service_provider')->canAccessPanel($provider))->toBeTrue()
        ->and(userWithRole('donor')->canAccessPanel($provider))->toBeFalse()
        // A deactivated account reaches nothing.
        ->and(userWithRole('admin', ['is_active' => false])->canAccessPanel($admin))->toBeFalse();
});
