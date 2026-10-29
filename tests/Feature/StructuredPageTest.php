<?php

use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Filament\Resources\PageResource\RelationManagers\SectionsRelationManager;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Setting;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\PageContentSeeder;
use Filament\Facades\Filament;

/*
 | T-113 — the association's «كيف نعمل» package of 19 October: three levels
 | on a page, editable from the panel, inside the platform rather than as a
 | separate HTML file.
 |
 | T-122 — and the other four, sent on 25 October with «المطلوب تعبئة
 | محتواهم»: شروط الاستخدام، الأسئلة الشائعة، الشفافية المالية، الرقابة
 | والتفتيش، and «كيف نعمل» rewritten.
 |
 | The thing being tested is the structure, not one page's words: the words
 | are now data, one file per page, and what has to hold is that any of them
 | draws, is editable, and costs the same few queries whether it carries
 | twenty-five sections or sixty.
 */

beforeEach(function () {
    seedCore();
    (new HomePageSeeder)->run();
});

it('draws the levels, the panels and the controls the association designed', function () {
    (new PageContentSeeder)->run();

    $body = $this->get('/how-we-work')->assertOk()->getContent();

    expect($body)->toContain('١. المختصر')
        ->and($body)->toContain('٢. التفاصيل')
        ->and($body)->toContain('٣. الأدلة')
        // The control line under a step, which is the point of their design.
        ->and($body)->toContain('من سجّل لا يعتمد تحقق نفسه.')
        ->and($body)->toContain('الوصول إلى العائلة والتسجيل')
        // A panel is a disclosure element, so it opens with no script at all.
        ->and($body)->toContain('<details')
        // And an anchor can send a reader to a level.
        ->and($body)->toContain('id="detail"')
        ->and($body)->toContain('id="evidence"');
});

it('is the page the platform draws, not an HTML file dropped beside it', function () {
    (new PageContentSeeder)->run();

    $body = $this->get('/how-we-work')->assertOk()->getContent();

    // «مهم يكون التنفيذ ضمن بنية المنصة الحالية»: the same bar, the same
    // footer, the same identity and the same right-to-left shell as every
    // other page.
    expect($body)->toContain('dir="rtl"')
        ->and($body)->toContain(config('brand.logo.full'))
        ->and($body)->toContain(__('sanabel.public.footer_nav'))
        // Their demonstration's own palette and typeface are not carried in.
        ->and($body)->not->toContain('#145c45')
        ->and($body)->not->toContain('Tahoma');
});

it('fills every page the association sent, and none that they did not', function () {
    (new PageContentSeeder)->run();

    foreach ([
        '/how-we-work' => 'المنهجية والإجراءات الكاملة',
        '/terms' => 'الشروط بالتفصيل',
        '/faq' => 'التعريف بالمنصة',
        '/financial-transparency' => 'كيف تتحقق من وضوح الأرقام؟',
        '/internal-oversight' => 'ماذا تراجع الرقابة؟ وكيف؟',
        // T-196 — «من نحن», sent on 29 October in the same shape as the
        // other five, and filled from the same kind of file.
        '/about' => 'رؤيتنا الرقمية وكيف نعمل على تحقيقها',
    ] as $url => $heading) {
        expect($this->get($url)->assertOk()->getContent())->toContain($heading);
    }

    // The one they have not written yet still says so rather than inventing
    // words for it.
    expect($this->get('/contact')->assertOk()->getContent())
        ->toContain('بانتظار نصّها من الجمعية');
});

it('carries all fifty questions, each with its own address', function () {
    (new PageContentSeeder)->run();

    $page = Page::firstWhere('slug', 'faq');

    expect($page->sections()->where('kind', 'accordion')->count())->toBe(50)
        ->and($page->sections()->where('kind', 'heading')->count())->toBe(10);

    $body = $this->get('/faq')->assertOk()->getContent();

    expect($body)->toContain('ما هي سنابل الوفاء؟')
        ->and($body)->toContain('id="answer-50"');
});

