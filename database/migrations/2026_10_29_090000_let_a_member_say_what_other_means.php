<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-155 — «عند اختيار الصفة اخرى لازم يتم حقل لكتابة بشكل حر», 29 October.
 *
 * The kinship list has had its own box since the beginning: pick «أخرى» and
 * the form asks which. The status list — the one T-129 made decide what is
 * asked next — did not, so «أخرى» was the end of the question and the file
 * carried a household member nobody could describe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $table->string('occupation_other_ar')->nullable()->after('occupation');
        });
    }

    public function down(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $table->dropColumn('occupation_other_ar');
        });
    }
};
