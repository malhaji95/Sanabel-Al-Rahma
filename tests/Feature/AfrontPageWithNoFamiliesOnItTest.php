<?php

use Database\Seeders\HomePageSeeder;

/*
 | T-265 — the morning of the launch.
 |
 | «حالات تحتاج دعمك اليوم» is drawn whether or not there is a family under
 | it, so on the day the association opens the site — and on any day they
 | have nothing published — the front page carried a promise and a blank
 | strip. It says so now, the way «الأخبار» three sections down always has.
 */
beforeEach(function () {
    seedCore();
    (new HomePageSeeder)->run();
});

it('says there is nothing open rather than opening on a blank strip', function () {
    $body = $this->get('/')->assertOk()->getContent();

    expect($body)->toContain(__('sanabel.public.needs_you_today'))
        ->and($body)->toContain(__('sanabel.public.no_open_cases'));
});

it('keeps the doors and the way to browse under it either way', function () {
    $body = $this->get('/')->assertOk()->getContent();

    // The visitor who came to give is not left without a door to walk
    // through: the eight tiles and the list are both still there.
    expect($body)->toContain(__('sanabel.public.all_cases'))
        ->and($body)->toContain('كفالة يتيم');
});

it('draws the families and not the line the moment one is published', function () {
    $case = publishedCase(regionWithRates());

    $body = $this->get('/')->assertOk()->getContent();

    expect($body)->toContain($case->file_number)
        ->and($body)->not->toContain(__('sanabel.public.no_open_cases'));
});
