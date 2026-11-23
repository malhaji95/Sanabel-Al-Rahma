<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-318 — phase two, item 4: «الاستثناء المؤقّت».
 |
 | T-305 built the bar and the key that lifts it: a disclosed relationship
 | stops the hand that would verify, endorse or approve, and an independent
 | account with its own permission may lift that stop with a written reason.
 |
 | What it could not do is lift it for a week. A waiver granted once held for
 | ever, so an exception made for one urgent file quietly became a standing
 | permission over that family.
 |
 | The column is nullable on purpose. A waiver already granted has no end
 | date, and giving it one now would change a decision somebody made under
 | different terms. An empty end date keeps today's behaviour exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conflict_waivers', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->after('granted_at');
        });
    }

    public function down(): void
    {
        Schema::table('conflict_waivers', fn (Blueprint $table) => $table->dropColumn('expires_on'));
    }
};
