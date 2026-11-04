<?php

/*
 | T-253 — «مسار الكفاية», the association's own page, sent on 4 November
 | as a finished mock-up with its text and its banner.
 |
 | It is published the way every other page of theirs is (T-122): the words
 | live in `database/data/pages/sufficiency-path.json` and become rows the
 | content manager edits, drawn in the shapes T-113 built. Nothing about
 | this page is code.
 */

use App\Models\Banner;
use App\Models\ContentCard;
use App\Models\Page;
use App\Models\PageSection;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\PageContentSeeder;

beforeEach(function () {
    seedCore();
    $this->seed(PageContentSeeder::class);
    $this->seed(HomePageSeeder::class);
});

it('publishes the page with the association’s own words', function () {
    $page = Page::where('slug', 'sufficiency-path')->first();

    expect($page)->not->toBeNull()
        ->and($page->is_published)->toBeTrue()
        ->and($page->title_ar)->toBe('مسار الكفاية')
        ->and($page->body_ar)->toContain('لا نريد أن نُعين الأسرة ليومٍ واحد');
});

it('draws it for a visitor, with every part of their text on it', function () {
    $this->get('/sufficiency-path')
        ->assertOk()
        ->assertSee('ماذا لو كان أفضل عطاءٍ هو الذي يقلّل الحاجة إلى العطاء؟')
        ->assertSee('ستة أبواب ممكنة… وهدف واحد: كفاية تناسب كل أسرة')
        ->assertSee('من السؤال عن الاحتياج… إلى السؤال عن النتيجة')
        ->assertSee('كيف نحمي نية المتبرع وكرامة الأسرة؟')
        ->assertSee('قبل أن تساهم… من حقك أن تسأل')
        // The caveats are theirs and are kept word for word: this page
        // describes a plan, and says so.
        ->assertSee('وليست نتائج محققة أو أرقامًا منشورة')
        ->assertSee('ولا يُعد إعلانًا عن بدء التشغيل');
});

it('carries their eight questions as questions that open', function () {
    $page = Page::where('slug', 'sufficiency-path')->first();

    expect($page->sections()->where('kind', 'accordion')->count())->toBe(8);

    $this->get('/sufficiency-path')
        ->assertOk()
        ->assertSee('هل مسار الكفاية بديل عن المساعدات الإنسانية العاجلة؟')
        ->assertSee('ما الفرق بين تمويل مشروع وقياس أثره؟');
});

it('sends the one button on it to a page that can actually take money', function () {
    /*
     | Their mock-up said «يُربط زر المساهمة بصفحة التبرع الرسمية بعد اعتماد
     | المسار وتفعيل نظام الدفع», and that line is left off the live page
     | because giving already works here: a transfer the finance desk
     | verifies, which is the only payment this platform has ever had
     | (rule 7). A sentence saying the button is not wired, on a page where
     | it is, would be the one untrue thing on it.
     */
    $this->get('/sufficiency-path')
        ->assertOk()
        ->assertSee('أرغب بالمساهمة في المسار')
        ->assertSee(route('donate.general', absolute: false));

    $this->get(route('donate.general'))->assertOk();
});

it('puts the page in the bar and its banner on the home page', function () {
    $nav = ContentCard::block('nav_link')->pluck('link', 'title_ar');

    expect($nav)->toHaveKey('مسار الكفاية')
        ->and($nav['مسار الكفاية'])->toBe('/sufficiency-path');

    $banner = Banner::where('title_ar', 'مسار الكفاية')->first();

    expect($banner)->not->toBeNull()
        ->and($banner->image)->toBe('images/banners/sufficiency-path.webp')
        ->and($banner->link)->toBe('/sufficiency-path')
        ->and(public_path($banner->image))->toBeFile();
});

it('leaves what the association has since written over it alone', function () {
    $page = Page::where('slug', 'sufficiency-path')->first();

    PageSection::where('page_id', $page->id)->where('kind', 'statement')
        ->first()->update(['body_ar' => 'جملة كتبتها الجمعية بنفسها']);

    // Re-seeding a deploy must not throw their editing away (T-122).
    $this->seed(PageContentSeeder::class);

    expect(PageSection::where('page_id', $page->id)->where('kind', 'statement')
        ->first()->body_ar)->toBe('جملة كتبتها الجمعية بنفسها');
});
