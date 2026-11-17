<?php

use App\Filament\Resources\ScoringWeightResource\Pages\ListScoringWeights;
use App\Models\Assessment;
use App\Models\ScoringWeight;
use App\Services\WeightSimulation;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 | T-308 — «هل النظام يتعامل مع الرقم ٨٥ باعتباره ٨٥٪, أم يقوم بقسمة كل وزن
 | على مجموع الأوزان؟ وهل يجب أن نضع F = 0.85 بدلًا من 85؟», 17 November.
 |
 | The association was about to enter 85 for the financial gap, meaning
 | eighty-five per cent. The engine divides nothing: it multiplies a factor
 | already out of a hundred by the number as written. So 85 would send every
 | file to the ceiling and leave the queue in no order at all.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** A family whose one approved study froze the factors given. */
function fileWithFactors(array $factors, string $fileNumber): void
{
    $family = familyOf(test()->region, adults: 1, children: 1);
    $family->forceFill(['file_number' => $fileNumber])->save();

    Assessment::create([
        'beneficiary_id' => $family->getKey(),
        'monthly_need' => 100_000,
        'stable_income' => 0,
        'gap' => 100_000,
        'currency' => config('sanabel.currency'),
        'base_score' => 0,
        'factors_json' => $factors,
        'snapshot_json' => ['weights' => []],
        'status' => 'approved',
    ]);
}

/** What the files score under a set of weights, keyed by file number. */
function scoresUnder(array $weights): array
{
    return collect(app(WeightSimulation::class)->run($weights)['rows'])
        ->mapWithKeys(fn (array $row) => [$row['file_number'] => $row['score_new']])
        ->all();
}

function weightsSubheading(): string
{
    return (string) Livewire::actingAs(userWithRole('admin'))
        ->test(ListScoringWeights::class)->instance()->getSubheading();
}

it('divides no weight by the sum of the weights', function () {
    /*
     | The question itself, answered in arithmetic. Two families far apart on
     | every factor land on the same hundred, which is what it costs to read
     | 85 as eighty-five per cent.
     */
    fileWithFactors(['F' => 100, 'M' => 50, 'V' => 50, 'H' => 50, 'U' => 100, 'D' => 100, 'B' => 100], 'W-POOR');
    fileWithFactors(['F' => 20, 'M' => 0, 'V' => 0, 'H' => 0, 'U' => 0, 'D' => 0, 'B' => 0], 'W-BETTER');

    $asWritten = scoresUnder(['F' => 85, 'M' => 0.20, 'V' => 0.15, 'H' => 0.10, 'U' => 0.15, 'D' => 0.10, 'B' => 0.05]);

    expect($asWritten['W-POOR'])->toBe(100.0)
        ->and($asWritten['W-BETTER'])->toBe(100.0);

    // The same two, under weights that really are 85 per cent and 15.
    $eightyFive = scoresUnder(['F' => 0.85, 'M' => 0.04, 'V' => 0.03, 'H' => 0.02, 'U' => 0.03, 'D' => 0.02, 'B' => 0.01]);

    expect($eightyFive['W-POOR'])->toBe(95.5)
        ->and($eightyFive['W-BETTER'])->toBe(17.0);
});

it('counts a sub-weight inside its own factor and nowhere else', function () {
    // The seven are all the score is made of: orphans, the single carer and
    // the dependants ratio reach it through V, and never beside it.
    expect(WeightSimulation::FACTORS)->toBe(['F', 'M', 'V', 'H', 'U', 'D', 'B']);

    fileWithFactors(['F' => 0, 'M' => 0, 'V' => 100, 'H' => 0, 'U' => 0, 'D' => 0, 'B' => 0], 'W-ONLYV');

    // 0.15 × 100, and not a point more for the orphans inside it.
    $scores = scoresUnder(['F' => 0.25, 'M' => 0.20, 'V' => 0.15, 'H' => 0.10, 'U' => 0.15, 'D' => 0.10, 'B' => 0.05]);

    expect($scores['W-ONLYV'])->toBe(15.0);
});

it('tells the screen what the number it asks for is out of', function () {
    $said = weightsSubheading();

    expect($said)->toContain('٠٫٨٥')
        ->and($said)->not->toContain('sanabel.');
});

it('reads back a sum of one without alarm', function () {
    expect(weightsSubheading())->toContain(__('sanabel.weight.sum_ok', ['sum' => '1.00']));
});

it('warns when the weights in force no longer sum to one', function () {
    ScoringWeight::create([
        'factor_key' => 'F',
        'weight' => 85,
        'is_active' => true,
        'effective_from' => now()->subDay(),
        'version' => 2,
    ]);

    // 85 in place of 0.25 leaves 85.75 on the seven.
    expect(weightsSubheading())->toContain(__('sanabel.weight.sum_off', ['sum' => '85.75']));
});
