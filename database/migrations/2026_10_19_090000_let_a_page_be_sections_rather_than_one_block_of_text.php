<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-113 — the association's «كيف نعمل» package of 19 October: three levels
 | on a page, a quick summary, details that open and close, and links to the
 | policies — all of it editable from the panel, and «ما نكتفي برفع ملف HTML
 | منفصل».
 |
 | A page was one column of text, so it could carry the words and nothing
 | about their shape. This is the shape: a flat, ordered list of typed
 | sections, each with a kind that decides how it is drawn.
 |
 | Flat and ordered rather than nested, because nesting is a tree to edit and
 | a tree to render and the association asked for neither — a heading section
 | opens a block and the sections after it belong to it until the next one.
 | That is enough for the three levels, and it is the same structure for the
 | other six pages they said would follow, which is why it is a page_sections
 | table and not a how_we_work_sections one.
 |
 | Nothing here is specific to «كيف نعمل». The kinds are the shapes their
 | design uses, and a page that wants two of them uses two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('title_ar')->nullable();
            $table->text('body_ar')->nullable();
            // The highlighted line under an accordion: «ضابط العدالة: …».
            $table->text('rule_ar')->nullable();
            // The small chip on a reference card: «مسودة قانونية للمراجعة».
            $table->string('badge_ar')->nullable();
            $table->string('link')->nullable();
            $table->string('link_label_ar')->nullable();
            // An anchor, so one section can point at another on the same page.
            $table->string('anchor')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['page_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_sections');
    }
};
