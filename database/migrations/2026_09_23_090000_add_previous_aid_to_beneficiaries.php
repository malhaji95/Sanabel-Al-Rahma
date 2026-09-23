<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The family file is meant to record المساعدات السابقة — aid the family
 * received before this platform, or from anyone outside it. Nothing in the
 * system knows about that, so a delegate had nowhere to write it down.
 *
 * Free text on purpose: it is a note taken on a visit, not a figure the need
 * engine reads. Nothing computes from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->text('previous_aid_ar')->nullable()->after('support_type');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn('previous_aid_ar');
        });
    }
};
