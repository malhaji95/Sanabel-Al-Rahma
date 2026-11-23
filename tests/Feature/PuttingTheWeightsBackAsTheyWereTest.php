<?php

use App\Filament\Resources\ScoringWeightResource\Pages\ListScoringWeights;
use App\Models\Assessment;
use App\Models\ScoringWeight;
use App\Services\ReferenceResolver;
use App\Services\WeightRollback;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

/*
 | T-315 — phase two, item 14: «التراجع عن إصدار».
 |
 | The weights were versioned and dated, and the screen published new sets
 | happily. Going back was the thing it would not do: whoever published a
 | wrong seven this morning had to read yesterday's numbers off the audit log
 | and type them in again, by hand, seven times.
 |
 | Naming a date now publishes that date's weights as a new version in force
 | from today. It is a publish, not an undo — no past row is touched — so a
 | study already computed keeps its own snapshot (hard rule 8).
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->admin = userWithRole('admin');
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** Publishes one factor's weight on a date, as the screen would. */
function publishWeight(string $factor, float $weight, string $on, bool $active = true): ScoringWeight
{
    return ScoringWeight::create([
        'factor_key' => $factor,
        'weight' => $weight,
        'is_active' => $active,
        'effective_from' => Carbon::parse($on),
        'version' => 1 + (int) ScoringWeight::withTrashed()->where('factor_key', $factor)->max('version'),
        'created_by' => test()->admin->id,
    ]);
}

it('publishes the weights of a past date again, as a new version in force today', function () {
    publishWeight('F', 0.40, '2026-09-01');
    publishWeight('M', 0.30, '2026-09-01');

    // And then somebody got it wrong this morning.
    publishWeight('F', 0.90, 'today');
    publishWeight('M', 0.05, 'today');

    $changed = app(WeightRollback::class)->restoreTo(Carbon::parse('2026-09-15'), $this->admin);

    expect(array_keys($changed))->toEqualCanonicalizing(['F', 'M'])
        ->and($changed['F']['from'])->toBe(0.90)
        ->and($changed['F']['to'])->toBe(0.40);

    $inForce = app(ReferenceResolver::class)->weights(Carbon::today())['values'];

    expect(round($inForce['F'], 4))->toBe(0.40)
        ->and(round($inForce['M'], 4))->toBe(0.30);
});

it('touches no past row, and deletes nothing', function () {
    $september = publishWeight('F', 0.40, '2026-09-01');
    $wrong = publishWeight('F', 0.90, 'today');

    $before = ScoringWeight::withTrashed()->count();

    app(WeightRollback::class)->restoreTo(Carbon::parse('2026-09-15'), $this->admin);

    expect(ScoringWeight::withTrashed()->count())->toBe($before + 1)
        ->and($september->fresh()->weight)->toBe(0.40)
        ->and($september->fresh()->deleted_at)->toBeNull()
        // The wrong row stays on the record: it was published, and the record
        // says so. It is simply no longer the last word.
        ->and($wrong->fresh()->weight)->toBe(0.90)
        ->and($wrong->fresh()->deleted_at)->toBeNull();
});

it('restores a factor that was stopped as stopped, not as a weight of nought', function () {
    /*
     | T-288 made a stopped factor count as nought. The resolver therefore
     | reports it as 0, and copying that number forward would turn «stopped,
     | and its weight was 0.15» into «running, at nought» — which reads on the
     | screen as a value nobody filled in rather than as a decision.
     */
    publishWeight('D', 0.15, '2026-09-01', active: false);
    publishWeight('D', 0.22, 'today');

    app(WeightRollback::class)->restoreTo(Carbon::parse('2026-09-10'), $this->admin);

    $row = ScoringWeight::where('factor_key', 'D')->orderByDesc('version')->first();

    expect($row->is_active)->toBeFalse()
        ->and($row->weight)->toBe(0.15);

    $state = app(ReferenceResolver::class)->weights(Carbon::today());

    expect($state['stopped'])->toContain('D')
        ->and($state['values']['D'])->toBe(0.0);
});

it('restores the default where no row had been published by that date', function () {
    // Nothing on 1 September; the default was what governed.
    publishWeight('V', 0.60, 'today');

    app(WeightRollback::class)->restoreTo(Carbon::parse('2026-09-01'), $this->admin);

    $inForce = app(ReferenceResolver::class)->weights(Carbon::today())['values'];

    expect(round($inForce['V'], 4))->toBe(round(ReferenceResolver::DEFAULT_WEIGHTS['V'], 4));
});

it('refuses a date that has not come, and a date that would change nothing', function () {
    expect(fn () => app(WeightRollback::class)->restoreTo(Carbon::tomorrow(), $this->admin))
        ->toThrow(RuntimeException::class, __('sanabel.weight.rollback_future'));

    expect(fn () => app(WeightRollback::class)->restoreTo(Carbon::parse('2026-09-01'), $this->admin))
        ->toThrow(RuntimeException::class, __('sanabel.weight.rollback_no_change'));
});

it('leaves a study computed before it on the snapshot it was computed from', function () {
    /*
     | Hard rule 8. Restoring an old set changes what happens next, and never
     | what has already happened.
     */
    publishWeight('F', 0.40, '2026-09-01');
    publishWeight('F', 0.90, 'today');

    $case = publishedCase($this->region);
    $study = $case->assessments()->latest('id')->first();

    expect($study)->not->toBeNull();

    $snapshot = $study->snapshot_json;
    $score = $study->base_score;

    app(WeightRollback::class)->restoreTo(Carbon::parse('2026-09-15'), $this->admin);

    $study->refresh();

    expect($study->snapshot_json)->toBe($snapshot)
        ->and($study->base_score)->toBe($score);

    expect(Assessment::whereKey($study->id)->value('updated_at')->toIso8601String())
        ->toBe($study->updated_at->toIso8601String());
});

it('offers the button to the hand that may publish a weight, and to no other', function () {
    $this->actingAs($this->admin);

    expect(Livewire\Livewire::test(ListScoringWeights::class)->html())
        ->toContain(e(__('sanabel.weight.rollback')));

    /*
     | Oversight opens this screen — it reads every screen — and is offered
     | nothing to press on it: `is_read_only` refuses the publish key whatever
     | the matrix says, and the button asks for that key by name.
     */
    $this->actingAs(userWithRole('oversight_director'));

    expect(Livewire\Livewire::test(ListScoringWeights::class)->html())
        ->not->toContain(e(__('sanabel.weight.rollback')));
})->group('AUTH-01');
