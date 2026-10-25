<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Campaign;
use App\Services\ProgressBand;
use Illuminate\Support\Facades\Blade;

/*
 | T-138 — «بالنسبه لنسبة الانجاز الحاملة الناقصه لازم يكون اللون تحت 40
 | بالمية اللون الاحمر, و من 41 الى 80 باللون البرتقالي, ومن 81 الى 99 اخضر
 | فاتح, اما المكتملة تكون باللون الاخضر الغامق», 28 October — asked of the
 | campaigns on 26 October and of the family bars now.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('names the four bands at the association boundaries', function () {
    expect(ProgressBand::of(0))->toBe('low')
        ->and(ProgressBand::of(39))->toBe('low')
        ->and(ProgressBand::of(40))->toBe('low')
        ->and(ProgressBand::of(41))->toBe('mid')
        ->and(ProgressBand::of(80))->toBe('mid')
        ->and(ProgressBand::of(81))->toBe('high')
        ->and(ProgressBand::of(99))->toBe('high')
        ->and(ProgressBand::of(100))->toBe('complete')
        ->and(ProgressBand::of(140))->toBe('complete');
});

it('draws them on a family card, not one colour for every percentage', function () {
    $family = publishedCase($this->region);

    $card = (new MaskedCaseResource($family))->resolve();

    foreach ([15 => 'low', 60 => 'mid', 90 => 'high', 100 => 'complete'] as $percent => $band) {
        $card['coverage_percent'] = $percent;

        $html = Blade::render('<x-masked-case-card :case="$case" />', ['case' => $card]);

        expect($html)->toContain('meter-fill-'.$band);
    }
});

it('keeps the campaign bar on the same four names', function () {
    $campaign = Campaign::factory()->create(['goal_amount' => 100_000, 'status' => 'active']);

    expect($campaign->progressBand(30))->toBe('low')
        ->and($campaign->progressBand(55))->toBe('mid')
        ->and($campaign->progressBand(95))->toBe('high')
        ->and($campaign->progressBand(100))->toBe('complete');
});

it('gives each band a colour of its own in the stylesheet', function () {
    $css = file_get_contents(base_path('resources/css/app.css'));

    foreach (['low', 'mid', 'high', 'complete'] as $band) {
        expect($css)->toContain('.meter-fill-'.$band)
            ->and($css)->toContain('--meter-'.$band);
    }
});
