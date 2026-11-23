<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-320 — phase two, item 3: «مهلة تصعيد».
 |
 | T-240 gave the data desk a working-day window and a morning that chases it.
 | A complaint had no such thing: the follow-up screen shows it waiting, and
 | whoever does not open that screen does not know.
 |
 | One column, as on the change request: the moment it was chased. Its absence
 | is what makes the query find it, so a complaint answered before its window
 | runs out is never chased at all, and one already chased is not chased every
 | morning after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', fn (Blueprint $table) => $table
            ->timestamp('alerted_at')->nullable()->after('appealed_from_owner_id'));
    }

    public function down(): void
    {
        Schema::table('complaints', fn (Blueprint $table) => $table->dropColumn('alerted_at'));
    }
};
