<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Services\CoverageService;
use App\Services\DonationService;
use App\Services\RankingService;
use Illuminate\Support\Facades\DB;

/*
 | The public lists fetch confirmed support for every family in one query
 | instead of asking per family. That batch has to agree with the single-family
 | figure exactly, reversals included (rule 5), or donors are shown the wrong
 | coverage.
 */
beforeEach(function () {
    seedCore();
});

it('sums coverage in one query exactly as it does one family at a time', function () {
    $region = regionWithRates();
    $admin = userWithRole('admin');

    $paid = publishedCase($region);
    $reversed = publishedCase($region);
    $untouched = publishedCase($region);

    $give = function ($family, int $amount, string $ref) use ($admin) {
        $donation = app(DonationService::class)->record([
            'donor_id' => Donor::factory()->create()->id,
            'amount' => $amount,
            'transaction_ref' => $ref,
        ]);
        DonationAllocation::create([
            'donation_id' => $donation->id,
            'beneficiary_id' => $family->id,
            'amount' => $amount,
            'currency' => 'SYP',
        ]);

        return app(DonationService::class)->verify($donation, $admin->id);
    };

    $give($paid, 12_000, 'TRX-BATCH-A');
    $give($paid, 3_000, 'TRX-BATCH-B');
    app(DonationService::class)->reverse($give($reversed, 8_000, 'TRX-BATCH-C'), $admin->id, 'حوالة مكررة');

    $coverage = app(CoverageService::class);
    $families = collect([$paid, $reversed, $untouched])->map->fresh();
    $batch = $coverage->confirmedSupportForMany($families);

    foreach ($families as $family) {
        expect($batch[$family->getKey()] ?? 0)
            ->toBe($coverage->confirmedSupport($family));
    }

    expect($batch[$paid->getKey()])->toBe(15_000)
        ->and($batch[$reversed->getKey()] ?? 0)->toBe(0)
        ->and($batch[$untouched->getKey()] ?? 0)->toBe(0);
});

/*
 | The donor list is the page that broke the first demo deploy: it issued a
 | query per published family, so a page that was fine locally took ninety
 | seconds against a database in another region. What matters is not the exact
 | number but that it does not grow with the number of families.
 */
it('costs the same number of queries however many families are published', function () {
    $region = regionWithRates();

    $render = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(RankingService::class)->fundingList('monthly')
            ->take(12)
            ->each(fn (array $row) => (new MaskedCaseResource($row['beneficiary'], $row['confirmed']))->resolve());

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    foreach (range(1, 3) as $ignored) {
        publishedCase($region);
    }
    $few = $render();

    foreach (range(1, 12) as $ignored) {
        publishedCase($region);
    }
    $many = $render();

    expect($many)->toBe($few);
});
