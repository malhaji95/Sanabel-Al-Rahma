<?php

use App\Filament\Resources\BeneficiaryResource\Pages\ViewBeneficiary;
use App\Models\Assessment;
use App\Models\RegionRate;
use App\Services\AssessmentService;
use App\Services\CoverageService;
use Filament\Facades\Filament;

/*
 | T-277 — «نطلب شاشة تفصيلية داخل دراسة كل أسرة توضح كيف وصل النظام إلى مبلغ
 | الاحتياج والعجز والأولوية والتمويل, حتى يستطيع المسؤول مراجعة النتيجة دون
 | الرجوع للمبرمج», 9 November.
 |
 | T-261 answered that for the score. This is the other half: the money. It
 | reads the study's own snapshot and nothing else, so a study explains
 | itself with the reference values it was computed from, whatever has been
 | edited since.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('adds up to the need it explains', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);

    $breakdown = $case->currentAssessment()->needBreakdown();

    $sum = collect($breakdown['need'])->sum('total');

    expect($sum)->toBe($breakdown['totals']['monthly_need'])
        ->and($breakdown['totals']['monthly_need'])->toBe($case->currentAssessment()->monthly_need);
});

it('shows the deficit as the need less the stable income, which is what the percentage is taken of', function () {
    $case = publishedCase($this->region);
    $assessment = $case->currentAssessment();

    $totals = $assessment->needBreakdown()['totals'];

    expect($totals['deficit'])->toBe(max(0, $assessment->monthly_need - $assessment->stable_income))
        // And it is the figure CoverageService multiplies by the percent.
        ->and($totals['deficit'])->toBe(app(CoverageService::class)->needAmount($case));
});

it('names every line and where its figure came from', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);

    $rows = $case->currentAssessment()->needBreakdown()['need'];
    $kinds = collect($rows)->pluck('kind')->unique()->values()->all();

    // A class line per person class, the adjustments, and the rent line —
    // which appears even when it is not applied, so a reader asking why a
    // need looks low finds the answer rather than a missing row.
    expect($kinds)->toContain('class')
        ->and($kinds)->toContain('rent')
        ->and(collect($rows)->firstWhere('kind', 'rent')['applied'])->toBeFalse();

    foreach ($rows as $row) {
        expect($row)->toHaveKeys(['kind', 'key', 'count', 'unit', 'version', 'total']);
    }
});

it('keeps the three incomes apart, which is where double counting was asked about', function () {
    $case = publishedCase($this->region);

    $income = collect($case->currentAssessment()->needBreakdown()['income']);

    expect($income->pluck('key')->all())
        ->toBe(['income_rows', 'person_work_income', 'livestock_income'])
        // The three together are the stable income and nothing else.
        ->and($income->sum('total'))->toBe($case->currentAssessment()->stable_income);
});

it('explains an old study with the values it was computed from, not today\'s', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);

    $before = collect($case->currentAssessment()->needBreakdown()['need'])
        ->firstWhere('key', 'child');

    // The association raises the child rate, the way they would on the
    // reference screen. Rule 8: the study already stored keeps its own.
    RegionRate::create([
        'region_id' => $this->region->id,
        'person_class' => 'child',
        'amount' => $before['unit'] * 3,
        'currency' => config('sanabel.currency'),
        'effective_from' => now()->subDay(),
        'version' => 99,
    ]);

    $after = collect($case->currentAssessment()->fresh()->needBreakdown()['need'])
        ->firstWhere('key', 'child');

    expect($after['unit'])->toBe($before['unit']);

    // And the next study reads the new one, so the change is not lost.
    app(AssessmentService::class)->create($case, status: 'approved');

    $next = collect($case->refresh()->currentAssessment()->needBreakdown()['need'])
        ->firstWhere('key', 'child');

    expect($next['unit'])->toBe($before['unit'] * 3);
});

it('explains nothing rather than inventing an explanation', function () {
    expect((new Assessment(['snapshot_json' => []]))->needBreakdown())->toBe([]);
});

it('draws it on the screen the association actually opens', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);

    passTwoFactor();
    $this->actingAs(userWithRole('executive_director'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertSee(__('sanabel.need_breakdown.title'))
        ->assertSee(__('sanabel.need_breakdown.income_title'))
        ->assertSee(__('sanabel.need_breakdown.deficit_note'))
        ->assertSee(__('sanabel.need_breakdown.gap_note'))
        // And the sentence that stops a reader reading today's values into
        // a study computed months ago.
        ->assertSee(__('sanabel.need_breakdown.frozen_note'));
});

it('draws the funding percent and the target beside the deficit, on the study in force', function () {
    /*
     | T-298 — the sixth and seventh of the nine values the association asked
     | to see on its own line in every family's file. They are the only two in
     | that list the study's snapshot does not hold: the percent is a setting
     | on the region, read at the moment it is asked for.
     |
     | So they are read from today and say so, and they are drawn only on the
     | study in force — «today's target» over a study from August is a figure
     | that means nothing.
     */
    $case = publishedCase($this->region);

    $this->actingAs(userWithRole('executive_director'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $coverage = app(CoverageService::class);

    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertSee(__('sanabel.need_breakdown.target_percent'))
        ->assertSee(__('sanabel.need_breakdown.target_amount'))
        ->assertSee(__('sanabel.need_breakdown.target_note'))
        ->assertSee($coverage->targetPercent($case).'%')
        ->assertSee(number_format($coverage->targetAmount($case)));
});

it('is the deficit times the percent, and nothing else', function () {
    // The screen may not quietly invent a third figure: the target is the
    // deficit the page just showed, taken at the percent the page just named.
    $case = publishedCase($this->region);
    $coverage = app(CoverageService::class);

    $deficit = $case->currentAssessment()->needBreakdown()['totals']['deficit'];

    expect($coverage->targetAmount($case))
        ->toBe(intdiv($deficit * $coverage->targetPercent($case), 100));
});

it('keeps today’s target off a study that is no longer the one in force', function () {
    $case = publishedCase($this->region);

    // A second, newer study takes over, so the first is history.
    $older = $case->currentAssessment();
    app(AssessmentService::class)->create($case->refresh(), status: 'approved');

    expect($case->refresh()->currentAssessment()->getKey())->not->toBe($older->getKey());

    $this->actingAs(userWithRole('executive_director'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The file still draws it, because the file draws the study in force.
    Livewire\Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertSee(__('sanabel.need_breakdown.target_percent'));

    // The older study, read on its own, does not: it is not what is funded.
    $view = view('filament.forms.need-breakdown', [
        'getRecord' => fn () => Assessment::find($older->getKey()),
    ])->render();

    expect($view)->not->toContain(__('sanabel.need_breakdown.target_percent'))
        ->and($view)->toContain(__('sanabel.need_breakdown.deficit_note'));
});
