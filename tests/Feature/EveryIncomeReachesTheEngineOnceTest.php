<?php

use App\Models\Asset;
use App\Models\Income;
use App\Models\Livestock;
use App\Models\SupportSource;
use App\Services\CoverageService;
use App\Services\MonthlyAverage;
use App\Services\NeedEngine;

/*
 | T-303 — «ربط جميع مصادر الدخل بمحرك الاحتساب تلقائيًا», 12 November.
 |
 | Their acceptance test, in their words: «إدخال أسرة لديها بقرة، وإيجار
 | عقار، ومساعدة جار شهرية، ومساعدة جمعية سنوية، والتأكد من ظهور كل مصدر
 | مرة واحدة في الحساب».
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->case = familyOf($this->region, adults: 2, children: 2);
    $this->case->forceFill(['work_income' => null, 'spouse_work_income' => null])->save();
    $this->engine = app(NeedEngine::class);
});

it('counts a cow, a let house, a neighbour and a yearly charity, each once', function () {
    // The cow: nine thousand a month, net of feed and care.
    Livestock::create([
        'beneficiary_id' => $this->case->getKey(),
        'kind' => 'cows', 'head_count' => 1,
        'income_kind' => 'main_income',
        'monthly_income' => 9_000, 'is_stable' => true,
    ]);

    // The house: thirty-six thousand, once a year.
    Asset::create([
        'beneficiary_id' => $this->case->getKey(),
        'kind' => 'house', 'necessity' => 'surplus', 'details_ar' => 'بيت مؤجَّر',
        'net_income' => 36_000, 'is_stable' => false, 'times_per_year' => 1,
    ]);

    // The neighbour: six thousand every month, confirmed as arriving.
    SupportSource::create([
        'beneficiary_id' => $this->case->getKey(),
        'name_ar' => 'جار', 'kind' => 'recurring',
        'amount' => 6_000, 'purpose' => 'living', 'is_confirmed' => true,
    ]);

    // The other association: twenty-four thousand, once a year.
    SupportSource::create([
        'beneficiary_id' => $this->case->getKey(),
        'name_ar' => 'جمعية', 'kind' => 'recurring',
        'amount' => 24_000, 'times_per_year' => 1, 'purpose' => 'living', 'is_confirmed' => true,
    ]);

    $need = $this->engine->compute($this->case->refresh());
    $snapshot = $need['snapshot'];

    expect($snapshot['livestock_income'])->toBe(9_000)
        ->and($snapshot['asset_income'])->toBe(3_000)       // 36,000 ÷ 12
        ->and($snapshot['support_income'])->toBe(8_000)     // 6,000 + 24,000 ÷ 12
        ->and($need['stable_income'])->toBe(20_000)
        // And nothing was also taken off the remaining need: a standing
        // arrangement is income, never coverage.
        ->and($snapshot['external_support'])->toBe(0);
});

it('does not count a promise nobody confirmed', function () {
    // «عدم احتساب الوعود غير المؤكدة كدخل».
    SupportSource::create([
        'beneficiary_id' => $this->case->getKey(),
        'name_ar' => 'وعد', 'kind' => 'recurring',
        'amount' => 500_000, 'purpose' => 'living',
    ]);

    expect($this->engine->compute($this->case->refresh())['stable_income'])->toBe(0);
});

it('never deducts the same help twice, once as income and once from the remaining', function () {
    /*
     | «منع خصم المساعدة نفسها مرتين، مرة من الدخل ومرة من المتبقي للتمويل».
     | The kind decides which of the two a row is, so the two are exclusive
     | by construction rather than by anybody remembering.
     */
    $month = app(CoverageService::class)->currentMonth();

    // A standing arrangement, carrying a month as well. It is income, and
    // the month on it changes nothing.
    SupportSource::create([
        'beneficiary_id' => $this->case->getKey(),
        'name_ar' => 'جمعية', 'kind' => 'recurring',
        'amount' => 10_000, 'purpose' => 'living', 'is_confirmed' => true,
        'coverage_month' => $month->toDateString(),
    ]);

    $need = $this->engine->compute($this->case->refresh());

    expect($need['snapshot']['support_income'])->toBe(10_000)
        ->and($need['snapshot']['external_support'])->toBe(0);
});

