<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Models\PageSection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/*
 | T-122 — the association's own pages, as data.
 |
 | They sent five interactive mock-ups on 25 October with «المطلوب تعبئة
 | محتواهم», which is the second half of what T-113 agreed: that page became
 | sections the association edits, and «بعد اعتماد الصفحة منعمم نفس الطريقة
 | على بقية الصفحات الست».
 |
 | The words live in database/data/pages/<slug>.json rather than in a seeder
 | each, for the same reason the region names and the privacy policy do
 | (CLAUDE.md §5): they are the association's text, they will be edited, and
 | the next page is a file rather than a class. Nothing here knows what any
 | page says.
 |
 | A page whose sections already exist is left exactly as it is. The
 | association edits these from the panel the moment they are published, and
 | a deploy that re-seeded them would throw that away — the same rule
 | HowWeWorkSeeder was written with, which this replaces.
 */
class PageContentSeeder extends Seeder
{
    public const DIRECTORY = 'data/pages';

    /**
     * The lines these pages were opened with and nobody has written over:
     * the footer's placeholder (T-99) and the demonstration sentence the
     * synthetic seeder leaves on «من نحن». Either is replaced by the
     * association's own text; a line they have since written is not.
     */
    private const PLACEHOLDERS = [
        'هذه الصفحة بانتظار نصّها من الجمعية.',
        'صفحة تعريفية تجريبية يحررها المدير من لوحة التحكم.',
    ];

    public function run(): void
    {
        foreach (File::glob(database_path(self::DIRECTORY.'/*.json')) as $path) {
            $this->publish(json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    private function publish(array $content): void
    {
        $page = Page::firstOrNew(['slug' => $content['slug']]);

        /*
         | The title and the lead are theirs too, and they replace the
         | placeholder — but never a line the association has since written
         | over it.
         */
        if (! $page->exists || in_array($page->body_ar, self::PLACEHOLDERS, true) || blank($page->body_ar)) {
            $page->fill([
                'title_ar' => $content['page']['title_ar'],
                'body_ar' => $content['page']['body_ar'] ?? null,
                'is_published' => true,
            ]);

            $page->sort_order ??= 50;
            $page->save();
        }

        if ($page->sections()->exists()) {
            $this->command?->line("  {$page->slug}: ".__('sanabel.page_section.plural').' — '
                .$page->sections()->count());

            return;
        }

        foreach ($content['sections'] as $section) {
            PageSection::create(array_merge(
                ['page_id' => $page->id, 'is_published' => true],
                array_filter($section, fn ($value) => $value !== null && $value !== ''),
            ));
        }

        $this->command?->info("  {$page->slug}: ".count($content['sections']));
    }
}
