<?php

use App\Http\Resources\CoordinationLookupResource;
use App\Http\Resources\ReferralCardResource;
use App\Models\Beneficiary;
use App\Models\Donation;
use App\Models\JobProfile;
use App\Models\Provider;
use App\Models\Referral;
use App\Models\Region;
use App\Services\CaseService;
use App\Services\PermissionService;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('rejects council on every write route', function (string $method, string $route, array $payload) {
    $council = userWithRole('council');
    $case = publishedCase($this->region);
    $donation = Donation::factory()->create();
    $profile = JobProfile::factory()->create([
        'beneficiary_id' => $case->id,
        'region_id' => $case->region_id,
        'status' => 'published',
    ]);

    $url = str_replace(
        ['{case}', '{donation}', '{profile}'],
        [$case->id, $donation->id, $profile->id],
        $route,
    );

    $this->actingAs($council, 'sanctum')
        ->json($method, $url, $payload)
        ->assertForbidden();
})->with([
    'create case' => ['POST', '/api/cases', [
        'national_id' => '11122233344', 'first_name' => 'أ', 'father_name' => 'ب',
        'family_name' => 'ج', 'region_id' => 1, 'support_type' => 'monthly',
    ]],
    'approve case' => ['POST', '/api/cases/{case}/approve', []],
    'reject case' => ['POST', '/api/cases/{case}/reject', ['reason_ar' => 'سبب']],
    'publish case' => ['POST', '/api/cases/{case}/publish', []],
    'request change' => ['POST', '/api/cases/{case}/change-requests', [
        'payload' => ['support_type' => 'one_time'], 'reason_ar' => 'سبب',
    ]],
    'confirm delivery' => ['POST', '/api/cases/{case}/deliveries', [
        'type' => 'cash', 'proof_media_id' => 1,
    ]],
    'record donation' => ['POST', '/api/donor/donations', [
        'amount' => 1000, 'transaction_ref' => 'TRX-COUNCIL',
    ]],
    'verify donation' => ['POST', '/api/donations/{donation}/verify', []],
    'reject donation' => ['POST', '/api/donations/{donation}/reject', ['reason' => 'سبب']],
    'sync visits' => ['POST', '/api/visits/sync', ['visits' => []]],
    'redeem referral' => ['POST', '/api/referrals/ABC123/redeem', ['proof_media_id' => 1]],
    'request job contact' => ['POST', '/api/donor/jobs/{profile}/contact', [
        'requester_name_ar' => 'أ', 'contact' => '0999',
    ]],
]);

it('denies council every write permission and allows it read permissions', function () {
    $council = userWithRole('council');
    $permissions = app(PermissionService::class);

    foreach (PermissionService::WRITE_PERMISSIONS as $key) {
        expect($permissions->has($council, $key))->toBeFalse("council should not hold '$key'");
    }

    expect($permissions->has($council, 'view_full_case'))->toBeTrue()
        ->and($permissions->has($council, 'view_reports'))->toBeTrue()
        ->and($council->isReadOnly())->toBeTrue();
});

it('stops an association from opening an out-of-scope case', function () {
    $association = userWithRole('association', ['region_id' => $this->region->id]);
    $otherAssociation = userWithRole('association', ['region_id' => $this->region->id]);

    $ownCase = publishedCase($this->region);
    $ownCase->forceFill(['created_by' => $association->id])->save();

    $foreignCase = publishedCase($this->region);
    $foreignCase->forceFill(['created_by' => $otherAssociation->id])->save();

    $this->actingAs($association, 'sanctum')->getJson(route('cases.show', $ownCase))->assertOk();
    $this->actingAs($association, 'sanctum')->getJson(route('cases.show', $foreignCase))->assertForbidden();
});

