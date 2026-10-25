<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | T-122 — «كيف نعمل» was filled in T-113 from the association's first
 | mock-up. On 25 October they sent a rewritten one, with new words, a new
 | lead and a worked example in place of the old panel.
 |
 | The seeder leaves a page alone once it has sections, which is right: a
 | deploy must never overwrite what the association has typed. So the old
 | rows have to go for the new ones to land, and the question is which rows
 | are theirs.
 |
 | `created_by` answers it. A section written from the panel carries the
 | account that wrote it; a seeded one carries nothing. So only the seeded
 | rows are removed, and a page the association has since edited keeps every
 | word of it — in which case the new text stays in the file and somebody
 | decides, rather than this migration deciding for them.
 |
 | SoftDeletes: the rows are not destroyed, they are set aside.
 */
return new class extends Migration
{
    public function up(): void
    {
        $page = DB::table('pages')->where('slug', 'how-we-work')->first();

        if (! $page) {
            return;
        }

        $edited = DB::table('page_sections')
            ->where('page_id', $page->id)
            ->whereNull('deleted_at')
            ->whereNotNull('created_by')
            ->exists();

        if ($edited) {
            return;
        }

        DB::table('page_sections')
            ->where('page_id', $page->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    public function down(): void
    {
        // The rows are still there, soft-deleted; nothing is restored blindly.
    }
};
