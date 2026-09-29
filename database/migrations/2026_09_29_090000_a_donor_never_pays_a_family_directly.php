<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The association settled the question on 29 Sep 2026: a donor never transfers
 * to a family's wallet. Every transfer reaches the association, and what varies
 * is not where the money goes but what it is for — a named file, or general
 * money the association may spend at its discretion.
 *
 * So the three transfer modes of 27 Sep are gone: with the direct route
 * refused there is nothing left to choose. A family's wallet is once again
 * something no donor ever sees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            // earmarked = for the files in the basket; general = the association decides.
            $table->string('designation')->default('earmarked')->after('route');
        });

        // Everything recorded so far came through a basket of named files.
        DB::table('donations')->update(['designation' => 'earmarked']);

        // `route` keeps its documented values, but `direct` is now unreachable:
        // nothing writes it, and DonationService refuses it.
        DB::table('donations')->where('route', 'direct')->update(['route' => 'platform']);

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('transfer_mode'));
        Schema::table('beneficiaries', fn (Blueprint $table) => $table->dropColumn('transfer_mode'));

        DB::table('settings')->where('key', 'default_transfer_mode')->delete();
    }

    public function down(): void
    {
        Schema::table('donations', fn (Blueprint $table) => $table->dropColumn('designation'));
        Schema::table('users', fn (Blueprint $table) => $table->string('transfer_mode')->nullable());
        Schema::table('beneficiaries', fn (Blueprint $table) => $table->string('transfer_mode')->nullable());
    }
};
