<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns were a shell: nothing could be pledged to one, collected_amount
 * was never incremented anywhere in the application, and the reversal path
 * decremented a column that could only go negative.
 *
 * A basket item may now target a campaign, which reuses the 24h hold and its
 * locking rather than adding a second reservation path. The two counters are
 * dropped: what a campaign has collected is the sum of its verified
 * allocations, and what is held against it is the sum of its live basket
 * items, exactly as a family's coverage is already derived. A stored copy of
 * either could disagree with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('basket_items', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('beneficiary_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['collected_amount', 'reserved_amount']);
        });
    }

    public function down(): void
    {
        Schema::table('basket_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->bigInteger('collected_amount')->default(0)->after('goal_amount');
            $table->bigInteger('reserved_amount')->default(0)->after('collected_amount');
        });
    }
};
