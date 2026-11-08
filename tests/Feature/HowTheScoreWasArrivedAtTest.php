<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ViewBeneficiary;
use App\Models\Assessment;
use App\Models\ScoringWeight;
use App\Services\AssessmentService;
use Filament\Facades\Filament;

/*
 | T-261 — «ما عارفين آلية عملها كيف تم الاحتساب», 8 November.
 |
 | The association was handed one number and no way to take it apart, so they
 | could not run a single experiment on the weights: change one, and all they
 | saw was a different number. What is held here is that the number explains
 | itself from the assessment's own two columns, and that the explanation
 | belongs to the study rather than to today's settings.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('adds up to the score it explains', function () {
    $case = publishedCase($this->region);

    $rows = $case->currentAssessment()->scoreBreakdown();

    expect($rows)->toHaveCount(7)
        ->and(collect($rows)->pluck('key')->all())->toBe(['F', 'M', 'V', 'H', 'U', 'D', 'B']);

    $sum = collect($rows)->sum(fn (array $row) => $row['weight'] * $row['value']);

    expect(round($sum, 2))->toBe($case->currentAssessment()->base_score);
});

it('states each factor value out of a hundred and the weight it was scored with', function () {
    $case = publishedCase($this->region);
    $assessment = $case->currentAssessment();

    foreach ($assessment->scoreBreakdown() as $row) {
        expect($row['value'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
            // The weight is the one on the row, not the one in force now.
            ->and($row['weight'])->toBe((float) $assessment->snapshot_json['weights'][$row['key']])
            ->and($row['share'])->toBe(round($row['weight'] * $row['value'], 2));
    }
});

it('names what a composite factor is made of, and leaves a simple one alone', function () {
    $rows = collect(publishedCase($this->region)->currentAssessment()->scoreBreakdown())
        ->keyBy('key');

    expect($rows['M']['parts'])->toHaveCount(4)
        ->and($rows['V']['parts'])->toHaveCount(4)
        ->and($rows['H']['parts'])->toHaveCount(5)
        ->and($rows['F']['parts'])->toBe([])
        ->and($rows['U']['parts'])->toBe([])
        ->and(collect($rows['M']['parts'])->pluck('key')->all())
        ->toBe(['M_severity', 'M_economic_impact', 'M_care_burden', 'M_cost_burden']);
});

it('explains an old study with the weights it was scored with, not with today\'s', function () {
    $case = publishedCase($this->region);
    $before = collect($case->currentAssessment()->scoreBreakdown())->firstWhere('key', 'F');

    expect($before['weight'])->toBe(0.25);

    /*
     | The association doubles the weight of the financial gap, the way they
     | would on the weights screen. Rule 8 says the study already stored keeps
     | the values it was computed with, and this is that rule being readable
     | rather than merely true.
     */
    ScoringWeight::create([
        'factor_key' => 'F',
        'weight' => 0.50,
        'effective_from' => now()->subDay(),
        'version' => 2,
    ]);

    $after = collect($case->currentAssessment()->fresh()->scoreBreakdown())->firstWhere('key', 'F');

    expect($after['weight'])->toBe(0.25);

    // And the next study reads the new weight, so an experiment is visible.
    app(AssessmentService::class)->create($case, status: 'approved');

    $next = collect($case->refresh()->currentAssessment()->scoreBreakdown())->firstWhere('key', 'F');

    expect($next['weight'])->toBe(0.50);
});

it('explains nothing rather than inventing an explanation', function () {
    $assessment = new Assessment(['factors_json' => [], 'snapshot_json' => []]);

    expect($assessment->scoreBreakdown())->toBe([]);
});

it('draws the seven lines on the screen the association actually opens', function () {
    $case = publishedCase($this->region);

    passTwoFactor();
    $this->actingAs(userWithRole('executive_director'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $page = Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()]);

    $page->assertSee(__('sanabel.score_breakdown.title'))
        ->assertSee(__('sanabel.score_breakdown.share'))
        // The sentence that keeps a reader from reading today's weights into
        // a study computed months ago.
        ->assertSee(__('sanabel.score_breakdown.formula'));

    foreach (array_keys(Assessment::FACTOR_PARTS) as $key) {
        $page->assertSee(__('sanabel.weight.factors.'.$key))
            ->assertSee(__('sanabel.score_breakdown.about.'.$key));
    }
});
