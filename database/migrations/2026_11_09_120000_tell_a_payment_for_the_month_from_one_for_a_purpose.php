<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-280 — «كيفية معالجة المساعدات المنتظمة والموسمية والمؤقتة، والمساعدات
 * المخصصة لغرض معين», 9 November.
 *
 * Until now any payment another body made, once it carried an amount and a
 * month, came off that month's remaining need. That is right for a body that
 * pays towards the family's living, and wrong for one that pays a heating
 * bill, a course fee or a course of medicine: the family's food need did not
 * shrink because somebody bought them a stove, and the association was being
 * told to send them less.
 *
 * So a payment now says what it answers. `living` is the existing behaviour
 * and the default, including for every row written before today. `earmarked`
 * is recorded, shown, and deducted from nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_sources', function (Blueprint $table) {
            // living | earmarked
            $table->string('purpose')->default('living')->after('kind');
            $table->string('purpose_note_ar')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('support_sources', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'purpose_note_ar']);
        });
    }
};
