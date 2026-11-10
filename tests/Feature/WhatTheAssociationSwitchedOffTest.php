<?php

use App\Models\AdjustmentCatalog;
use App\Models\HealthRecord;
use App\Models\ScoringWeight;
use App\Services\AssessmentService;
use App\Services\NeedEngine;
use App\Services\ReferenceResolver;

/*
 | T-288 — «نحتاج آلية اننا نستطيع ايقاف اي وزن او تعديل, مثلا موضوع الأدوية
 | ممكن ايقافه», 10 November.
 |
 | It could already be done by typing a nought, and that is not the same
 | thing: a nought reads as a value nobody filled in. A switch is a decision,
 | it can be read back, and it is frozen into every study that was computed
 | while it was set.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('takes a switched-off factor out of the score entirely', function () {
    $case = familyOf($this->region, adults: 2, children: 2);

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'severity_band' => 100,
        'economic_impact_band' => 100,
        'care_burden_band' => 100,
        'monthly_medical_cost' => 50_000,
    ]);

    $with = app(AssessmentService::class)->create($case->refresh())->base_score;

    ScoringWeight::create([
        'factor_key' => 'M',
        'weight' => 0.20,
        'is_active' => false,
        'effective_from' => now()->subDay(),
        'version' => 2,
    ]);

    $without = app(AssessmentService::class)->create($case->refresh())->base_score;

    expect($without)->toBeLessThan($with);
});

it('counts a switched-off weight as nought, not as the value we proposed', function () {
    ScoringWeight::create([
        'factor_key' => 'M',
        'weight' => 0.90,
        'is_active' => false,
        'effective_from' => now()->subDay(),
        'version' => 2,
    ]);

    $weights = app(ReferenceResolver::class)->weights(now());

    // Not 0.90, and not the 0.20 it would fall back to either: falling back
    // would make switching one off raise it.
    expect($weights['values']['M'])->toBe(0.0)
        ->and($weights['stopped'])->toContain('M');
});

it('says «موقوف» on the study rather than showing a bare nought', function () {
    $case = familyOf($this->region, adults: 2, children: 2);

    ScoringWeight::create([
        'factor_key' => 'M', 'weight' => 0.20, 'is_active' => false,
        'effective_from' => now()->subDay(), 'version' => 2,
    ]);

    $row = collect(app(AssessmentService::class)->create($case->refresh())->scoreBreakdown())
        ->firstWhere('key', 'M');

    expect($row['stopped'])->toBeTrue()
        ->and($row['weight'])->toBe(0.0);
});

it('takes a switched-off item out of the need, and says so', function () {
    $case = familyOf($this->region, adults: 2, children: 2);

    AdjustmentCatalog::create([
        'key' => 'children_present',
        'name_ar' => 'وجود أطفال',
        'amount' => 9000,
        'currency' => config('sanabel.currency'),
        'region_id' => null,
        'effective_from' => now()->subYear(),
        'version' => 1,
    ]);

    $with = app(NeedEngine::class)->compute($case->refresh())['monthly_need'];

    AdjustmentCatalog::create([
        'key' => 'children_present',
        'name_ar' => 'وجود أطفال',
        'amount' => 9000,
        'is_active' => false,
        'currency' => config('sanabel.currency'),
        'region_id' => null,
        'effective_from' => now()->subDay(),
        'version' => 2,
    ]);

    $without = app(NeedEngine::class)->compute($case->refresh())['monthly_need'];

    expect($with - $without)->toBe(9000);

    $row = collect(app(AssessmentService::class)->create($case->refresh())->needBreakdown()['need'])
        ->firstWhere('key', 'children_present');

    expect($row['stopped'])->toBeTrue()
        ->and($row['total'])->toBe(0);
});

it('keeps a study computed while it was on exactly as it was', function () {
    $case = familyOf($this->region, adults: 2, children: 2);

    AdjustmentCatalog::create([
        'key' => 'children_present', 'name_ar' => 'وجود أطفال', 'amount' => 9000,
        'currency' => config('sanabel.currency'), 'region_id' => null,
        'effective_from' => now()->subYear(), 'version' => 1,
    ]);

    $before = app(AssessmentService::class)->create($case->refresh(), status: 'approved');
    $need = $before->monthly_need;

    AdjustmentCatalog::create([
        'key' => 'children_present', 'name_ar' => 'وجود أطفال', 'amount' => 9000,
        'is_active' => false, 'currency' => config('sanabel.currency'), 'region_id' => null,
        'effective_from' => now()->subDay(), 'version' => 2,
    ]);

    // Rule 8 again: the old study is explained by the day it was computed.
    expect($before->fresh()->monthly_need)->toBe($need)
        ->and(collect($before->fresh()->needBreakdown()['need'])
            ->firstWhere('key', 'children_present')['stopped'])->toBeFalse();
});

it('restores exactly what was there when it is switched back on', function () {
    $case = familyOf($this->region, adults: 2, children: 2);

    $row = ScoringWeight::create([
        'factor_key' => 'V', 'weight' => 0.35, 'is_active' => false,
        'effective_from' => now()->subDay(), 'version' => 2,
    ]);

    expect(app(ReferenceResolver::class)->weights(now())['values']['V'])->toBe(0.0);

    $row->update(['is_active' => true]);

    // The weight was never lost: switching off is not deleting.
    expect(app(ReferenceResolver::class)->weights(now())['values']['V'])->toBe(0.35)
        ->and(app(ReferenceResolver::class)->weights(now())['stopped'])->not->toContain('V');
});