it('keeps a one-off payment as coverage, exactly as it was', function () {
    $month = app(CoverageService::class)->currentMonth();

    SupportSource::create([
        'beneficiary_id' => $this->case->getKey(),
        'name_ar' => 'جمعية', 'kind' => 'one_off',
        'amount' => 10_000, 'purpose' => 'living',
        'coverage_month' => $month->toDateString(),
    ]);

    $need = $this->engine->compute($this->case->refresh());

    expect($need['snapshot']['support_income'])->toBe(0)
        ->and($need['snapshot']['external_support'])->toBe(10_000);
});

it('counts a seasonal livestock income rather than dropping it', function () {
    // A flock sold once a year is money the household lives on all twelve
    // months. It used to be worth nothing at all.
    Livestock::create([
        'beneficiary_id' => $this->case->getKey(),
        'kind' => 'sheep', 'head_count' => 20,
        'income_kind' => 'main_income',
        'monthly_income' => 120_000, 'is_stable' => false, 'times_per_year' => 1,
    ]);

    expect($this->engine->compute($this->case->refresh())['snapshot']['livestock_income'])
        ->toBe(10_000);
});

it('leaves an unanswered steadiness at nought, and says nothing it does not know', function () {
    /*
     | The defect that started this: a researcher wrote the income and never
     | touched the switch beside it. The switch is a question now, and the
     | form will not take the row without an answer — but where a row exists
     | with none, the engine still refuses to guess.
     */
    Livestock::create([
        'beneficiary_id' => $this->case->getKey(),
        'kind' => 'cows', 'head_count' => 1,
        'income_kind' => 'main_income', 'monthly_income' => 150_000,
    ]);

    expect($this->engine->compute($this->case->refresh())['snapshot']['livestock_income'])->toBe(0);
});

it('applies one rule to all four, so «سنوي ÷ ١٢» means one thing', function () {
    // The shared rule, stated once and checked on its own.
    expect(MonthlyAverage::of(true, 9_000, null))->toBe(9_000)
        ->and(MonthlyAverage::of(false, 36_000, 1))->toBe(3_000)
        ->and(MonthlyAverage::of(false, 12_000, 4))->toBe(4_000)
        ->and(MonthlyAverage::of(false, 50_000, null))->toBe(0)
        ->and(MonthlyAverage::of(null, 50_000, null))->toBe(0)
        ->and(MonthlyAverage::of(true, 0, null))->toBe(0);
});

it('does not ask the researcher to write one source in two screens', function () {
    /*
     | «عدم اشتراط إدخال المصدر مرتين في شاشتين مختلفتين». The rent of a
     | house is counted from the assets table, where its screen puts it, and
     | not only when somebody also copies it into the income table.
     */
    Asset::create([
        'beneficiary_id' => $this->case->getKey(),
        'kind' => 'land', 'necessity' => 'surplus', 'details_ar' => 'أرض مؤجَّرة',
        'net_income' => 15_000, 'is_stable' => true,
    ]);

    expect($this->case->refresh()->incomes)->toHaveCount(0)
        ->and($this->engine->compute($this->case)['stable_income'])->toBe(15_000);
});

it('still counts an income row written by hand, beside the rest', function () {
    Income::create([
        'beneficiary_id' => $this->case->getKey(),
        'source_type' => 'إعانة', 'amount' => 7_000, 'is_stable' => true,
    ]);

    Asset::create([
        'beneficiary_id' => $this->case->getKey(),
        'kind' => 'car', 'necessity' => 'surplus', 'details_ar' => 'سيارة أجرة',
        'net_income' => 5_000, 'is_stable' => true,
    ]);

    $need = $this->engine->compute($this->case->refresh());

    expect($need['stable_income'])->toBe(12_000)
        ->and($need['snapshot']['asset_income'])->toBe(5_000);
});
