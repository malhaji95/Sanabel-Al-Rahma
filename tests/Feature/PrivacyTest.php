<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Donor;
use App\Models\HouseholdMember;
use App\Models\JobProfile;
use App\Services\BasketService;
use App\Services\DonationService;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** Everything about a family that must never reach a donor. */
function identifyingValues(Beneficiary $case): array
{
    $case->refresh()->loadMissing('members', 'housing', 'healthRecords');

    return array_values(array_filter([
        $case->first_name,
        $case->father_name,
        $case->family_name,
        $case->national_id_encrypted,
        $case->national_id_hash,
        $case->phone_encrypted,
        $case->wallet_encrypted,
        $case->housing?->landlord_name_ar,
        $case->housing?->landlord_phone_encrypted,
        (string) $case->housing?->monthly_rent,
        (string) $case->currentAssessment()?->base_score,
        ...$case->members->pluck('name_ar')->all(),
        ...$case->members->pluck('birth_year')->map(fn ($y) => (string) $y)->all(),
        ...$case->healthRecords->pluck('description_ar')->all(),
    ], fn ($v) => filled($v) && $v !== '0'));
}

function assertNoLeak(string $body, Beneficiary $case): void
{
    foreach (identifyingValues($case) as $secret) {
        expect($body)->not->toContain((string) $secret);
    }
}

function donorUser(): App\Models\User
{
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id]);

    return $user;
}

/** A case with every identifying field populated, so a leak has something to leak. */
function fullCase(App\Models\Region $region): Beneficiary
{
    $case = publishedCase($region);
    $case->update([
        'wallet_encrypted' => '0912345678',
        'urgency_deadline_at' => now()->addDays(3),
    ]);
    $case->housing->update([
        'housing_type' => 'rent',
        'monthly_rent' => 37_531,
        'landlord_name_ar' => 'أبو المالك الخاص',
        'landlord_phone_encrypted' => '0999888777',
    ]);
    App\Models\HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'description_ar' => 'تشخيص سري للغاية',
        'severity_band' => 75,
    ]);
    HouseholdMember::factory()->child()->create([
        'beneficiary_id' => $case->id,
        'name_ar' => 'طفل سري الاسم',
        'birth_year' => 2019,
    ]);

    return $case->refresh();
}

it('leaks nothing identifying on the donor case list route', function () {
    $case = fullCase($this->region);

    $response = $this->actingAs(donorUser(), 'sanctum')->getJson(route('donor.cases.index', 'monthly'));

    $response->assertOk();
    assertNoLeak($response->getContent(), $case);
    expect(array_keys($response->json('data.0')))
        ->toEqualCanonicalizing(MaskedCaseResource::ALLOWED_KEYS);
});

it('leaks nothing identifying on the donor single case route', function () {
    $case = fullCase($this->region);

    $response = $this->actingAs(donorUser(), 'sanctum')->getJson(route('donor.cases.show', $case));

    $response->assertOk();
    assertNoLeak($response->getContent(), $case);
    expect(array_keys($response->json('data')))->toEqualCanonicalizing(MaskedCaseResource::ALLOWED_KEYS);
});

it('leaks nothing identifying on the donor basket route', function () {
    $case = fullCase($this->region);
    $user = donorUser();

    $this->actingAs($user, 'sanctum')
        ->postJson(route('donor.basket.add'), ['beneficiary_id' => $case->id, 'amount' => 1_000])
        ->assertOk();

    $response = $this->actingAs($user, 'sanctum')->getJson(route('donor.basket.show'));

    $response->assertOk();
    assertNoLeak($response->getContent(), $case);
    expect(array_keys($response->json('items.0.case')))
        ->toEqualCanonicalizing(MaskedCaseResource::ALLOWED_KEYS);
});

it('leaks nothing identifying on the donor my-donations route', function () {
    $case = fullCase($this->region);
    $user = donorUser();

    $this->actingAs($user, 'sanctum')
        ->postJson(route('donor.basket.add'), ['beneficiary_id' => $case->id, 'amount' => 1_000]);
    $this->actingAs($user, 'sanctum')->postJson(route('donor.basket.reserve'));

    $basket = $user->donor->baskets()->first();
    $donation = app(DonationService::class)->record([
        'donor_id' => $user->donor->id,
        'amount' => 1_000,
        'transaction_ref' => 'TRX-LEAK-TEST',
        'basket_id' => $basket->id,
    ]);
    app(DonationService::class)->verify($donation, userWithRole('admin')->id);

    $response = $this->actingAs($user, 'sanctum')->getJson(route('donor.donations.index'));

    $response->assertOk();
    assertNoLeak($response->getContent(), $case);
});

it('leaks nothing identifying on the donor campaign routes', function () {
    $case = fullCase($this->region);
    $campaign = Campaign::factory()->create([
        'beneficiary_id' => $case->id,
        'surplus_policy_text_ar' => 'يوجه الفائض لأسرة أخرى ضمن نفس المنطقة.',
        'is_published' => true,
    ]);

    $index = $this->actingAs(donorUser(), 'sanctum')->getJson(route('donor.campaigns.index'));
    $show = $this->actingAs(donorUser(), 'sanctum')->getJson(route('donor.campaigns.show', $campaign));

    $index->assertOk();
    $show->assertOk();
    assertNoLeak($index->getContent(), $case);
    assertNoLeak($show->getContent(), $case);
    expect(array_keys($show->json('data.case')))->toEqualCanonicalizing(MaskedCaseResource::ALLOWED_KEYS);
});

it('leaks nothing identifying on the donor job market route', function () {
    $case = fullCase($this->region);
    JobProfile::factory()->create([
        'beneficiary_id' => $case->id,
        'region_id' => $case->region_id,
        'status' => 'published',
    ]);

    $response = $this->actingAs(donorUser(), 'sanctum')->getJson(route('donor.jobs.index'));

    $response->assertOk();
    assertNoLeak($response->getContent(), $case);
});

it('never publishes anything identifying a child', function () {
    $case = fullCase($this->region);
    $child = $case->members()->where('person_class', 'child')->first();

    $response = $this->actingAs(donorUser(), 'sanctum')->getJson(route('donor.cases.show', $case));
    $body = $response->getContent();

    expect($body)->not->toContain($child->name_ar)
        ->and($body)->not->toContain((string) $child->birth_year)
        // Children appear only as a count inside an age band.
        ->and($response->json('data.age_bands.child'))->toBeGreaterThan(0);
});

it('gives the donor a band and a label instead of the exact rent, diagnosis and score', function () {
    $case = fullCase($this->region);

    $data = $this->actingAs(donorUser(), 'sanctum')
        ->getJson(route('donor.cases.show', $case))
        ->json('data');

    expect($data['rent_band'])->toBeString()
        ->and($data['rent_band'])->not->toContain('37')
        ->and($data['has_chronic_illness'])->toBeTrue()
        ->and($data)->not->toHaveKey('base_score')
        ->and($data['urgency_label'])->toBe(__('sanabel.masked.urgency.high'));
});

it('refuses a donor on every internal full-case route', function () {
    $case = fullCase($this->region);
    $donor = donorUser();

    $this->actingAs($donor, 'sanctum')->getJson(route('cases.index'))->assertForbidden();
    $this->actingAs($donor, 'sanctum')->getJson(route('cases.show', $case))->assertForbidden();
    $this->actingAs($donor, 'sanctum')->postJson(route('coordination.lookup'), [
        'national_id' => '12345678901',
    ])->assertForbidden();
});
