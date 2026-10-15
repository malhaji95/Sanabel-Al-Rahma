<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-101 — the association asked, on 15 October, that a donor be told the
 | illness: «هل يوجد وضع صحي نعم لا … نعم نكتب المرض».
 |
 | The file already holds the illness, in `health_records.description_ar`,
 | and that column cannot be the one published. It is a paragraph the
 | researcher writes for the committee, and three of the privacy tests exist
 | because of what ends up in a paragraph: a doctor, a hospital, a quarter, a
 | name. Publishing it would hand a donor the one field nobody wrote for a
 | donor to read.
 |
 | So the illness becomes a choice from a list, beside the paragraph rather
 | than instead of it. A list can be published, because its values were
 | written here and not in the field: «سكري» is the whole of what the
 | association asked for, and carries nothing else with it. The paragraph
 | stays where it was, internal, and unchanged.
 |
 | It changes no computation. Severity, economic impact and care burden are
 | the banded columns the score reads, and this one is read by nobody —
 | deliberately, so that naming an illness cannot move a family up the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->string('condition')->nullable()->after('member_id');
        });
    }

    public function down(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->dropColumn('condition');
        });
    }
};
