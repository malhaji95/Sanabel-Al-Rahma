<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-287 — «بالنسبة للدخل الموسمي، نحتاج معرفة كيف يُحول إلى متوسط شهري»,
 * and «الموسمية او الجيران او الجمعيات ممكن تقسم على السنة او تكون وسطي»,
 * 10 November.
 *
 * Until now an income was steady and deducted, or it was not and was
 * documented and deducted from nothing. That is right for work that may or
 * may not come, and wrong for land rent collected once a year or a relative
 * who gives at both feasts: that money arrives, and the household lives on
 * it across the year.
 *
 * The screen told the researcher to divide it by twelve themselves. A sum
 * done in somebody's head is a sum nobody can audit, so the row says how
 * often instead and the engine does the division.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            // Null: it is not counted on, and nothing is deducted — as before.
            $table->unsignedSmallInteger('times_per_year')->nullable()->after('recurrence');
        });
    }

    public function down(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->dropColumn('times_per_year');
        });
    }
};
