<?php

use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Services\AssessmentService;
use App\Services\RankingService;

beforeEach(function () {
    seedCore();
});

/** Gives a family an approved assessment with an exact base score and need. */
function caseScoring(App\Models\Region $region, float $score, int $need, array $attributes = []): App\Models\Beneficiary
{
    $family = familyOf($region, adults: 2, children: 3, attributes: array_merge([
        'status' => 'published',
        'approved_at' => now()->subDays(3),
        'published_at' => now()->subDays(3),
    ], $attributes));

    $assessment = app(AssessmentService::class)->create($family->refresh(), status: 'approved');

    // Score and need are pinned so the ranking arithmetic is the only thing under test.
    $assessment->forceFill([
        'base_score' => $score,
        'monthly_need' => $need,
        'stable_income' => 0,
    ])->save();

    return $family->refresh();
}

function fundWith(App\Models\Beneficiary $family, int $amount, string $status = 'verified'): Donation
{
    $donation = Donation::factory()->create([
        'amount' => $amount,
        'status' => $status,
        'verified_at' => $status === 'verified' ? now() : null,
    ]);

    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $family->id,
        'amount' => $amount,
        'currency' => 'SYP',
    ]);

    return $donation;
}

it('gives a score of 100 with 60% covered a current score of 40', function () {
    $region = regionWithRates();
    $family = caseScoring($region, score: 100, need: 100_000);
    fundWith($family, 60_000);

    $ranking = app(RankingService::class)->rank($family);

    expect($ranking['remaining'])->toBe(0.4)
        ->and($ranking['current_score'])->toBe(40.0);
});

it('lets an unfunded case at 50 outrank a 60%-funded case at 100', function () {
    $region = regionWithRates();

    $unfunded = caseScoring($region, score: 50, need: 100_000);
    $funded = caseScoring($region, score: 100, need: 100_000);
    fundWith($funded, 60_000);

    $ranking = app(RankingService::class);

    expect($ranking->rank($unfunded)['current_score'])->toBe(50.0)
        ->and($ranking->rank($funded)['current_score'])->toBe(40.0)
        ->and($ranking->rank($unfunded)['current_score'])
        ->toBeGreaterThan($ranking->rank($funded)['current_score']);
});

it('drops a fully covered case from the funding list and the waiting bonus does not bring it back', function () {
    $region = regionWithRates();

    // Waiting long enough to earn the maximum bonus of 10.
    $covered = caseScoring($region, score: 100, need: 100_000, attributes: [
        'published_at' => now()->subDays(120),
        'approved_at' => now()->subDays(120),
    ]);
    fundWith($covered, 100_000);

    $ranking = app(RankingService::class)->rank($covered);

    expect($ranking['waiting_bonus'])->toBe(10)
        ->and($ranking['remaining'])->toBe(0.0)
        ->and($ranking['eligible'])->toBeFalse()
        ->and($ranking['priority'])->toBe(0.0);

    $list = app(RankingService::class)->fundingList('monthly');

    expect($list->pluck('beneficiary.id'))->not->toContain($covered->id);
});

it('ignores pledges and unverified proofs when ranking', function () {
    $region = regionWithRates();
    $family = caseScoring($region, score: 100, need: 100_000);

    fundWith($family, 60_000, status: 'pending');
    fundWith($family, 20_000, status: 'rejected');

    $ranking = app(RankingService::class)->rank($family);

    expect($ranking['remaining'])->toBe(1.0)
        ->and($ranking['current_score'])->toBe(100.0);
});

it('caps the waiting bonus at 10 and adds one point per waiting week', function () {
    $region = regionWithRates();

    $threeWeeks = caseScoring($region, score: 10, need: 100_000, attributes: [
        'published_at' => now()->subDays(21), 'approved_at' => now()->subDays(21),
    ]);
    $twoYears = caseScoring($region, score: 10, need: 100_000, attributes: [
        'published_at' => now()->subDays(730), 'approved_at' => now()->subDays(730),
    ]);

    $ranking = app(RankingService::class);

    expect($ranking->rank($threeWeeks)['waiting_bonus'])->toBe(3)
        ->and($ranking->rank($twoYears)['waiting_bonus'])->toBe(10)
        ->and($ranking->rank($twoYears)['priority'])->toBe(20.0);
});

it('separates monthly and one-time cases into two lists', function () {
    $region = regionWithRates();
    caseScoring($region, score: 80, need: 100_000, attributes: ['support_type' => 'monthly']);
    caseScoring($region, score: 80, need: 100_000, attributes: ['support_type' => 'one_time']);

    $ranking = app(RankingService::class);

    expect($ranking->fundingList('monthly'))->toHaveCount(1)
        ->and($ranking->fundingList('one_time'))->toHaveCount(1);
});
