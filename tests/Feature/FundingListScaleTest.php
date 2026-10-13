<?php

use App\Models\Beneficiary;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Models\Override;
use App\Services\CoverageService;
use App\Services\DonationService;
use App\Services\RankingService;
use Illuminate\Support\Facades\DB;

/*
 | T-87 — what the donor list may cost.
 |
 | The list ranked every published family by loading every published family:
 | a Beneficiary carries sixty-eight columns, and the list eager-loaded seven
 | relations onto each one, on every request, to show twelve of them. Measured
 | on the demo set it came to about 38 KB per family per request — so a 256 MB
 | worker stopped being slow and started answering 500 somewhere around six
 | thousand families, which is a size this association will reach.
 |
 | It now ranks on a flat row per family and loads only the page. The tests
 | below hold the two halves of that: that the ranking never fetches a whole
 | family, and that the ranking it computes is the same one the models give.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->ranking = app(RankingService::class);
    $this->coverage = app(CoverageService::class);
});

/** Verified money against this month, so a row has a figure on it. */
function fundNow(Beneficiary $case, int $amount, $admin): void
{
    static $ref = 0;

    $donation = app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => $amount,
        'transaction_ref' => 'TRX-SCALE-'.(++$ref),
    ]);

    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $case->id,
        'amount' => $amount,
        'currency' => config('sanabel.currency'),
        'coverage_month' => now()->startOfMonth(),
    ]);

    app(DonationService::class)->verify($donation, $admin->id);
}

it('ranks without fetching a single whole family', function () {
    foreach (range(1, 3) as $ignored) {
        publishedCase($this->region);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $rows = $this->ranking->fundingList('monthly', withCovered: true);

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // The one query that reads the families names its columns. `select *`
    // here is the whole regression: sixty-eight columns times every published
    // family, for a page that shows twelve.
    $families = $queries->filter(fn (string $q) => str_contains($q, 'from "beneficiaries"'));

    expect($families)->not->toBeEmpty('the list reads no families at all');

    foreach ($families as $query) {
        expect(str_contains($query, 'select *'))->toBeFalse('the list still fetches whole family rows: '.$query);
        expect(str_contains($query, '"beneficiaries".*'))->toBeFalse('the list still fetches whole family rows: '.$query);
    }

    // And the rows it answers with carry no model at all, so nothing
    // downstream can quietly reach through one.
    expect($rows)->not->toBeEmpty()
        ->and($rows->first())->toHaveKeys(['beneficiary_id', 'file_number', 'confirmed', 'need', 'month', 'covered', 'ranking'])
        ->and($rows->first())->not->toHaveKey('beneficiary');
});

it('loads the page it is given and no more', function () {
    foreach (range(1, 5) as $ignored) {
        publishedCase($this->region);
    }

    $rows = $this->ranking->fundingList('monthly', withCovered: true);

    $page = $this->ranking->hydrate($rows->take(2));

    expect($page)->toHaveCount(2)
        ->and($page->first()['beneficiary'])->toBeInstanceOf(Beneficiary::class)
        // In the ranked order, and carrying the row's own figures.
        ->and($page->pluck('beneficiary_id')->all())->toBe($rows->take(2)->pluck('beneficiary_id')->all())
        // Everything the card reads is already there: a masked card must not
        // go back to the database per family.
        ->and($page->first()['beneficiary']->relationLoaded('members'))->toBeTrue()
        ->and($page->first()['beneficiary']->relationLoaded('housing'))->toBeTrue()
        ->and($page->first()['beneficiary']->relationLoaded('assessments'))->toBeTrue();
});

it('computes the same ranking the families themselves give', function () {
    /*
     | The safety net under the whole change. The list now reads a family's
     | score, need and waiting time out of a flat row built by one query,
     | where it used to ask the model. Both paths end in the same formula,
     | and this holds that they are fed the same numbers — including the one
     | case the query has to reproduce by hand: a live override standing in
     | for the assessment's own score.
     */
    $admin = userWithRole('admin');

    $plain = publishedCase($this->region);

    $partly = publishedCase($this->region);
    fundNow($partly, intdiv($this->coverage->needAmount($partly), 3), $admin);

    $overridden = publishedCase($this->region);
    Override::create([
        'assessment_id' => $overridden->currentAssessment()->id,
        'auto_score' => $overridden->currentAssessment()->base_score,
        'new_score' => 91.5,
        'reason_ar' => 'قرار لجنة',
        'approved_by' => $admin->id,
    ]);

    $expired = publishedCase($this->region);
    Override::create([
        'assessment_id' => $expired->currentAssessment()->id,
        'auto_score' => $expired->currentAssessment()->base_score,
        'new_score' => 10.0,
        'reason_ar' => 'قرار انتهى',
        'approved_by' => $admin->id,
        'expires_at' => now()->subDay(),
    ]);

    $unapproved = publishedCase($this->region);
    Override::create([
        'assessment_id' => $unapproved->currentAssessment()->id,
        'auto_score' => $unapproved->currentAssessment()->base_score,
        'new_score' => 5.0,
        'reason_ar' => 'بانتظار الاعتماد',
    ]);

    $overdue = publishedCase($this->region);
    $overdue->forceFill(['next_assessment_due_at' => now()->subWeek()])->save();

    $rows = $this->ranking->fundingList('monthly', withCovered: true);

    expect($rows)->toHaveCount(6);

    foreach ($rows as $row) {
        $family = Beneficiary::with('assessments.overrides')->find($row['beneficiary_id']);

        expect($row['ranking'])->toBe(
            $this->ranking->rank($family, $row['confirmed']),
            'the flat row and the family disagree on file '.$row['file_number'],
        );

        expect($row['need'])->toBe($this->coverage->needAmount($family));
    }

    // The live override decided one of them, and the two dead ones decided
    // nothing — which is the join doing its work rather than the test's hope.
    $scores = $rows->mapWithKeys(fn (array $r) => [$r['file_number'] => $r['ranking']['current_score']]);

    expect($scores[$overridden->file_number])->toBe(91.5)
        ->and($scores[$expired->file_number])->toBe($scores[$plain->file_number])
        ->and($scores[$unapproved->file_number])->toBe($scores[$plain->file_number]);
});

it('counts the covered families on the home page without loading them', function () {
    // The other unbounded read: the homepage counted covered families by
    // fetching every published one with its assessments.
    $admin = userWithRole('admin');

    $covered = publishedCase($this->region);
    fundNow($covered, $this->coverage->needAmount($covered), $admin);

    publishedCase($this->region);

    // A published family with no approved assessment has no need, so it reads
    // as covered — as it always did.
    familyOf($this->region, attributes: [
        'status' => 'published',
        'approved_at' => now()->subDay(),
        'published_at' => now()->subDay(),
    ]);

    $this->get('/')->assertOk()->assertSee(__('sanabel.public.stat_covered'));

    $needs = $this->ranking->publishedNeeds();
    $funded = $this->coverage->fundedForMonthByNeed($needs);

    $short = count(array_filter(
        $needs,
        fn (int $need, int $id) => $need > 0 && ($funded[$id] ?? 0) < $need,
        ARRAY_FILTER_USE_BOTH,
    ));

    expect(Beneficiary::published()->count())->toBe(3)
        ->and($short)->toBe(1)
        // The family with no assessment is not in the needs at all.
        ->and(count($needs))->toBe(2);
});
