<?php

use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Filament\Resources\PageResource\RelationManagers\SectionsRelationManager;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Setting;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\HowWeWorkSeeder;
use Filament\Facades\Filament;

/*
 | T-113 — the association's «كيف نعمل» package of 19 October: three levels
 | on a page, editable from the panel, inside the platform rather than as a
 | separate HTML file.
 |
 | The thing being tested is the structure, not that one page's words: they
 | said the other six pages follow the same way, so a page is a flat ordered
 | list of typed sections and «كيف نعمل» is the first one filled in.
 */

beforeEach(function () {
    seedCore();
    (new HomePageSeeder)->run();
});

it('draws the three levels, the panels and the controls the association designed', function () {
    (new HowWeWorkSeeder)->run();

    $body = $this->get('/how-we-work')->assertOk()->getContent();

    expect($body)->toContain('المستوى الأول')
        ->and($body)->toContain('المستوى الثاني')
        ->and($body)->toContain('المستوى الثالث')
        // The control line under a step, which is the point of their design.
        ->and($body)->toContain('ضابط العدالة')
        ->and($body)->toContain('مسار العائلة')
        ->and($body)->toContain('مسار التبرع')
        // A panel is a disclosure element, so it opens with no script at all.
        ->and($body)->toContain('<details')
        // And an anchor can send a reader to one.
        ->and($body)->toContain('id="family1"')
        ->and($body)->toContain('id="doc-privacy"');
});

it('is the page the platform draws, not an HTML file dropped beside it', function () {
    (new HowWeWorkSeeder)->run();

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

it('lets the association edit every level from the panel', function () {
    (new HowWeWorkSeeder)->run();

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
        ->assertSee('نعرف الحاجة قبل الحكم عليها');

    // And an edit reaches the public page without a release.
    $section = $page->sections()->where('kind', 'statement')->first();
    $section->update(['body_ar' => 'عبارة كتبتها الجمعية بنفسها.']);

    expect($this->get('/how-we-work')->getContent())->toContain('عبارة كتبتها الجمعية بنفسها.');
})->group('AUTH-01');

it('hides a section the association unpublishes, and keeps the rest', function () {
    (new HowWeWorkSeeder)->run();

    $page = Page::firstWhere('slug', 'how-we-work');
    $page->sections()->where('anchor', 'family1')->update(['is_published' => false]);

    $body = $this->get('/how-we-work')->assertOk()->getContent();

    expect($body)->not->toContain('التسجيل الميداني')
        ->and($body)->toContain('التحقق المستقل');
});

it('reads the association own funding share into the example, not a number typed on a page', function () {
    (new HowWeWorkSeeder)->run();

    Setting::put('funding_target_percent', 40);

    $body = $this->get('/how-we-work')->assertOk()->getContent();

    // 40% of 100,000 is the stage, and half of that is collected in the
    // worked example the page shows before any script runs.
    expect($body)->toContain('percent: 40')
        ->and($body)->toContain(number_format(40_000))
        ->and($body)->toContain(number_format(20_000));
});

it('works with the script switched off, which is what the package asked for', function () {
    (new HowWeWorkSeeder)->run();

    $body = $this->get('/how-we-work')->assertOk()->getContent();

    /*
     | «اختبر … بانقطاع JavaScript: يبقى المحتوى مقروءًا وروابط الانتقال
     | متاحة». The panels are <details>, so they open; the levels are
     | anchors, so they jump; and the example's figures are computed in PHP
     | before Alpine sees them.
     */
    expect($body)->toContain('<summary')
        ->and($body)->toContain('href="#short"')
        ->and($body)->toContain('style="width: 50%"');
});

it('refuses to put a content editor id straight into the markup', function () {
    $page = Page::firstWhere('slug', 'how-we-work');

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
    (new HowWeWorkSeeder)->run();

    DB::enableQueryLog();
    $this->get('/how-we-work')->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Thirty-seven sections, and the page is still a handful of queries: the
    // sections come back in one, as they must before six more pages follow.
    expect(PageSection::count())->toBeGreaterThan(30)
        ->and($queries)->toBeLessThan(12);
});

it('leaves a page the association has already edited alone', function () {
    (new HowWeWorkSeeder)->run();

    $page = Page::firstWhere('slug', 'how-we-work');
    $page->sections()->where('kind', 'card')->delete();
    $before = $page->sections()->count();

    // Running the seeder again on a filled page would overwrite their work.
    (new HowWeWorkSeeder)->run();

    expect($page->sections()->count())->toBe($before);
});
