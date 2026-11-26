<?php

use App\Models\ContentCard;
use Database\Seeders\HomePageSeeder;

/*
 | T-327 — «شكل الصفحة الرئيسية لغاية اللحظة غير مطابق للصفحة المطلوبة… اشتغل
 | على نفس الشكل تماما وتوزيع العناصر والصور وعدد الصور», 26 November, with
 | their approved page attached.
 |
 | Measured against that file rather than against a memory of it: it is nine
 | thousand pixels tall and ours was four, it carries bands this page never
 | had, and it puts the eight giving doors in one place where we had split
 | them around something else.
 |
 | This test is the record of the shape, so a later edit cannot quietly take
 | a band out or put one back in the wrong place.
 */

beforeEach(function () {
    seedCore();
    $this->seed(HomePageSeeder::class);
    $this->html = $this->get('/')->assertOk()->getContent();
});

it('draws the bands in the order their page draws them', function () {
    $order = ['id="features"', 'id="zakat"', 'id="health"', 'id="kifaya"', 'id="about"', 'id="faq"', 'id="news"'];

    $at = array_map(fn ($needle) => strpos($this->html, $needle), $order);

    foreach ($at as $index => $position) {
        expect($position)->not->toBeFalse("band {$order[$index]} is not on the page");
    }

    expect($at)->toBe(array_values(collect($at)->sort()->all()));
});

it('puts the eight doors of giving in one band and not two halves', function () {
    /*
     | They were four, then the promises, then four more. A reader choosing
     | between doors was being shown half of them, something else, and then
     | the rest.
     */
    $zakat = strpos($this->html, 'id="zakat"');
    $health = strpos($this->html, 'id="health"');

    $band = substr($this->html, $zakat, $health - $zakat);

    foreach (ContentCard::block('tile')->published()->pluck('title_ar') as $door) {
        expect($band)->toContain($door);
    }

    expect(ContentCard::block('tile')->published()->count())->toBe(8);
});

it('carries the bands their page has and this one never had', function () {
    expect($this->html)
        ->toContain(__('sanabel.public.kifaya_heading'))
        ->toContain(__('sanabel.public.donor_questions'))
        ->toContain(__('sanabel.public.news_updates'))
        ->toContain(__('sanabel.public.know_us'));
});

it('answers the four questions a first donation asks', function () {
    $faq = ContentCard::block('faq_home')->published()->get();

    expect($faq)->toHaveCount(4);

    foreach ($faq as $question) {
        expect($this->html)->toContain(e($question->title_ar))
            ->and($question->body_ar)->not->toBeEmpty();
    }
});

it('opens six doors under «تعرّف علينا», each to a page that exists', function () {
    $tiles = ContentCard::block('about_tile')->published()->get();

    expect($tiles)->toHaveCount(6);

    foreach ($tiles as $tile) {
        expect($this->html)->toContain($tile->link);

        $this->get($tile->link)->assertOk();
    }
});

it('runs the hero the width of the window at the artwork’s own ratio', function () {
    expect($this->html)->toContain('aspect-[8/3]')
        ->and($this->html)->toContain('calc(50% - 50vw)');
});

it('shows the approved artwork wherever their page shows a picture', function () {
    foreach ([
        'images/home/features-six.webp',
        'images/banners/sufficiency-path.webp',
    ] as $art) {
        expect($this->html)->toContain($art)
            ->and(file_exists(public_path($art)))->toBeTrue("missing artwork: {$art}");
    }
});

it('gives a telephone the bar of five their page gives it', function () {
    foreach (['account', 'cases', 'basket', 'gifts', 'favourites'] as $key) {
        expect($this->html)->toContain(__('sanabel.public.tabbar.'.$key));
    }

    // And the sticky donate button steps aside for it rather than landing on
    // top of it: from `sm` upwards only.
    expect($this->html)->toContain('hidden print:hidden sm:block');
});

it('opens a drawer of seven doors from the bar', function () {
    foreach (['home', 'giving', 'health', 'about', 'governance', 'faq', 'contact'] as $key) {
        expect($this->html)->toContain(__('sanabel.public.menu.'.$key));
    }
});
