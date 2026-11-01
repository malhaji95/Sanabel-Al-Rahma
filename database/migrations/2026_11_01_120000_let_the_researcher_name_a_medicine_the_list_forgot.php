<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-222 — «اعمل خيار غير ذلك بحيث يفتح نص حر ينكتب فيه», 1 November.
 |
 | The six kinds of medicine were our wording, not the association's, and
 | they were asked to review the list. Their answer was not a seventh word:
 | it was that a closed list of six cannot hold what a field researcher
 | meets, and the way out of a list is a box.
 |
 | The same shape `missing_items_other_ar` already has on housing (T-169),
 | so a researcher who learns one learns both.
 |
 | What is typed here never reaches a donor: the card prints labels out of
 | the association's own list and nothing else (rule 2). This box is for the
 | committee reading the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->string('medicine_kinds_other_ar')->nullable()->after('medicine_kinds');
        });
    }

    public function down(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->dropColumn('medicine_kinds_other_ar');
        });
    }
};
