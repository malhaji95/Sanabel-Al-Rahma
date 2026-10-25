<?php

use App\Http\Resources\CoordinationLookupResource;
use App\Http\Resources\ReferralCardResource;
use App\Models\Beneficiary;
use App\Models\Donation;
use App\Models\JobProfile;
use App\Models\Post;
use App\Models\Provider;
use App\Models\Referral;
use App\Models\Region;
use App\Models\User;
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
])->group('AUTH-01');

it('denies council every write permission and allows it read permissions', function () {
    $council = userWithRole('council');
    $permissions = app(PermissionService::class);

    foreach (PermissionService::WRITE_PERMISSIONS as $key) {
        expect($permissions->has($council, $key))->toBeFalse("council should not hold '$key'");
    }

    expect($permissions->has($council, 'view_full_case'))->toBeTrue()
        ->and($permissions->has($council, 'view_reports'))->toBeTrue()
        ->and($council->isReadOnly())->toBeTrue();
})->group('AUTH-01');

it('opens to an association its own files and no other body files', function () {
    $association = userWithRole('association', ['region_id' => $this->region->id]);
    $otherAssociation = userWithRole('association', ['region_id' => $this->region->id]);

    $ownCase = publishedCase($this->region);
    $ownCase->forceFill(['created_by' => $association->id])->save();

    /*
     | T-144 — this used to assert that a file another body registered in the
     | same city opened too, on the decision of 30 Sep 2026. The association
     | read that screen on 28 October and asked why it was showing them
     | families they had never touched, and docs/04-permissions.md rule 3 had
     | the answer all along: its own and referred cases only. The city-wide
     | awareness is the coordination lookup, which carries no name.
     */
    $sameCity = publishedCase($this->region);
    $sameCity->forceFill(['created_by' => $otherAssociation->id])->save();

    $otherCity = publishedCase(regionWithRates());

    $this->actingAs($association, 'sanctum')->getJson(route('cases.show', $ownCase))->assertOk();
    $this->actingAs($association, 'sanctum')->getJson(route('cases.show', $sameCity))->assertForbidden();
    // Not found rather than forbidden: the region scope removes the row from
    // the query, so the association cannot even learn the file exists.
    $this->actingAs($association, 'sanctum')->getJson(route('cases.show', $otherCity))->assertNotFound();
})->group('AUTH-02');

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
})->group('AUTH-02');

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
})->group('AUTH-02');

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
})->group('AUTH-02');

it('stops the creator from being the final approver', function () {
    // approve_case is the executive director's, not the case officer's.
    $officer = userWithRole('executive_director');
    $second = userWithRole('executive_director');

    $case = familyOf($this->region, attributes: ['status' => 'pending_approval']);
    $case->forceFill(['created_by' => $officer->id])->save();

    // The officer who created the file cannot approve it, holding the
    // permission and all. It is the person the rule is about, not the role.
    expect($officer->can('approve', $case))->toBeFalse();

    expect(fn () => app(CaseService::class)->approve($case, $officer))
        ->toThrow(RuntimeException::class, __('sanabel.cases.self_approval_blocked'));

    // Another officer, who did not create it, can.
    expect($second->can('approve', $case->refresh()))->toBeTrue()
        ->and(app(CaseService::class)->approve($case, $second)->status)->toBe('approved');
})->group('APP-01');

it('scopes a delegate to their own village, and to nothing wider', function () {
    // T-74 — a delegate sees their village. Their own area, and the village
    // beside theirs in it, are both outside their scope now.
    $area = Region::factory()->create(['parent_id' => $this->region->id]);
    $village = Region::factory()->village()->create(['parent_id' => $area->id]);
    $nextVillage = Region::factory()->village()->create(['parent_id' => $area->id]);

    $delegate = userWithRole('delegate', ['region_id' => $village->id]);

    $inScope = familyOf($village);
    $outOfScope = familyOf($nextVillage);

    $this->actingAs($delegate, 'sanctum');

    $ids = Beneficiary::pluck('id');

    expect($ids)->toContain($inScope->id)
        ->and($ids)->not->toContain($outOfScope->id);
})->group('AUTH-02');

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
})->group('OPS-02');

it('gives the finance role the payment queue and nothing about the family', function () {
    $finance = userWithRole('finance');

    expect($finance->can('viewAny', Donation::class))->toBeTrue()
        ->and($finance->can('viewAny', Beneficiary::class))->toBeFalse()
        ->and($finance->can('viewAny', User::class))->toBeFalse();
})->group('AUTH-05');

it('lets the content manager run the site without opening a family file', function () {
    $content = userWithRole('content_manager');

    expect($content->can('viewAny', Post::class))->toBeTrue()
        ->and($content->can('create', Post::class))->toBeTrue()
        // The whole point of the role: managing content is not a way in.
        ->and($content->can('viewAny', Beneficiary::class))->toBeFalse()
        ->and($content->can('viewAny', Donation::class))->toBeFalse();
})->group('AUTH-01');

it('does not treat the user list as a report', function () {
    // view_reports is held by almost every staff role; it must not double as a
    // key to every account's email and role.
    foreach (['delegate', 'area_supervisor', 'case_officer', 'association'] as $roleKey) {
        expect(userWithRole($roleKey)->can('viewAny', User::class))
            ->toBeFalse("{$roleKey} can list every user");
    }

    expect(userWithRole('admin')->can('viewAny', User::class))->toBeTrue();
})->group('AUTH-01');
