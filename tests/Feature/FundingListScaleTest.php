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
     | The safety net under the whole change. The list reads a family's score,
     | need and waiting time out of a flat row built by one query, where it
     | used to ask the model. Both paths end in the same formula, and this
     | holds that they are fed the same numbers.
     |
     | T-106 — it used to hold one more thing: that the query reproduced a
     | live override by hand, the same way the model did. There is no
     | override any more. On 16 October the association settled that the
     | final score is never set by hand — a wrong score is a wrong input, and
     | the input is what gets corrected. Rows written before that decision
     | stay in the table as the record of what was done; they move nobody.
     */
    $admin = userWithRole('board_director');

    $plain = publishedCase($this->region);

    $partly = publishedCase($this->region);
    fundNow($partly, intdiv($this->coverage->needAmount($partly), 3), $admin);

    // A family carrying an override from before the decision. It ranks on
    // the score the assessment computed, exactly like the family beside it.
    $wasOverridden = publishedCase($this->region);
    Override::create([
        'assessment_id' => $wasOverridden->currentAssessment()->id,
        'auto_score' => $wasOverridden->currentAssessment()->base_score,
        'new_score' => 91.5,
        'reason_ar' => 'قرار لجنة سابق',
        'approved_by' => $admin->id,
    ]);

    $overdue = publishedCase($this->region);
    $overdue->forceFill(['next_assessment_due_at' => now()->subWeek()])->save();

    $rows = $this->ranking->fundingList('monthly', withCovered: true);

    expect($rows)->toHaveCount(4);

    foreach ($rows as $row) {
        $family = Beneficiary::with('assessments')->find($row['beneficiary_id']);

        expect($row['ranking'])->toBe(
            $this->ranking->rank($family, $row['confirmed']),
            'the flat row and the family disagree on file '.$row['file_number'],
        );

        expect($row['need'])->toBe($this->coverage->needAmount($family));
    }

    $scores = $rows->mapWithKeys(fn (array $r) => [$r['file_number'] => $r['ranking']['current_score']]);

    // The number somebody typed decides nothing, in either path.
    expect($scores[$wasOverridden->file_number])->not->toBe(91.5)
        ->and($scores[$wasOverridden->file_number])->toBe($scores[$plain->file_number]);
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
