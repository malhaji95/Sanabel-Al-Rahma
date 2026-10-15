<?php

use App\Filament\Resources\ContentCardResource;
use App\Models\Banner;
use App\Models\Campaign;
use App\Models\ContentCard;
use App\Models\Donation;
use App\Models\Page;
use App\Models\Setting;
use App\Services\DonorCaseList;
use App\Services\RankingService;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\PrivacyPolicySeeder;
use Filament\Facades\Filament;

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
        // T-103 — renamed on the association's own instruction of 15 October:
        // «الدفع المباشر» read as though the money went to the household
        // outside the association's books, which is the one thing it never
        // does.
        ->assertSee('تبرع عام')
        ->assertDontSee('الدفع المباشر')
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
    expect(userWithRole('content_manager')->can('viewAny', Campaign::class))->toBeFalse()
        ->and(userWithRole('executive_director')->can('create', Campaign::class))->toBeTrue();

    // And the guides must not promise a screen the account cannot open.
    $guides = require resource_path('training/guides.php');

    $paths = collect($guides['content_manager']['steps'])->pluck('path');

    expect($paths)->not->toContain('/admin/campaigns')
        ->and(collect($guides['executive_director']['steps'])->pluck('path'))
        ->toContain('/admin/campaigns');
})->group('AUTH-02');

it('answers a wrong address in Arabic, with a way back', function () {
    // Found on the live demo: a mistyped address met the server's own
    // «404 Not Found», in English, on a white page, with nothing to click.
    $response = $this->get('/no-such-page-at-all');

    $response->assertNotFound()
        ->assertSee(__('sanabel.errors.404_title'))
        ->assertSee(__('sanabel.errors.home'))
        // The page itself, not a bare string: right-to-left and Arabic.
        ->assertSee('dir="rtl"', false);
});

it('puts a slide in the hero out of the box', function () {
    // Also found on the live demo: nothing seeds a banner, so the first and
    // largest band of the page was simply absent there.
    expect(Banner::where('is_published', true)->exists())->toBeTrue();

    $this->get('/')->assertOk()->assertSee('بيدك.. تصنع الأمل');
});

/*
 | T-98 — what the association asked for on 15 October, after walking the demo
 | on a phone: a door to the zakat-eligible files beside the campaigns, and a
 | way to reach them.
 */

it('narrows the published list to the files a zakat payment may be spent on', function () {
    $region = regionWithRates();

    $eligible = publishedCase($region, attributes: ['zakat_category' => 'poor']);
    $notEligible = publishedCase($region);

    expect($eligible->zakat_category)->not->toBeNull()
        ->and($notEligible->zakat_category)->toBeNull();

    $ranking = app(RankingService::class);

    $all = $ranking->fundingList($eligible->support_type, withCovered: true)->pluck('beneficiary_id');
    $zakat = $ranking->fundingList($eligible->support_type, withCovered: true, zakatOnly: true)->pluck('beneficiary_id');

    expect($all)->toContain($eligible->id)
        ->and($all)->toContain($notEligible->id)
        // The judgement is the association's, recorded per file. A donor
        // paying zakat is shown only the files it may be spent on.
        ->and($zakat)->toContain($eligible->id)
        ->and($zakat)->not->toContain($notEligible->id);
})->group('AUTH-04');

it('serves the filtered list from its own cache, never the general one', function () {
    $region = regionWithRates();
    publishedCase($region, attributes: ['zakat_category' => 'poor']);
    publishedCase($region);

    $list = app(DonorCaseList::class);

    $all = $list->page('monthly', null, 1, 12);
    $zakat = $list->page('monthly', null, 1, 12, zakatOnly: true);

    // Two lists, two keys. Without that the second request is answered with
    // the first list's cards, which is the bug this is here to prevent.
    expect($all['total'])->toBeGreaterThan($zakat['total'])
        ->and($zakat['total'])->toBe(1);
})->group('APP-07');

