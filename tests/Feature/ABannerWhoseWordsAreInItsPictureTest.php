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
    /*
     | T-327 unpublished the six slides that came before the association's own
     | artwork, and «بيدك.. تصنع الأمل» was one of them — so the photograph
     | this test is about is now made here rather than looked up. The rule it
     | guards is unchanged: a picture that does not carry its own words still
     | gets the heading and the scrim drawn over it.
     */
    Banner::query()->update(['is_published' => false]);

    $photo = Banner::create([
        'title_ar' => 'بيدك.. تصنع الأمل',
        'body_ar' => 'معاً نحيي حياة',
        'image' => 'images/home/hero-child.webp',
        'link' => '/cases',
        'text_in_image' => false,
        'is_published' => true,
        'sort_order' => 1,
    ]);

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

it('leaves the association’s own artwork alone in the hero', function () {
    /*
     | T-310 put the five wide messages behind the six slides that were
     | already there. T-327 took those six off the page — their approved
     | front page carries these five and nothing else — so what this test
     | asks has changed with it: not «are they last», but «are they all».
     */
    $published = Banner::where('is_published', true)->get();

    expect($published)->toHaveCount(5)
        ->and($published->every(fn ($banner) => $banner->text_in_image))->toBeTrue()
        ->and($published->pluck('title_ar')->all())->toContain('التقنية في خدمة العطاء');

    // A fresh install never makes the six at all, and an install that
    // already has one keeps the row and loses only the switch — so the
    // association can bring it back without us.
    Banner::create([
        'title_ar' => 'بيدك.. تصنع الأمل',
        'image' => 'images/home/hero-child.webp',
        'link' => '/cases',
        'is_published' => true,
        'sort_order' => 1,
    ]);

    (new HomePageSeeder)->run();

    $old = Banner::where('title_ar', 'بيدك.. تصنع الأمل')->first();

    expect($old)->not->toBeNull()
        ->and($old->is_published)->toBeFalse();
});
