<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-276 — the step a second-factor code belonged to, once it has been used.
 |
 | A TOTP code is valid for its own thirty-second step and one either side,
 | which is ninety seconds. Within those ninety seconds the same six digits
 | were accepted again and again, so a code read over a shoulder — or caught
 | by a page standing between the user and ours — could be used a second
 | time by whoever caught it.
 |
 | RFC 6238 §5.2: the verifier must not accept a step twice. This column is
 | how it remembers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('two_factor_last_counter')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_counter');
        });
    }
};
