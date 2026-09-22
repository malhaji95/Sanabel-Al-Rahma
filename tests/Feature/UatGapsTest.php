<?php

use App\Models\Beneficiary;
use App\Models\Income;
use App\Models\RegionRate;
use App\Services\AssessmentService;
use App\Services\CaseService;
use App\Services\NeedEngine;
use App\Services\PermissionService;
use App\Services\ReferenceImporter;

beforeEach(function () {
    seedCore();
});

it('S04 — a supervisor sees only their own region', function () {
    $mine = regionWithRates();
    $other = regionWithRates();

    $inScope = familyOf($mine);
    $outOfScope = familyOf($other);

    $supervisor = userWithRole('area_supervisor', ['region_id' => $mine->id]);
    $this->actingAs($supervisor);

    $visible = Beneficiary::query()->pluck('id');

    expect($visible)->toContain($inScope->id)
        ->and($visible)->not->toContain($outOfScope->id)
        ->and(app(PermissionService::class)->coversRegion($supervisor, $other->id))->toBeFalse();
});

it('S12 — an unknown region is skipped, the rest of the import still lands', function () {
    $region = regionWithRates();

    $csv = "\xEF\xBB\xBF".'region_name_ar,person_class,amount,effective_from'."\n"
        .'"'.$region->name_ar.'",adult,7777,'.now()->toDateString()."\n"
        .'"منطقة غير مسجلة",adult,8888,'.now()->toDateString()."\n";

    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($path, $csv);
    $result = app(ReferenceImporter::class)->importRates($path);
    unlink($path);

    expect($result['imported'])->toBe(1)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0])->toContain('unknown region');

    expect(RegionRate::withoutGlobalScopes()->where('amount', 8888)->exists())->toBeFalse();
});

it('S15/S16 — stable income is deducted, unstable income is not', function () {
    $region = regionWithRates();
    $family = familyOf($region, adults: 2, children: 3);

    $need = app(NeedEngine::class)->compute($family)['monthly_need'];

    Income::factory()->create([
        'beneficiary_id' => $family->id, 'amount' => 20_000, 'is_stable' => false,
    ]);
    $withUnstable = app(NeedEngine::class)->compute($family->refresh());
    expect($withUnstable['gap'])->toBe($need);

    Income::factory()->create([
        'beneficiary_id' => $family->id, 'amount' => 20_000, 'is_stable' => true,
    ]);
    $withStable = app(NeedEngine::class)->compute($family->refresh());
    expect($withStable['gap'])->toBe(max(0, $need - 20_000))
        ->and($withStable['gap'])->toBeLessThan($need);
});

it('S24 — the person who created a case cannot approve or reject it', function () {
    $region = regionWithRates();
    $officer = userWithRole('case_officer');
    $admin = userWithRole('admin');

    $case = familyOf($region, attributes: ['created_by' => $officer->id, 'status' => 'submitted']);

    expect(fn () => app(CaseService::class)->approve($case, $officer))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(CaseService::class)->reject($case, $officer, 'سبب'))
        ->toThrow(RuntimeException::class);

    expect(app(CaseService::class)->approve($case, $admin)->status)->toBe('approved');
});

it('S25 — rejection without a reason is refused, and the reason is stored', function () {
    $region = regionWithRates();
    $admin = userWithRole('admin');
    $case = familyOf($region, attributes: ['status' => 'submitted']);

    expect(fn () => app(CaseService::class)->reject($case, $admin, '  '))
        ->toThrow(RuntimeException::class);

    $rejected = app(CaseService::class)->reject($case, $admin, 'المستندات غير مكتملة');

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->reject_reason_ar)->toBe('المستندات غير مكتملة');
});

it('S26 — a case can only be published once it is approved', function () {
    $region = regionWithRates();
    $admin = userWithRole('admin');
    $case = familyOf($region, attributes: ['status' => 'submitted']);

    expect(fn () => app(CaseService::class)->publish($case, $admin))
        ->toThrow(RuntimeException::class);

    $approved = app(CaseService::class)->approve($case, $admin);

    expect(app(CaseService::class)->publish($approved, $admin)->status)->toBe('published');
});

it('S27/S28 — a post-approval edit goes to review, and a material change recomputes', function () {
    $region = regionWithRates();
    $officer = userWithRole('case_officer');
    $admin = userWithRole('admin');

    $case = publishedCase($region);
    $before = $case->assessments()->count();

    $request = app(CaseService::class)->requestChange(
        $case, $officer, ['support_type' => 'one_time'], 'تغيّر وضع الأسرة'
    );

    // The edit is not applied until it is reviewed.
    expect($request->status)->toBe('pending')
        ->and($case->fresh()->support_type)->toBe('monthly');   // unchanged

    app(CaseService::class)->approveChange($request, $admin);

    expect($case->fresh()->support_type)->toBe('one_time')
        ->and($case->fresh()->assessments()->count())->toBeGreaterThan($before);
});

it('S60 — a provider sees a referral card and no family file', function () {
    $provider = userWithRole('service_provider');
    $region = regionWithRates();
    $case = publishedCase($region);

    $perms = app(PermissionService::class);

    foreach (['view_full_case', 'create_case', 'edit_draft', 'search_by_national_id'] as $denied) {
        expect($perms->has($provider, $denied))->toBeFalse();
    }

    expect($perms->has($provider, 'verify_referral'))->toBeTrue()
        ->and($provider->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeFalse();
});

it('S63 — the dashboard figures match the data', function () {
    $region = regionWithRates();
    foreach (range(1, 4) as $ignored) {
        publishedCase($region);
    }
    familyOf($region, attributes: ['status' => 'submitted']);

    expect(Beneficiary::published()->count())->toBe(4)
        ->and(Beneficiary::where('status', 'submitted')->count())->toBe(1);
});

it('starts the reassessment clock on the path the panel actually uses', function () {
    // The rule 10 test set next_assessment_due_at by hand, so it stayed green
    // while nothing in the application ever wrote that column: the panel's
    // recompute action calls create(status: 'approved'), and only approve(),
    // which nothing calls, scheduled the reassessment.
    $case = publishedCase(regionWithRates());

    $case->forceFill(['next_assessment_due_at' => null, 'last_assessment_at' => null])->save();

    app(AssessmentService::class)->create($case->refresh(), status: 'approved');

    $case->refresh();

    expect($case->next_assessment_due_at)->not->toBeNull()
        ->and($case->last_assessment_at)->not->toBeNull()
        ->and($case->next_assessment_due_at->isFuture())->toBeTrue();

    // And once it falls due, the case is flagged without a hand-written date.
    $case->forceFill(['next_assessment_due_at' => now()->subDay()])->save();

    expect(app(CaseService::class)->flagOverdueReassessments())->toBe(1)
        ->and($case->fresh()->status)->toBe('needs_reassessment');
});
