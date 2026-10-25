<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use App\Models\Donor;
use App\Models\Setting;
use App\Services\BasketService;
use App\Services\CoverageService;
use App\Services\DonationService;
use Illuminate\Support\Facades\Blade;

/*
 | T-140 — «ملف الاسرة … المبلغ الشهري هذا لازم يكون اللي احنا نحدده للتمويل,
 | مثلا حاجته مليون رح نموله ب 300 الف, اللي يظهر له 300 الاف, ونسبة التغطية
 | اللي معروض بالشاشة الخارجية حسب التبرع اللي يجييه», 28 October.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    // The association funds a third of the need this month.
    Setting::put('funding_target_percent', 30);
});

/**
 * Pays an amount into one family's month and has it verified — the way a
 * donor's money actually arrives: through a basket, and never to the family.
 */
function fundTheStage(Beneficiary $family, int $amount, string $ref): void
{
    $donor = Donor::factory()->create();

    $baskets = app(BasketService::class);
    $basket = $baskets->currentFor($donor);
    $baskets->addItem($basket, $family, $amount);
    $baskets->reserve($basket);

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'amount' => $amount,
        'transaction_ref' => $ref,
        'basket_id' => $basket->id,
    ]);

    app(DonationService::class)->verify($donation, userWithRole('finance')->id);
}

it('measures the coverage a donor reads against the stage, not the whole need', function () {
    $family = publishedCase($this->region);
    $coverage = app(CoverageService::class);

    $need = $coverage->needAmount($family);
    $target = $coverage->targetAmount($family);

    expect($target)->toBeGreaterThan(0)
        ->and($target)->toBeLessThan($need);

    // Half the stage arrives.
    fundTheStage($family, intdiv($target, 2), 'TRX-STAGE-HALF');

    $family->refresh();

    expect($coverage->targetCoveragePercent($family))->toBeGreaterThanOrEqual(48)
        ->and($coverage->targetCoveragePercent($family))->toBeLessThanOrEqual(52)
        // And the real need is still read where it decides who is paid first.
        ->and($coverage->coveragePercent($family))->toBeLessThan(20);
});

it('shows the stage amount and its remainder on the card', function () {
    $family = publishedCase($this->region);

    $card = (new MaskedCaseResource($family))->resolve();

    $html = Blade::render('<x-masked-case-card :case="$case" />', ['case' => $card]);

    expect($html)->toContain(number_format($card['target_amount']))
        ->toContain(__('sanabel.funding.target_amount'))
        // The percentage drawn is the stage's.
        ->toContain($card['target_coverage_percent'].'%');
});

it('keeps the whole monthly need on the card, in the association sentence', function () {
    $family = publishedCase($this->region);
    Setting::put('show_sufficiency_note', true);

    $card = (new MaskedCaseResource($family))->resolve();

    $html = Blade::render('<x-masked-case-card :case="$case" />', ['case' => $card]);

    // «الكفاية التامة لهذه الأسرة … وهدف الدعم الحالي يمثّل :percent% منها»
    expect($html)->toContain(number_format($card['need_amount']))
        ->toContain('30%');
});

it('leaves a file with no stage reading the whole need, as before', function () {
    Setting::put('funding_target_percent', 100);

    $family = publishedCase($this->region);
    $card = (new MaskedCaseResource($family))->resolve();

    expect($card['target_percent'])->toBe(100)
        ->and($card['target_coverage_percent'])->toBe($card['coverage_percent']);

    $html = Blade::render('<x-masked-case-card :case="$case" />', ['case' => $card]);

    expect($html)->toContain(number_format($card['remaining_amount']));
});

it('calls the stage complete once its amount has arrived, and says so', function () {
    $family = publishedCase($this->region);
    $target = app(CoverageService::class)->targetAmount($family);

    fundTheStage($family, $target, 'TRX-STAGE-FULL');

    $card = (new MaskedCaseResource($family->refresh()))->resolve();

    expect($card['target_complete'])->toBeTrue()
        ->and($card['target_coverage_percent'])->toBe(100);

    $html = Blade::render('<x-masked-case-card :case="$case" />', ['case' => $card]);

    expect($html)->toContain(__('sanabel.funding.good_news_complete'));
});
