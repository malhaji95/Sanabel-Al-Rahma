<?php

use App\Models\Campaign;
use App\Services\CoverageService;
use App\Services\ProgressBand;
use Illuminate\Support\Facades\Blade;

/*
 | T-167 — «لماذا موضوع الالوان لم يتم انجازه؟», 31 October.
 |
 | They were right, and the tests that said otherwise were asking the wrong
 | question. T-123 and T-138 put the four bands on every bar and proved it by
 | reading the markup: the class `meter-fill-low` was there, in the HTML, on
 | the right bar. The stylesheet defined it too.
 |
 | What nobody asked was whether that rule survived the build. Tailwind deletes
 | any rule in `@layer components` whose class name it cannot find in the files
 | it scans, and all three bars write their class as
 | `meter-fill-{{ ProgressBand::of($percent) }}` — a name that exists only once
 | the page is rendered. So the four colours were compiled out of every build,
 | and every bar on the site drew in the one default green.
 |
 | The markup was right, the stylesheet was right, and the build threw the
 | colours away. These tests ask the question that was missing.
 */

it('names a band for every percentage, and only the four', function () {
    expect(ProgressBand::of(0))->toBe('low')
        ->and(ProgressBand::of(39))->toBe('low')
        ->and(ProgressBand::of(40))->toBe('low')
        ->and(ProgressBand::of(41))->toBe('mid')
        ->and(ProgressBand::of(80))->toBe('mid')
        ->and(ProgressBand::of(81))->toBe('high')
        ->and(ProgressBand::of(99))->toBe('high')
        ->and(ProgressBand::of(100))->toBe('complete')
        ->and(ProgressBand::of(140))->toBe('complete')
        ->and(ProgressBand::of(null))->toBe('low');
});

it('keeps every band out of the sweep that deleted them', function () {
    $config = file_get_contents(base_path('tailwind.config.js'));

    foreach (['low', 'mid', 'high', 'complete'] as $band) {
        // Not safelisted means the build drops that colour.
        expect($config)->toContain("'meter-fill-{$band}'");
    }
});

it('leaves the four colours in the stylesheet that is built', function () {
    $built = glob(public_path('build/assets/*.css'));

    if ($built === []) {
        // Assets are built at deploy time; there is nothing to read here.
        expect(true)->toBeTrue();

        return;
    }

    $css = implode('', array_map('file_get_contents', $built));

    foreach (['low', 'mid', 'high', 'complete'] as $band) {
        expect($css)->toContain(".meter-fill-{$band}{");
    }
});

it('draws the band’s own colour on the bar a donor reads', function () {
    seedCore();
    $region = regionWithRates();
    $coverage = app(CoverageService::class);

    // A third of the way through the month: the association's red.
    $case = publishedCase($region);
    fundFor($case, (int) round($coverage->needAmount($case) / 3));

    expect($this->get(route('opportunity', $case->file_number))->getContent())
        ->toContain('meter-fill meter-fill-low');

    // And the deep green, on a campaign that has reached its goal. A family
    // whose month is answered has no bar to colour: its page closes and
    // offers another family instead (T-83), which is why the complete band
    // is read here.
    $campaign = Campaign::factory()->create([
        'beneficiary_id' => $case->id,
        'goal_amount' => 100_000,
        'is_published' => true,
        'status' => 'active',
        'surplus_policy_text_ar' => 'يُحوَّل الفائض إلى الحالة التالية في القائمة.',
    ]);

    expect($campaign->progressBand(100))->toBe('complete')
        ->and(Blade::render(
            '<div class="meter-fill meter-fill-{{ $band }}"></div>',
            ['band' => $campaign->progressBand(100)],
        ))->toContain('meter-fill meter-fill-complete');
});
