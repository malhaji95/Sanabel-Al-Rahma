<?php

use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Filament\Resources\PageResource\RelationManagers\SectionsRelationManager;
use App\Models\Page;
use App\Models\PageSection;
use Filament\Facades\Filament;

/*
 | T-118 — «بي الرقابة والتفتيش او الهيئة الشرعية نحتاج يكون فينا نرفع صورهم
 | مع اسماهم ضمنها … ممكن نقدر نخفي الصورة الي مابدو ياه تظهر وتكون لوغو
 | بدالها», 22 October.
 |
 | A committee is the one thing a page could not carry: every kind of section
 | was words. The answer is a kind that holds a face, a name and what the
 | person does, and one switch — because publishing a photograph of a real
 | person is something that person has to agree to, and the association asked
 | for the way to say no before they asked for anything else about it.
 */

beforeEach(function () {
    seedCore();

    $this->page = Page::create([
        'slug' => 'internal-oversight',
        'title_ar' => 'لجنة الرقابة والتفتيش الداخلي',
        'is_published' => true,
    ]);
});

function committeeMember(Page $page, array $attributes = []): PageSection
{
    return PageSection::create(array_merge([
        'page_id' => $page->id,
        'kind' => 'person',
        'title_ar' => 'أ. سمير الحوراني',
        'body_ar' => 'رئيس اللجنة',
        'image' => 'people/samir.jpg',
        'sort_order' => 1,
    ], $attributes));
}

it('puts the photograph and the name of a committee member on the page', function () {
    committeeMember($this->page);

    $body = $this->get('/internal-oversight')->assertOk()->getContent();

    expect($body)->toContain('أ. سمير الحوراني')
        ->and($body)->toContain('رئيس اللجنة')
        ->and($body)->toContain('people/samir.jpg');
});

it('draws the association mark instead of a face that may not be shown, and still names them', function () {
    // The whole of what they asked for: the member is published, their
    // photograph is not.
    committeeMember($this->page, ['show_image' => false]);

    $body = $this->get('/internal-oversight')->assertOk()->getContent();

    expect($body)->not->toContain('people/samir.jpg')
        ->and($body)->toContain(config('brand.logo.symbol'))
        // Named, which is the point: a hidden photograph is not a hidden person.
        ->and($body)->toContain('أ. سمير الحوراني')
        ->and($body)->toContain('رئيس اللجنة');
});

it('asks one question about whether a face is shown, so it cannot be answered twice', function () {
    $member = committeeMember($this->page, ['show_image' => false]);

    expect($member->imageUrl())->toBeNull();

    $member->update(['show_image' => true]);

    expect($member->refresh()->imageUrl())->toContain('people/samir.jpg');

    // And a member with no photograph at all reads the same as a hidden one.
    expect(committeeMember($this->page, ['image' => null])->imageUrl())->toBeNull();
});

it('serves a whole committee without asking a question per member', function () {
    foreach (range(1, 9) as $index) {
        committeeMember($this->page, [
            'title_ar' => 'عضو رقم '.$index,
            'image' => 'people/member-'.$index.'.jpg',
            'sort_order' => $index,
        ]);
    }

    DB::enableQueryLog();
    $body = $this->get('/internal-oversight')->assertOk()->getContent();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($body)->toContain('عضو رقم 9')
        ->and($queries)->toBeLessThan(12);
});

it('lets the association add a member from the panel, with the switch on the form', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();

    committeeMember($this->page);

    Livewire\Livewire::actingAs(userWithRole('content_manager'))
        ->test(SectionsRelationManager::class, [
            'ownerRecord' => $this->page,
            'pageClass' => EditPage::class,
        ])
        ->assertSuccessful()
        ->assertSee('أ. سمير الحوراني');

    expect(PageSection::KINDS)->toContain('person')
        ->and(__('sanabel.page_section.kinds.person'))->not->toBe('sanabel.page_section.kinds.person')
        ->and(__('sanabel.page_section.show_image'))->not->toBe('sanabel.page_section.show_image');
})->group('AUTH-01');

it('keeps a member off the page when the association unpublishes the row', function () {
    committeeMember($this->page, ['is_published' => false]);

    expect($this->get('/internal-oversight')->assertOk()->getContent())
        ->not->toContain('أ. سمير الحوراني');
});

it('refuses a name that is trying to be markup', function () {
    // The same guard every other kind has: a content editor types words, and
    // what they type is drawn as words.
    committeeMember($this->page, [
        'title_ar' => '<script>alert(1)</script>',
        'anchor' => 'a" onload="alert(1)',
    ]);

    $body = $this->get('/internal-oversight')->assertOk()->getContent();

    expect($body)->not->toContain('<script>alert(1)</script>')
        ->and($body)->not->toContain('onload=');
})->group('APP-02');
