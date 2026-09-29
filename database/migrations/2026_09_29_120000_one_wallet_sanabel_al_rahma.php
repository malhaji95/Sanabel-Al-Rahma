<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * There is one destination, not one per partner: Sanabel Al-Rahma's own Sham
 * Cash wallet. A donor either names the file the money is for, or leaves it
 * general — but either way it lands in the same account, and Sanabel Al-Rahma
 * moves it on.
 *
 * So the wallet added to `users` on 27 Sep goes. It existed only to route
 * donations to a partner association, and nothing routes to one now. The data
 * model never had it, which is the other reason it should not stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('wallet_encrypted'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->text('wallet_encrypted')->nullable());
    }
};