it('returns four values only from the coordination lookup', function () {
    $association = userWithRole('association', ['region_id' => $this->region->id]);
    $case = publishedCase($this->region);
    $nationalId = $case->national_id_encrypted;

    $response = $this->actingAs($association, 'sanctum')
        ->postJson(route('coordination.lookup'), ['national_id' => $nationalId]);

    $response->assertOk();

    expect(array_keys($response->json('data')))
        ->toEqualCanonicalizing(CoordinationLookupResource::ALLOWED_KEYS)
        ->and($response->json('data.registered'))->toBeTrue()
        ->and($response->json('data.has_active_assessment'))->toBeTrue();

    // Nothing about who the family is comes back.
    $body = $response->getContent();
    expect($body)->not->toContain($case->first_name)
        ->and($body)->not->toContain($case->family_name)
        ->and($body)->not->toContain($case->file_number);
});

it('shows a provider only the file number, validity and discount type', function () {
    $providerUser = userWithRole('service_provider');
    $provider = Provider::factory()->create([
        'user_id' => $providerUser->id,
        'region_id' => $this->region->id,
    ]);
    $case = publishedCase($this->region);
    $referral = Referral::factory()->create([
        'beneficiary_id' => $case->id,
        'provider_id' => $provider->id,
    ]);

    $response = $this->actingAs($providerUser, 'sanctum')
        ->getJson(route('referrals.verify', $referral->code));

    $response->assertOk();

    expect(array_keys($response->json('data')))
        ->toEqualCanonicalizing(ReferralCardResource::ALLOWED_KEYS);

    $body = $response->getContent();
    expect($body)->not->toContain($case->first_name)
        ->and($body)->not->toContain($case->family_name)
        ->and($body)->not->toContain((string) $case->national_id_encrypted);
});

it('stops a provider from reading a referral issued to another provider', function () {
    $mine = userWithRole('service_provider');
    Provider::factory()->create(['user_id' => $mine->id, 'region_id' => $this->region->id]);

    $theirs = Provider::factory()->create([
        'user_id' => userWithRole('service_provider')->id,
        'region_id' => $this->region->id,
    ]);
    $referral = Referral::factory()->create([
        'beneficiary_id' => publishedCase($this->region)->id,
        'provider_id' => $theirs->id,
    ]);

    $this->actingAs($mine, 'sanctum')
        ->getJson(route('referrals.verify', $referral->code))
        ->assertForbidden();
});

it('stops the creator from being the final approver', function () {
    $officer = userWithRole('case_officer');
    $admin = userWithRole('admin');

    $case = familyOf($this->region, attributes: ['status' => 'pending_approval']);
    $case->forceFill(['created_by' => $admin->id])->save();

    // The admin who created the file cannot approve it, even holding the permission.
    expect($admin->can('approve', $case))->toBeFalse();

    expect(fn () => app(CaseService::class)->approve($case, $admin))
        ->toThrow(RuntimeException::class, __('sanabel.cases.self_approval_blocked'));

    $case->forceFill(['created_by' => $officer->id])->save();

    expect($admin->can('approve', $case->refresh()))->toBeTrue()
        ->and(app(CaseService::class)->approve($case, $admin)->status)->toBe('approved');
});

it('scopes a delegate to their own region subtree', function () {
    $area = Region::factory()->create(['parent_id' => $this->region->id]);
    $otherArea = Region::factory()->create(['parent_id' => $this->region->id]);

    $delegate = userWithRole('delegate', ['region_id' => $area->id]);

    $inScope = familyOf($area);
    $outOfScope = familyOf($otherArea);

    $this->actingAs($delegate, 'sanctum');

    $ids = Beneficiary::pluck('id');

    expect($ids)->toContain($inScope->id)
        ->and($ids)->not->toContain($outOfScope->id);
});

it('never hard-deletes a case or a donation', function () {
    $admin = userWithRole('admin');
    $case = publishedCase($this->region);
    $donation = Donation::factory()->create();

    expect($admin->can('delete', $case))->toBeFalse()
        ->and($admin->can('forceDelete', $case))->toBeFalse()
        ->and($admin->can('delete', $donation))->toBeFalse()
        ->and($admin->can('forceDelete', $donation))->toBeFalse();

    $case->delete();

    expect(Beneficiary::withTrashed()->find($case->id))->not->toBeNull()
        ->and(Beneficiary::withTrashed()->find($case->id)->deleted_at)->not->toBeNull();
});
