<?php

use App\Models\Beneficiary;
use App\Models\SupportSource;
use App\Services\AssessmentService;
use App\Services\CoverageService;

/*
 | T-280 — «المساعدات المخصصة لغرض معين», 9 November.
 |
 | Any payment with an amount and a month used to come off that month's need.
 | That is right for a body paying towards the family's living and wrong for
 | one paying a heating bill: the family's food need did not shrink, and the
 | association was being told to send them less.
 */
beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** One payment against this month, of the given purpose. */
function supportOf(Beneficiary $case, string $purpose, int $amount = 20000): SupportSource
{
    return SupportSource::create([
        'beneficiary_id' => $case->id,
        'name_ar' => 'جهة داعمة',
        'kind' => 'one_off',
        'purpose' => $purpose,
        'purpose_note_ar' => $purpose === 'earmarked' ? 'فاتورة تدفئة' : null,
        'amount' => $amount,
        'currency' => config('sanabel.currency'),
        'coverage_month' => now()->startOfMonth()->toDateString(),
        'paid_on' => now()->toDateString(),
    ]);
}

it('takes a payment towards living off the month, as it always did', function () {
    $case = publishedCase($this->region);
    supportOf($case, 'living');

    expect(app(CoverageService::class)->externalForMonth($case->refresh()))->toBe(20000);
});

it('takes a payment for one named thing off nothing', function () {
    $case = publishedCase($this->region);
    supportOf($case, 'earmarked');

    expect(app(CoverageService::class)->externalForMonth($case->refresh()))->toBe(0);
});

it('leaves the need, the deficit and the score untouched by either kind', function () {
    $case = publishedCase($this->region, adults: 2, children: 3);
    $before = $case->currentAssessment();

    supportOf($case, 'earmarked');
    app(AssessmentService::class)->create($case->refresh(), status: 'approved');
    $after = $case->refresh()->currentAssessment();

    expect($after->monthly_need)->toBe($before->monthly_need)
        ->and($after->stable_income)->toBe($before->stable_income)
        // And unlike living support, the remaining gap does not move either.
        ->and($after->gap)->toBe($before->gap)
        ->and($after->base_score)->toBe($before->base_score);
});

it('reads the same figure whether the rows are loaded or queried', function () {
    $case = publishedCase($this->region);
    supportOf($case, 'living', 15000);
    supportOf($case, 'earmarked', 90000);

    $coverage = app(CoverageService::class);

    $queried = $coverage->externalForMonth($case->refresh());
    $loaded = $coverage->externalForMonth($case->fresh()->load('supportSources'));
    $many = $coverage->externalForMonthForIds([$case->id]);

    expect($queried)->toBe(15000)
        ->and($loaded)->toBe(15000)
        ->and($many[$case->id] ?? 0)->toBe(15000);
});

it('treats a row that names no purpose as living support, which is what every older row is', function () {
    $case = publishedCase($this->region);

    // Written the way a row was written before the column existed: the
    // database's own default answers for it, so nothing changes behaviour
    // under the association's existing records.
    $id = DB::table('support_sources')->insertGetId([
        'beneficiary_id' => $case->id,
        'name_ar' => 'جهة داعمة قديمة',
        'amount' => 20000,
        'currency' => config('sanabel.currency'),
        'coverage_month' => now()->startOfMonth()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(SupportSource::find($id)->purpose)->toBe('living')
        ->and(app(CoverageService::class)->externalForMonth($case->refresh()))->toBe(20000)
        ->and(app(CoverageService::class)->externalForMonthForIds([$case->id])[$case->id] ?? 0)->toBe(20000);
});

it('shows the earmarked payment on the breakdown rather than hiding it', function () {
    $case = publishedCase($this->region);
    supportOf($case, 'earmarked');
    app(AssessmentService::class)->create($case->refresh(), status: 'approved');

    // The screen reads the study's own snapshot, and the snapshot counts only
    // what was deducted — so the figure there is zero, not the 20,000.
    $breakdown = $case->refresh()->currentAssessment()->needBreakdown();

    expect($breakdown['external'])->toBe(0)
        ->and($breakdown['totals']['gap'])->toBe($breakdown['totals']['deficit']);
});

it('writes living support when the purpose is left out or sent empty', function () {
    $case = publishedCase($this->region);

    // The case the full suite caught: a caller that sends the key with
    // nothing in it. The column is NOT NULL, so this used to be a 500.
    $blank = SupportSource::create([
        'beneficiary_id' => $case->id,
        'name_ar' => 'جمعية البر',
        'kind' => 'one_off',
        'purpose' => null,
        'amount' => 30000,
        'currency' => config('sanabel.currency'),
        'coverage_month' => now()->startOfMonth()->toDateString(),
    ]);

    expect($blank->fresh()->purpose)->toBe('living')
        ->and($blank->fresh()->isPayment())->toBeTrue()
        ->and(app(CoverageService::class)->externalForMonth($case->refresh()))->toBe(30000);
});

it('refuses a purpose it does not know rather than storing it', function () {
    $case = publishedCase($this->region);

    $row = SupportSource::create([
        'beneficiary_id' => $case->id,
        'name_ar' => 'جهة',
        'purpose' => 'whatever',
        'amount' => 1000,
        'currency' => config('sanabel.currency'),
        'coverage_month' => now()->startOfMonth()->toDateString(),
    ]);

    // Anything but the two the association named is living support, so no
    // row can quietly stop being deducted because of a typo.
    expect($row->fresh()->purpose)->toBe('living');
});
