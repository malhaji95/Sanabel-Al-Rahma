<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approved 24 Sep 2026: the coverage cycle is the Gregorian calendar month,
 * starting at the beginning of each month, with the next month opening for
 * funding a configurable number of days before the current one ends, so a
 * family is never left with a gap at the turn of the month.
 *
 * Until now coverage compared a family's lifetime support against a monthly
 * need, so a family funded once read as covered for ever. Each allocation and
 * each basket item now names the month it answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_allocations', function (Blueprint $table) {
            $table->date('coverage_month')->nullable()->after('beneficiary_id');
            $table->index(['beneficiary_id', 'coverage_month']);
        });

        Schema::table('basket_items', function (Blueprint $table) {
            $table->date('coverage_month')->nullable()->after('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('donation_allocations', function (Blueprint $table) {
            $table->dropIndex(['beneficiary_id', 'coverage_month']);
            $table->dropColumn('coverage_month');
        });

        Schema::table('basket_items', function (Blueprint $table) {
            $table->dropColumn('coverage_month');
        });
    }
};
