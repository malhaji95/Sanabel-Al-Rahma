<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use App\Models\Housing;
use App\Services\AssessmentService;
use App\Services\NeedEngine;
use App\Services\ScoreService;

/*
 | T-300 — «دورية الإيجار لا تدخل في الحساب», 12 November.
 |
 | The column is called `monthly_rent` and everything that touched it read a
 | month's rent out of it, while the question beside it offered «كل ثلاثة
 | أشهر» and «سنوي». So a year's rent, entered correctly as a year's rent,
 | was read as a month's.
 |
 | We had reported this as harmless, on the reasoning that the burden is
 | capped at the reference and the cap would absorb it. That is true only
 | where the rent is at or above the reference. Below it — which is where a
 | poor family's rent sits — the figure is simply wrong, by as much as
 | fifty-five points of the sub-factor.
 |
 | Their acceptance test: the same rent entered both ways, and the same
 | result.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** One family, renting, with the rent written for the period given. */
function rentingFamily(int $amount, ?string $period): Beneficiary
{
    $case = familyOf(test()->region, adults: 2, children: 2);
    $case->housing()->forceDelete();

    Housing::create([
        'beneficiary_id' => $case->getKey(),
        'housing_type' => 'rent',
        'monthly_rent' => $amount,
        'rent_period' => $period,
        'habitable_rooms' => 2,
        'safety_band' => 50,
        'services_band' => 50,
        'eviction_band' => 25,
    ]);

    return $case->refresh();
}

it('reads the same rent the same way, whichever period it was written for', function () {
    // Thirty thousand a month, and the identical rent written as a year's.
    $monthly = rentingFamily(30_000, 'monthly');
    $annual = rentingFamily(360_000, 'annual');

    $engine = app(NeedEngine::class);
    $score = app(ScoreService::class);

    $a = $score->compute($monthly, $engine->compute($monthly));
    $b = $score->compute($annual, $engine->compute($annual));

    expect($b['factors']['H'])->toBe($a['factors']['H'])
        ->and($b['base_score'])->toBe($a['base_score']);
});

it('reads the quarter and the half year too, not only the year', function () {
    $monthly = rentingFamily(20_000, 'monthly');

    foreach ([['quarterly', 60_000], ['semiannual', 120_000], ['annual', 240_000]] as [$period, $amount]) {
        $other = rentingFamily($amount, $period);

        expect($other->housing->monthlyRent())
            ->toBe($monthly->housing->monthlyRent(), "the $period rent");
    }
});

it('takes an unanswered period for a month, which is what every older row meant', function () {
    $case = rentingFamily(25_000, null);

    expect($case->housing->monthlyRent())->toBe(25_000);
});

it('was wrong by this much: the figure the sub-factor used to get', function () {
    /*
     | The number that makes the case. A rent of twenty thousand against a
     | reference of forty-five, on a household with an income: read as a
     | month it is eleven, read as a year it is sixty-six.
     */
    expect(ScoreService::rentBurden(20_000, 45_000, 50_000))->toBeLessThan(12.0)
        ->and(ScoreService::rentBurden(240_000, 45_000, 50_000))->toBeGreaterThan(66.0);
});

it('puts the donor’s band on a month as well', function () {
    /*
     | The same column is read on the donor's card. A year's rent put every
     | such family in the «high» band, which is a statement about the family
     | made out of a unit mistake.
     */
    $monthly = rentingFamily(30_000, 'monthly');
    $annual = rentingFamily(360_000, 'annual');

    app(AssessmentService::class)->create($monthly, status: 'approved');
    app(AssessmentService::class)->create($annual, status: 'approved');

    $a = (new MaskedCaseResource($monthly->fresh()))->resolve();
    $b = (new MaskedCaseResource($annual->fresh()))->resolve();

    expect($b['rent_band'] ?? null)->toBe($a['rent_band'] ?? null);
});

it('says on the screen what the family pays, beside what was counted', function () {
    $case = rentingFamily(360_000, 'annual');
    app(AssessmentService::class)->create($case, status: 'approved');

    $view = view('filament.forms.need-breakdown', [
        'getRecord' => fn () => $case->fresh(),
    ])->render();

    // Thirty thousand a month, said as a month, with the period it came from.
    expect($view)->toContain(number_format(30_000))
        ->and($view)->toContain(__('sanabel.rent_period.annual'));
});
