<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-118 — «بي الرقابة والتفتيش او الهيئة الشرعية نحتاج يكون فينا نرفع صورهم
 | مع اسماهم ضمنها … ممكن نقدر نخفي الصورة الي مابدو ياه تظهر وتكون لوغو
 | بدالها», 22 October.
 |
 | A page was a list of typed sections (T-113) and every kind of them carried
 | words only. A committee is people: a face, a name and what they do, and the
 | association asked for the one thing that makes publishing people possible —
 | a member who does not want their photograph published is drawn as the
 | association's own mark instead, and is still named.
 |
 | So a section may carry a picture, and a switch that says whether it is
 | shown. The switch defaults to on, which is what an editor who has just
 | uploaded a photograph means; turning it off leaves the name and puts the
 | logo in the frame.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_sections', function (Blueprint $table) {
            // The same shape as content_cards.image: a path on the public disk.
            $table->string('image')->nullable()->after('body_ar');
            $table->boolean('show_image')->default(true)->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('page_sections', function (Blueprint $table) {
            $table->dropColumn(['image', 'show_image']);
        });
    }
};
