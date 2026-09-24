<?php

use App\Models\Income;
use App\Services\NeedEngine;

/**
 * Approved 24 Sep 2026: an unstable income records its recurrence — monthly,
 * seasonal or intermittent — and is still never deducted from the need.
 */
beforeEach(function () {
    seedCore();
});

it('keeps the recurrence beside the source, amount and stability', function () {
    $family = familyOf(regionWithRates());

    $income = Income::create([
        'beneficiary_id' => $family->id,
        'source_type' => 'seasonal_work',
        'amount' => 30_000,
        'currency' => config('sanabel.currency'),
        'is_stable' => false,
        'recurrence' => 'seasonal',
    ]);

    expect($income->fresh()->recurrence)->toBe('seasonal')
        ->and(__('sanabel.income_recurrence.seasonal'))->toBe('موسمي');
});

it('does not deduct an unstable income whatever its recurrence', function () {
    $family = familyOf(regionWithRates());
    $before = app(NeedEngine::class)->compute($family->fresh());

    foreach (['monthly', 'seasonal', 'intermittent'] as $recurrence) {
        Income::create([
            'beneficiary_id' => $family->id,
            'source_type' => 'odd_jobs',
            'amount' => 50_000,
            'currency' => config('sanabel.currency'),
            'is_stable' => false,
            'recurrence' => $recurrence,
        ]);
    }

    $after = app(NeedEngine::class)->compute($family->fresh());

    // 150,000 of unstable income, and the deducted figure has not moved.
    expect($after['stable_income'])->toBe($before['stable_income'])
        ->and($after['monthly_need'])->toBe($before['monthly_need']);
});

it('asks for a recurrence only when the income is unstable', function () {
    $form = file_get_contents(app_path('Filament/Resources/BeneficiaryResource.php'));

    expect($form)->toContain("Select::make('recurrence')")
        ->and($form)->toContain("->visible(fn (Forms\\Get \$get) => ! \$get('is_stable'))")
        ->and($form)->toContain("->required(fn (Forms\\Get \$get) => ! \$get('is_stable'))");
});
