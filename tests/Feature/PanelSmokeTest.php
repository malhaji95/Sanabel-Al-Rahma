<?php

use App\Filament\Association\Pages\CoordinationLookup;
use App\Filament\Association\Resources\CaseResource;
use App\Filament\Pages\ManageSettings;
use App\Filament\Provider\Pages\VerifyCard;
use App\Filament\Provider\Resources\OfferResource;
use App\Filament\Resources\AdjustmentCatalogResource;
use App\Filament\Resources\BannerResource;
use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\ChangeRequestResource;
use App\Filament\Resources\ComplaintResource;
use App\Filament\Resources\DistributionResource;
use App\Filament\Resources\DonationResource;
use App\Filament\Resources\JobProfileResource;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PostResource;
use App\Filament\Resources\ProviderResource;
use App\Filament\Resources\ReferralResource;
use App\Filament\Resources\RegionRateResource;
use App\Filament\Resources\RegionRentReferenceResource;
use App\Filament\Resources\RegionResource;
use App\Filament\Resources\ScoringWeightResource;
use App\Filament\Resources\SponsorshipResource;
use App\Filament\Resources\UserResource;
use App\Filament\Widgets\CoverageByRegion;
use App\Filament\Widgets\OverviewStats;
use App\Models\Campaign;
use App\Models\Complaint;
use App\Models\Distribution;
use App\Models\Donation;
use App\Models\Provider;
use App\Models\Referral;
use App\Models\Setting;
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
    BeneficiaryResource::class,
    DonationResource::class,
    CampaignResource::class,
    SponsorshipResource::class,
    DistributionResource::class,
    ChangeRequestResource::class,
    MemberResource::class,
    ProviderResource::class,
    ReferralResource::class,
    JobProfileResource::class,
    ComplaintResource::class,
    RegionResource::class,
    RegionRateResource::class,
    RegionRentReferenceResource::class,
    AdjustmentCatalogResource::class,
    ScoringWeightResource::class,
    PageResource::class,
    PostResource::class,
    BannerResource::class,
    UserResource::class,
]);

it('loads every admin resource create page', function (string $resource) {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs(userWithRole('admin'))
        ->get($resource::getUrl('create'))
        ->assertSuccessful();
})->with([
    BeneficiaryResource::class,
    DonationResource::class,
    CampaignResource::class,
    SponsorshipResource::class,
    DistributionResource::class,
    ProviderResource::class,
    ReferralResource::class,
    ComplaintResource::class,
    RegionRateResource::class,
    UserResource::class,
]);

it('loads the admin dashboard with its widgets', function () {
    publishedCase($this->region);
    Donation::factory()->create();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs(userWithRole('admin'))
        ->get(Filament::getPanel('admin')->getUrl())
        ->assertSuccessful();

    Livewire\Livewire::actingAs(userWithRole('admin'))
        ->test(OverviewStats::class)
        ->assertSuccessful();

    Livewire\Livewire::actingAs(userWithRole('admin'))
        ->test(CoverageByRegion::class)
        ->assertSuccessful();
});

it('loads the settings page and saves a value', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire\Livewire::actingAs(userWithRole('admin'))
        ->test(ManageSettings::class)
        ->assertSuccessful()
        ->fillForm(['basket_hold_hours' => 12])
        ->call('save');

    expect(Setting::value('basket_hold_hours'))->toBe(12);
});

it('keeps the settings page away from roles without edit_config', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs(userWithRole('council'));

    expect(ManageSettings::canAccess())->toBeFalse();

    $this->actingAs(userWithRole('admin'));

    expect(ManageSettings::canAccess())->toBeTrue();
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

    $this->get(BeneficiaryResource::getUrl('edit', [$case]))->assertSuccessful();
    $this->get(CampaignResource::getUrl('edit', [$campaign]))->assertSuccessful();
    $this->get(DistributionResource::getUrl('edit', [$distribution]))->assertSuccessful();
    $this->get(ComplaintResource::getUrl('edit', [$complaint]))->assertSuccessful();
    // A pending donation is still editable; a verified one is not.
    $this->get(DonationResource::getUrl('edit', [$donation]))->assertSuccessful();
});

it('loads the association panel and its coordination lookup', function () {
    Filament::setCurrentPanel(Filament::getPanel('association'));
    $association = userWithRole('association', ['region_id' => $this->region->id]);

    $case = publishedCase($this->region);
    $case->forceFill(['created_by' => $association->id])->save();

    $this->actingAs($association)
        ->get(CaseResource::getUrl('index'))
        ->assertSuccessful();

    Livewire\Livewire::actingAs($association)
        ->test(CoordinationLookup::class)
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
        ->get(OfferResource::getUrl('index'))
        ->assertSuccessful();

    Livewire\Livewire::actingAs($user)
        ->test(VerifyCard::class)
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
