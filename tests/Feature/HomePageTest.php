<?php

use App\Models\Banner;
use Filament\Facades\Filament;
use App\Models\ContentCard;
use App\Models\Page;
use App\Filament\Resources\ContentCardResource;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\PrivacyPolicySeeder;

/*
 | T-89 — the home page the association approved, and the thing that makes it
 | theirs: every band of it is rows they edit, so none of it is written in the
 | template. The tests below hold that — change a row, the page changes; take
 | a row away, the band goes with it.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    // Both, and in this order: the policy's page is its own seeder's, and the
    // home page's footer links to it.
    (new PrivacyPolicySeeder)->run();
    (new HomePageSeeder)->run();
});

it('draws every band of the page from rows the association edits', function () {
    Banner::create([
        'title_ar' => 'بيدك.. تصنع الأمل',
        'body_ar' => 'معاً نحيي حياة',
        'image' => 'images/home/hero-child.webp',
        'link' => '/cases',
        'is_published' => true,
        'sort_order' => 1,
    ]);

    $response = $this->get('/');

    $response->assertOk()
        // the hero
        ->assertSee('بيدك.. تصنع الأمل')
        // the two offers
        ->assertSee('الدفع المباشر')
        ->assertSee('هدية')
        // the ways to give
        ->assertSee('ترميم منزل')
        ->assertSee('كفالة يتيم')
        ->assertSee('زكاة واجبة')
        // the promises
        ->assertSee(__('sanabel.public.what_sets_us_apart'))
        ->assertSee('كرامة المحتاج أولويتنا')
        // the closing cards
        ->assertSee('الهيئة الشرعية')
        // the bar and the footer
        ->assertSee('الحملات')
        ->assertSee('الشفافية المالية');
});

it('changes when a card changes, and loses a band when its rows go', function () {
    $tile = ContentCard::block('tile')->first();

    $tile->update(['title_ar' => 'سلة غذائية']);

    $this->get('/')->assertOk()->assertSee('سلة غذائية');

    // Unpublished is gone from the page, and still there to put back.
    ContentCard::where('block', 'advantage')->update(['is_published' => false]);

    $this->get('/')->assertOk()
        ->assertDontSee(__('sanabel.public.what_sets_us_apart'))
        ->assertDontSee('كرامة المحتاج أولويتنا');
});

it('draws a card with no artwork as its icon rather than an empty frame', function () {
    // Seven of the design pack's thirteen pictures never arrived, so this is
    // the state half the tiles are actually in.
    $tile = ContentCard::block('tile')->where('icon', 'book')->first();

    expect($tile->image)->toBeNull()
        ->and($tile->imageUrl())->toBeNull();

    // The icon's own artwork, inlined — an <img> could not take the brand
    // colour, which is why the component inlines it.
    $this->get('/')->assertOk()->assertSee('<title>book</title>', false);
});

it('never puts a family photograph on the page, whatever the design shows', function () {
    /*
     | The design's case cards carry photographs of families. Rule 2 does not
     | bend for a layout: a masked card has no media, and the pictures on this
     | page belong to the ways to give — a category, not a household. The
     | design pack says the same of its own images.
     */
    $case = publishedCase($this->region);

    $body = $this->get('/')->assertOk()->getContent();

    expect($body)->not->toContain($case->first_name)
        ->and($body)->not->toContain($case->family_name)
        // Nothing on the page reaches the private media route.
        ->and($body)->not->toContain('/storage/media');
})->group('AUTH-03');

it('counts the five figures the design asks for, and counts them right', function () {
    $case = publishedCase($this->region);

    $response = $this->get('/');

    $response->assertOk()
        ->assertSee(__('sanabel.public.stat_families'))
        ->assertSee(__('sanabel.masked.priority.critical'))
        ->assertSee(__('sanabel.masked.priority.middle'))
        ->assertSee(__('sanabel.masked.priority.low'))
        ->assertSee(__('sanabel.public.stat_covered'));

    $stats = $response->viewData('stats');

    expect($stats['families'])->toBe(1)
        // Nothing has arrived for it, so it sits in the first band.
        ->and($stats['bands']['critical'])->toBe(1)
        ->and($stats['bands']['complete'])->toBe(0)
        ->and($stats['open'])->toBe(1)
        ->and(array_sum($stats['bands']))->toBe($stats['families']);
});

it('opens a page behind every link in the footer', function () {
    // A footer of 404s is worse than a shorter footer. The four pages the
    // association has still to write are opened carrying one line that says so.
    foreach (ContentCard::block('footer_link')->get() as $link) {
        $slug = ltrim($link->link, '/');

        expect(Page::where('slug', $slug)->where('is_published', true)->exists())
            ->toBeTrue($link->title_ar.' points at /'.$slug.', and there is no such page');

        $this->get($link->link)->assertOk();
    }
});

it('leaves the gift card without a button until there is somewhere to send it', function () {
    // Gift giving is not built. A button pointing somewhere approximate would
    // be a promise the system does not keep.
    $gift = ContentCard::block('feature')->where('title_ar', 'هدية')->first();

    expect($gift->link)->toBeNull();

    $this->get('/')->assertOk()->assertSee('هدية');
});

it('lets the content manager edit the page, and keeps everyone else out', function () {
    // The screen is new, and the guide for that role walks through it. A role
    // that cannot open it would make the guide a fiction — which is the kind
    // of thing the capture has caught three times now.
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    foreach (['content_manager', 'admin'] as $role) {
        $this->actingAs(userWithRole($role));

        expect(ContentCardResource::canAccess())->toBeTrue($role.' cannot open the home page screen');
    }

    // The board of trustees reads and does not touch, here as everywhere.
    $this->actingAs(userWithRole('council'));

    expect(ContentCardResource::canCreate())->toBeFalse()
        ->and(userWithRole('delegate')->can('update', ContentCard::block('tile')->first()))->toBeFalse();
})->group('AUTH-02');

it('keeps the campaigns screen with the role that holds the key to it', function () {
    /*
     | T-89 — the content manager's guide walked through the campaigns screen,
     | and the capture was answered 403. It was right to be: a campaign carries
     | a goal, a window and a surplus policy — money — and the content manager
     | holds `manage_cms` alone. The guide had been saying otherwise since the
     | guides were written.
     |
     | Pinned here rather than left to the next capture: if the association
     | later decides the content manager should run campaigns, this test is
     | what says the decision has been made rather than drifted into.
     */
    expect(userWithRole('content_manager')->can('viewAny', App\Models\Campaign::class))->toBeFalse()
        ->and(userWithRole('executive_director')->can('create', App\Models\Campaign::class))->toBeTrue();

    // And the guides must not promise a screen the account cannot open.
    $guides = require resource_path('training/guides.php');

    $paths = collect($guides['content_manager']['steps'])->pluck('path');

    expect($paths)->not->toContain('/admin/campaigns')
        ->and(collect($guides['executive_director']['steps'])->pluck('path'))
        ->toContain('/admin/campaigns');
})->group('AUTH-02');