it('keeps the zakat door on the bar and a way to reach the association in the footer', function () {
    $this->seed(HomePageSeeder::class);

    $nav = ContentCard::block('nav_link')->published()->pluck('link', 'title_ar');

    expect($nav)->toHaveKey('زكاة مستحقة')
        ->and($nav['زكاة مستحقة'])->toContain('zakat=1');

    $footer = ContentCard::block('footer_link')->published()->pluck('link', 'title_ar');

    expect($footer)->toHaveKey('تواصل معنا');

    // And the page behind it exists, so the link is not a dead end.
    expect(Page::where('slug', 'contact')->where('is_published', true)->exists())->toBeTrue();

    // The zakat tile lands on the zakat list rather than on every one-time
    // need: it is the one tile a donor could act on and be wrong about.
    $tile = ContentCard::block('tile')->where('title_ar', 'زكاة واجبة')->first();

    expect($tile?->link)->toContain('zakat=1');
})->group('APP-06');

/*
 | T-99 — the association's review of 15 October, walked on a phone.
 */

it('carries the approved logo itself in the bar and in the footer', function () {
    $html = $this->get('/')->assertSuccessful()->getContent();

    // The full lockup, not the name-only version: their instruction, and the
    // artwork is used as supplied rather than recomposed.
    expect(substr_count($html, config('brand.logo.full')))->toBeGreaterThanOrEqual(2)
        ->and($html)->not->toContain(config('brand.logo.wordmark'));
})->group('APP-06');

it('turns the hero by itself, and stops when a reader takes hold of it', function () {
    $this->seed(HomePageSeeder::class);

    expect(Banner::where('is_published', true)->count())->toBeGreaterThanOrEqual(5);

    $html = $this->get('/')->getContent();

    expect($html)->toContain('setInterval')
        // A reader pointing at a slide is reading it; it must not move.
        ->and($html)->toContain('x-on:mouseenter="stop()"')
        ->and($html)->toContain('prefers-reduced-motion');
})->group('APP-06');

it('counts the money from verified transfers only, never from pending ones', function () {
    $region = regionWithRates();
    publishedCase($region);

    Donation::factory()->create(['amount' => 70_000, 'status' => 'verified']);
    Donation::factory()->create(['amount' => 500_000, 'status' => 'pending']);

    $html = $this->get('/')->getContent();

    // A transfer the finance desk has not matched is not money yet, and a
    // front page that counted it would be making a claim the books cannot
    // support.
    expect($html)->toContain(number_format(70_000))
        ->and($html)->not->toContain(number_format(570_000));
})->group('APP-06', 'PAY-04');

it('lets the association switch any single counter off', function () {
    $region = regionWithRates();
    publishedCase($region);

    expect($this->get('/')->getContent())->toContain(__('sanabel.public.stat_donated'));

    Setting::put('stat_donated', false);

    $html = $this->get('/')->getContent();

    // One off, the rest standing.
    expect($html)->not->toContain(__('sanabel.public.stat_donated'))
        ->and($html)->toContain(__('sanabel.public.stat_families'));
})->group('APP-06');

it('shows the partners band at the foot of the page, and only once they add one', function () {
    $this->seed(HomePageSeeder::class);

    // Nothing is seeded: a partner is a real organisation with a real
    // agreement, and inventing three for a demonstration would put names on
    // their page that never agreed to be there.
    expect(ContentCard::block('partner')->count())->toBe(0)
        ->and($this->get('/')->getContent())->not->toContain(__('sanabel.public.partners'));

    ContentCard::create([
        'block' => 'partner',
        'title_ar' => 'جمعية شريكة',
        'link' => 'https://example.test',
        'is_published' => true,
        'sort_order' => 1,
    ]);

    $html = $this->get('/')->getContent();

    expect($html)->toContain(__('sanabel.public.partners'))
        ->and($html)->toContain('جمعية شريكة');
})->group('APP-06');

it('puts كفالات and زكاة on the bar, and still refuses a heading that leads nowhere', function () {
    $this->seed(HomePageSeeder::class);

    $nav = ContentCard::block('nav_link')->published()->pluck('link', 'title_ar');

    expect($nav)->toHaveKey('كفالات')
        ->and($nav['كفالات'])->toContain('supportType=monthly')
        ->and($nav)->toHaveKey('زكاة مستحقة')
        ->and($nav)->toHaveKey('الحملات')
        // «هدية» waits for the feature. A heading leading to a page that
        // cannot do what it says is worse than its absence.
        ->and($nav)->not->toHaveKey('هدية');
})->group('APP-06');
