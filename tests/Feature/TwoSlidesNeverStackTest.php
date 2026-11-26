<?php

use App\Models\Banner;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\PrivacyPolicySeeder;

/*
 | T-311 — «ليش عند تبديل الشرائح تظهر بهاد الشكل كأنه في خلل», 23 November,
 | with a screenshot of two banners stacked one under the other.
 |
 | The crossfade keeps the leaving slide displayed for its 400ms. While the
 | slides sat in the page's flow, those 400ms put two of them in the flow at
 | once and the hero grew to twice its height. The slides now lie on top of
 | one another in a single track whose height is fixed, so no second slide can
 | push the page open, however many banners there are.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    (new PrivacyPolicySeeder)->run();
    (new HomePageSeeder)->run();

    $this->published = Banner::where('is_published', true)->count();
    $this->body = $this->get(route('home'))->assertOk()->getContent();
});

it('gives the slides one track, and the track the height', function () {
    expect($this->published)->toBeGreaterThan(1)
        ->and(substr_count($this->body, 'relative aspect-[8/3] w-full'))->toBe(1)
        // The picture fills the track instead of setting its own height, which
        // is what let a second slide add to it.
        ->and($this->body)->toContain('h-full w-full object-cover')
        ->and($this->body)->not->toContain('h-[180px] w-full object-cover');
});

it('takes every slide out of the flow, so two can overlap but never stack', function () {
    $opens = preg_match_all(
        '/<div\s+x-show="slide === \d+"\s+x-transition[^>]*?class="([^"]*)"/s',
        $this->body,
        $m
    );

    expect($opens)->toBe($this->published);

    foreach ($m[1] as $class) {
        expect($class)->toBe('absolute inset-0');
    }
});

it('still shows the first slide alone when Alpine never runs', function () {
    /*
     | The guard T-295 settled: every slide after the first carries an inline
     | display:none, the one form x-show can undo. Lifting the slides out of
     | the flow must not quietly drop it, or a reader with no JavaScript would
     | see every picture piled on the first.
     */
    expect(substr_count($this->body, 'style="display:none;"'))->toBe($this->published - 1);
});