it('lets the association edit every level from the panel', function () {
    (new PageContentSeeder)->run();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();

    $page = Page::firstWhere('slug', 'how-we-work');
    $editor = userWithRole('content_manager');

    Livewire\Livewire::actingAs($editor)
        ->test(SectionsRelationManager::class, [
            'ownerRecord' => $page,
            'pageClass' => EditPage::class,
        ])
        ->assertSuccessful()
        // The rows themselves, by the title the editor reads in the list.
        ->assertSee('الفكرة في أقل من دقيقة');

    // And an edit reaches the public page without a release.
    $section = $page->sections()->where('kind', 'accordion')->first();
    $section->update(['body_ar' => 'عبارة كتبتها الجمعية بنفسها.']);

    expect($this->get('/how-we-work')->getContent())->toContain('عبارة كتبتها الجمعية بنفسها.');
})->group('AUTH-01');

it('hides a section the association unpublishes, and keeps the rest', function () {
    (new PageContentSeeder)->run();

    $page = Page::firstWhere('slug', 'how-we-work');
    $page->sections()->where('title_ar', 'التحقق الميداني المنفصل')->update(['is_published' => false]);

    $body = $this->get('/how-we-work')->assertOk()->getContent();

    expect($body)->not->toContain('من سجّل لا يعتمد تحقق نفسه.')
        ->and($body)->toContain('الوصول إلى العائلة والتسجيل');
});

it('reads the association own funding share into the example, not a number typed on a page', function () {
    (new PageContentSeeder)->run();

    Setting::put('funding_target_percent', 40);

    $body = $this->get('/how-we-work')->assertOk()->getContent();

    // 40% of 100,000 is the stage, and half of that is collected in the
    // worked example the page shows before any script runs.
    expect($body)->toContain('percent: 40')
        ->and($body)->toContain(number_format(40_000))
        ->and($body)->toContain(number_format(20_000));
});

it('works with the script switched off, which is what the package asked for', function () {
    (new PageContentSeeder)->run();

    $body = $this->get('/how-we-work')->assertOk()->getContent();

    /*
     | «اختبر … بانقطاع JavaScript: يبقى المحتوى مقروءًا وروابط الانتقال
     | متاحة». The panels are <details>, so they open; the levels are
     | anchors, so they jump; and the example's figures are computed in PHP
     | before Alpine sees them.
     */
    expect($body)->toContain('<summary')
        ->and($body)->toContain('href="#detail"')
        ->and($body)->toContain('style="width: 50%"');
});

it('refuses to put a content editor id straight into the markup', function () {
    $page = Page::firstOrCreate(['slug' => 'how-we-work'], ['title_ar' => 'كيف نعمل', 'is_published' => true]);

    $section = PageSection::create([
        'page_id' => $page->id,
        'kind' => 'document',
        'title_ar' => 'وثيقة',
        // Somebody pastes this into the anchor box.
        'anchor' => 'a" onload="alert(1)',
    ]);

    expect($section->anchorId())->toBe('aonloadalert1');

    $body = $this->get('/how-we-work')->assertOk()->getContent();

    expect($body)->not->toContain('onload=');
})->group('APP-02');

it('serves a page and its sections without asking a question per section', function () {
    (new PageContentSeeder)->run();

    DB::enableQueryLog();
    $this->get('/faq')->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Sixty-one sections, and the page is still a handful of queries: they
    // come back in one, which is what let the other four pages follow.
    expect(Page::firstWhere('slug', 'faq')->sections()->count())->toBeGreaterThan(60)
        ->and($queries)->toBeLessThan(12);
});

it('leaves a page the association has already edited alone', function () {
    (new PageContentSeeder)->run();

    $page = Page::firstWhere('slug', 'terms');
    $page->sections()->where('kind', 'card')->delete();
    $before = $page->sections()->count();

    // Running the seeder again on a filled page would overwrite their work.
    (new PageContentSeeder)->run();

    expect($page->sections()->count())->toBe($before);
});

it('keeps a line the association wrote over the placeholder', function () {
    // The page exists with the placeholder, somebody replaces the lead, and
    // the seeder must not put the mock-up's own words back over it.
    $page = Page::firstWhere('slug', 'terms');
    $page->update(['body_ar' => 'نصّ كتبته الجمعية بدل العبارة المؤقتة.']);

    (new PageContentSeeder)->run();

    expect($page->refresh()->body_ar)->toBe('نصّ كتبته الجمعية بدل العبارة المؤقتة.');
});
