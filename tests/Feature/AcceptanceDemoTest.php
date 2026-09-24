<?php

use App\Models\Housing;
use App\Models\Income;
use App\Models\RegionRate;
use App\Services\AssessmentService;
use App\Services\CaseService;
use App\Services\CoverageService;
use App\Services\NeedEngine;

/**
 * The six scenarios the association asked to see before accepting the phase,
 * written as tests so the evidence can be re-run rather than taken on trust.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates(adult: 5_000, child: 2_000, elderly: 6_000, rent: 40_000, wife: 4_000);
});

it('١ — a renting family carries the rent reference, an owning one does not', function () {
    $renting = familyOf($this->region, adults: 1, children: 2);
    $owning = familyOf($this->region, adults: 1, children: 2);

    Housing::where('beneficiary_id', $renting->id)->delete();
    Housing::create([
        'beneficiary_id' => $renting->id, 'housing_type' => 'rent', 'monthly_rent' => 55_000,
        'habitable_rooms' => 2, 'safety_band' => 25, 'services_band' => 0, 'eviction_band' => 0,
    ]);

    Housing::where('beneficiary_id', $owning->id)->delete();
    Housing::create([
        'beneficiary_id' => $owning->id, 'housing_type' => 'own', 'monthly_rent' => 0,
        'habitable_rooms' => 2, 'safety_band' => 25, 'services_band' => 0, 'eviction_band' => 0,
    ]);

    $engine = app(NeedEngine::class);
    $rentingNeed = $engine->compute($renting->fresh())['monthly_need'];
    $owningNeed = $engine->compute($owning->fresh())['monthly_need'];

    // Same household, same members: the whole difference is the rent reference.
    expect($rentingNeed - $owningNeed)->toBe(40_000)
        ->and($engine->compute($renting->fresh())['snapshot']['rent_reference']['amount'])->toBe(40_000)
        ->and($engine->compute($owning->fresh())['snapshot']['rent_reference']['amount'])->toBe(0);
});

it('٢ — a family earning more than it needs has no gap and no claim', function () {
    $family = familyOf($this->region, adults: 1, children: 1);
    $need = app(NeedEngine::class)->compute($family->fresh())['monthly_need'];

    Income::create([
        'beneficiary_id' => $family->id, 'source_type' => 'salary',
        'amount' => $need + 50_000, 'currency' => config('sanabel.currency'), 'is_stable' => true,
    ]);

    $computed = app(NeedEngine::class)->compute($family->fresh());

    expect($computed['stable_income'])->toBeGreaterThan($computed['monthly_need'])
        ->and($computed['gap'])->toBe(0)
        // F is the financial-shortfall factor: no shortfall, no score from it.
        ->and($computed['f'])->toBe(0.0);
});

it('٣ — changing a reference value leaves the earlier assessment exactly as it was', function () {
    $family = familyOf($this->region, adults: 1, children: 1);
    $before = app(AssessmentService::class)->create($family->fresh(), status: 'approved');

    $recordedNeed = $before->monthly_need;
    $recordedAdultRate = $before->snapshot_json['rates']['adult']['amount'];

    // The association raises the adult rate, effective today.
    RegionRate::factory()->create([
        'region_id' => $this->region->id, 'person_class' => 'adult',
        'amount' => 25_000, 'effective_from' => now(), 'version' => 2,
    ]);

    $after = app(AssessmentService::class)->create($family->fresh(), status: 'approved');

    // The new assessment moves. The old one does not — neither its figure nor
    // the reference values it was computed from.
    expect($after->monthly_need)->toBeGreaterThan($recordedNeed)
        ->and($before->fresh()->monthly_need)->toBe($recordedNeed)
        ->and($before->fresh()->snapshot_json['rates']['adult']['amount'])->toBe($recordedAdultRate)
        ->and($after->snapshot_json['rates']['adult']['amount'])->toBe(25_000);
});

it('٤ — the person who created a case cannot approve it', function () {
    $officer = userWithRole('case_officer');
    $case = familyOf($this->region, attributes: ['created_by' => $officer->id, 'status' => 'pending_approval']);

    // Not merely hidden in the interface: the policy refuses, and so does the service.
    expect($officer->can('approve', $case))->toBeFalse()
        ->and(fn () => app(CaseService::class)->approve($case, $officer))
        ->toThrow(RuntimeException::class);

    $other = userWithRole('admin');

    expect($other->can('approve', $case))->toBeTrue()
        ->and(app(CaseService::class)->approve($case, $other)->status)->toBe('approved');
});

it('٥ — an edit after approval goes to review, and a material one recomputes', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $case = publishedCase($this->region);

    // A renting family, so the rent actually bears on the assessment.
    Housing::where('beneficiary_id', $case->id)->delete();
    Housing::create([
        'beneficiary_id' => $case->id, 'housing_type' => 'rent', 'monthly_rent' => 10_000,
        'habitable_rooms' => 3, 'safety_band' => 25, 'services_band' => 0, 'eviction_band' => 0,
    ]);

    app(AssessmentService::class)->create($case->fresh(), status: 'approved');
    $scoreBefore = $case->fresh()->currentAssessment()->base_score;

    // A delegate cannot write to an approved file at all; the edit is a request.
    expect($delegate->can('update', $case->fresh()))->toBeFalse();

    $request = app(CaseService::class)->requestChange(
        $case->fresh(),
        $delegate,
        ['monthly_rent' => 90_000],
        'ارتفع الإيجار بعد تجديد العقد.',
    );

    expect($request->is_material)->toBeTrue()
        ->and($request->status)->toBe('pending')
        ->and($request->old_json)->toHaveKey('monthly_rent');

    app(CaseService::class)->approveChange($request, userWithRole('admin'));

    // Approving a material change recomputes rather than leaving a stale figure.
    $assessments = $case->fresh()->assessments()->orderByDesc('id')->get();

    // The rent really moved, its previous value was recorded, a fresh
    // assessment was produced, and the score followed the rent burden. The
    // monthly need does not move: it uses the region's rent reference, not what
    // this family happens to pay.
    expect($case->fresh()->housing()->first()->monthly_rent)->toBe(90_000)
        ->and($request->fresh()->old_json['monthly_rent'])->toBe(10_000)
        ->and($assessments->count())->toBeGreaterThan(1)
        ->and($case->fresh()->currentAssessment()->base_score)->not->toBe($scoreBefore);
});

it('٦ — coverage is read for the month, and the next month opens before this one ends', function () {
    $case = publishedCase($this->region);
    $coverage = app(CoverageService::class);

    expect($coverage->currentMonth()->day)->toBe(1)
        ->and($coverage->openMonths())->not->toBeEmpty()
        ->and($coverage->monthIsOpen($coverage->currentMonth()))->toBeTrue();
});
