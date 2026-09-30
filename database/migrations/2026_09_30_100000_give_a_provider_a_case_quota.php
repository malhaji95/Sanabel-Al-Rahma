<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider carried a discount and nothing else: no ceiling on how many
 * families it would take, and no figure for what a case is worth. The
 * association agreed the two on 30 Sep 2026.
 *
 * The quota either renews with each Gregorian month or stands as a fixed
 * balance, chosen per provider because some doctors agree a monthly number
 * and others a total. Running out warns rather than blocks: a card is still
 * issued, and the association decides what to do about the overage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            // Null means no ceiling agreed, which is how every provider stands today.
            $table->unsignedInteger('case_quota')->nullable()->after('discount_value');
            $table->string('quota_period')->default('monthly')->after('case_quota'); // monthly|fixed
            // What one case is worth at this provider, in the smallest unit (rule 9).
            $table->bigInteger('case_value')->nullable()->after('quota_period');
            // Where a fixed quota has been drawn down to. A monthly quota counts
            // the cards issued in the month instead, so nothing to reset.
            $table->unsignedInteger('quota_used')->default(0)->after('case_value');
        });
    }

    public function down(): void
    {
        Schema::table('providers', fn (Blueprint $table) => $table->dropColumn([
            'case_quota', 'quota_period', 'case_value', 'quota_used',
        ]));
    }
};
