<?php

use App\Filament\Pages\WeightSimulator;
use App\Models\Assessment;
use App\Models\ScoringWeight;
use App\Services\WeightSimulation;
use Filament\Facades\Filament;

/*
 | T-297 — «توفير إمكانية اختبار أوزان جديدة دون تغيير نتائج الدراسات
 | المعتمدة», 12 November.
 |
 | The association wants to see what a set of weights would do before it
 | enters one. The screen answers from the factors each study froze on its
 | own row, so it reads and reckons: no study is recomputed, none is touched,
 | and the system is the same after as before.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** A family with one approved study carrying the factors given. */
function fileScoring(array $factors, float $score, string $fileNumber): Assessment
{
    $family = familyOf(test()->region, adults: 1, children: 1);
    $family->forceFill(['file_number' => $fileNumber])->save();

    return Assessment::create([
        'beneficiary_id' => $family->getKey(),
        'monthly_need' => 100_000,
        'stable_income' => 0,
        'gap' => 100_000,
        'currency' => config('sanabel.currency'),
        'base_score' => $score,
        'factors_json' => $factors,
        'snapshot_json' => ['weights' => []],
        'status' => 'approved',
    ]);
}

it('scores a file the same way the engine does, from the factors it froze', function () {
    /*
     | The arithmetic is the whole tool: the score is the seven factors times
     | their weights. If this ever drifts from the engine the screen is
     | worse than useless, because it would be confidently wrong.
     */
    fileScoring(['F' => 100, 'M' => 0, 'V' => 0, 'H' => 20, 'U' => 0, 'D' => 100, 'B' => 0], 37.0, 'SIM-0001');

    $result = app(WeightSimulation::class)->run([
        'F' => 0.25, 'M' => 0.20, 'V' => 0.15, 'H' => 0.10, 'U' => 0.15, 'D' => 0.10, 'B' => 0.05,
    ]);

    // 0.25×100 + 0.10×20 + 0.10×100 = 37, which is the score on the row.
    expect($result['rows'][0]['score_new'])->toBe(37.0)
        ->and($result['rows'][0]['score_now'])->toBe(37.0)
        ->and($result['rows'][0]['move'])->toBe(0);
});

it('keeps a score inside its bounds, exactly as the engine does', function () {
    fileScoring(['F' => 100, 'M' => 100, 'V' => 100, 'H' => 100, 'U' => 100, 'D' => 100, 'B' => 100], 100.0, 'SIM-0002');

    $result = app(WeightSimulation::class)->run([
        'F' => 5, 'M' => 5, 'V' => 5, 'H' => 5, 'U' => 5, 'D' => 5, 'B' => 5,
    ]);

    expect($result['rows'][0]['score_new'])->toBe(100.0);
});

it('reorders the files, and says which moved and by how much', function () {
    // One file is all deficit, the other is all health. Weighting one over
    // the other has to swap them, and that swap is the thing being tested.
    fileScoring(['F' => 100, 'M' => 0, 'V' => 0, 'H' => 0, 'U' => 0, 'D' => 0, 'B' => 0], 25.0, 'SIM-DEFICIT');
    fileScoring(['F' => 0, 'M' => 100, 'V' => 0, 'H' => 0, 'U' => 0, 'D' => 0, 'B' => 0], 20.0, 'SIM-HEALTH');

    $deficitFirst = app(WeightSimulation::class)->run(['F' => 0.9, 'M' => 0.1]);
    expect($deficitFirst['rows'][0]['file_number'])->toBe('SIM-DEFICIT');

    $healthFirst = app(WeightSimulation::class)->run(['F' => 0.1, 'M' => 0.9]);

    expect($healthFirst['rows'][0]['file_number'])->toBe('SIM-HEALTH')
        ->and($healthFirst['rows'][0]['move'])->toBe(1)
        ->and($healthFirst['up'])->toBe(1)
        ->and($healthFirst['down'])->toBe(1);
});

it('gives two files on the same score the same place, so neither is said to have moved', function () {
    /*
     | Without this the sort order would decide which of two equal files came
     | first, and the screen would report a family as having risen when
     | nothing about it had changed.
     */
    fileScoring(['F' => 50, 'M' => 0, 'V' => 0, 'H' => 0, 'U' => 0, 'D' => 0, 'B' => 0], 12.5, 'SIM-TIE-A');
    fileScoring(['F' => 50, 'M' => 0, 'V' => 0, 'H' => 0, 'U' => 0, 'D' => 0, 'B' => 0], 12.5, 'SIM-TIE-B');

    $result = app(WeightSimulation::class)->run(['F' => 0.25]);

    expect($result['rows'][0]['rank_new'])->toBe(1)
        ->and($result['rows'][1]['rank_new'])->toBe(1)
        ->and($result['same'])->toBe(2)
        ->and($result['up'])->toBe(0)
        ->and($result['down'])->toBe(0);
});

it('writes nothing at all: not a study, not a weight, not a score', function () {
    /*
     | The association asked for no change to the approved studies before the
     | rules are agreed. A screen that tried a set of weights by storing them
     | would be the very thing they asked us not to do.
     */
    $assessment = fileScoring(['F' => 100, 'M' => 50, 'V' => 0, 'H' => 0, 'U' => 0, 'D' => 0, 'B' => 0], 35.0, 'SIM-0003');

    $weightsBefore = ScoringWeight::withoutGlobalScopes()->get()->toArray();
    $before = $assessment->fresh()->only(['base_score', 'factors_json', 'snapshot_json', 'status']);

    app(WeightSimulation::class)->run(['F' => 0.85, 'M' => 0.15]);

    expect($assessment->fresh()->only(['base_score', 'factors_json', 'snapshot_json', 'status']))->toBe($before)
        ->and(ScoringWeight::withoutGlobalScopes()->get()->toArray())->toBe($weightsBefore)
        ->and(Assessment::withoutGlobalScopes()->count())->toBe(1);
});

it('says when a file is already being ranked on older weights', function () {
    /*
     | Rule 8 freezes each study on the weights of its day, so a file studied
     | before the weights last changed carries an older sum. That is the rule
     | working, and the screen says so rather than letting it be read as a
     | fault of the proposal being tried.
     */
    fileScoring(['F' => 100, 'M' => 0, 'V' => 0, 'H' => 0, 'U' => 0, 'D' => 0, 'B' => 0], 99.0, 'SIM-STALE');

    // Today's weights would give 0.25×100 = 25, and the row says 99.
    expect(app(WeightSimulation::class)->run(['F' => 0.25])['stale'])->toBe(1);
});

it('opens to the people who may read the weights, and to nobody else', function () {
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->actingAs($officer);

    expect(WeightSimulator::canAccess())->toBeFalse();

    $director = userWithRole('executive_director', ['region_id' => $this->region->id]);
    $this->actingAs($director);

    expect(WeightSimulator::canAccess())->toBeTrue();
});

it('draws the screen with the weights in force already in the boxes', function () {
    $director = userWithRole('executive_director', ['region_id' => $this->region->id]);

    fileScoring(['F' => 100, 'M' => 0, 'V' => 0, 'H' => 0, 'U' => 0, 'D' => 0, 'B' => 0], 25.0, 'SIM-0004');

    Livewire\Livewire::actingAs($director)
        ->test(WeightSimulator::class)
        ->assertOk()
        ->assertSee('SIM-0004')
        ->assertSee(__('sanabel.simulator.nothing_is_written'));
});
