<?php

use App\Models\Banner;
use App\Models\Page;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\PageContentSeeder;
use Database\Seeders\PrivacyPolicySeeder;

/*
 | T-310 — the association's five wide messages, on the front page at last.
 |
 | They are finished compositions: headline, sentence and button are drawn
 | into the artwork. The carousel was built for the other kind of picture, so
 | it lays a scrim over the frame and centres a heading and a button on it.
 | The row now says which kind it is, and the slide is rendered accordingly.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    (new PrivacyPolicySeeder)->run();
    (new HomePageSeeder)->run();
});

it('shows artwork that carries its own words bare, and links the whole slide', function () {
    Banner::query()->update(['is_published' => false]);

    $banner = Banner::where('title_ar', 'التقنية في خدمة العطاء')->firstOrFail();

    expect($banner->text_in_image)->toBeTrue()
        ->and($banner->body_ar)->toBeNull();

    $banner->update(['is_published' => true]);

    $html = $this->get('/')->assertOk()->getContent();

    // The picture describes itself to a screen reader, because its content is
    // words — an empty alt would tell a blind reader nothing at all.
    expect($html)->toContain('alt="'.$banner->title_ar.'"');

    // And nothing is written over it: no heading, no scrim, no second button.
    expect($html)->not->toContain('<h2 class="text-xl font-bold text-white');

    // The slide itself is the link its painted button promises.
    expect($html)->toContain('href="/about"');
});

it('still lays a heading and a scrim over a photograph', function () {
    Banner::query()->update(['is_published' => false]);

    $photo = Banner::where('title_ar', 'بيدك.. تصنع الأمل')->firstOrFail();

    expect($photo->text_in_image)->toBeFalse();

    $photo->update(['is_published' => true]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain($photo->title_ar)
        ->and($html)->toContain('linear-gradient');
});

it('seeds the five wide messages, each pointing where its own words promise', function () {
    (new PageContentSeeder)->run();

    $wide = Banner::where('text_in_image', true)->orderBy('sort_order')->get();

    expect($wide)->toHaveCount(5);

    foreach ($wide as $banner) {
        expect($banner->image)->toStartWith('images/banners/')
            ->and(file_exists(public_path($banner->image)))->toBeTrue("missing: {$banner->image}")
            ->and($banner->destination())->not->toBeNull();

        // A slide whose button goes nowhere is the lie this guards against:
        // every destination is a page that really exists and really answers.
        $slug = ltrim((string) $banner->destination(), '/');

        expect(Page::where('slug', $slug)->where('is_published', true)->exists())
            ->toBeTrue("no published page at /{$slug} for «{$banner->title_ar}»");
    }
});

it('leaves the five behind the slides that were already there', function () {
    $first = Banner::where('text_in_image', false)->max('sort_order');
    $wide = Banner::where('text_in_image', true)->min('sort_order');

    expect($wide)->toBeGreaterThan($first);
});
