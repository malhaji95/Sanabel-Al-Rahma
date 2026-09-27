<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The association decided on 27 Sep that a donor may transfer to the family
 * directly, to the association, or be offered both.
 *
 * An association is a user row with the `association` role — the convention
 * `users.association_id` already follows — so its wallet and its default mode
 * live there rather than in a table of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Optional: an association without a wallet simply cannot offer
            // the association route, and its families fall back to the platform.
            $table->text('wallet_encrypted')->nullable()->after('phone_encrypted');
            $table->string('transfer_mode')->nullable()->after('wallet_encrypted'); // direct|association|both
        });

        Schema::table('beneficiaries', function (Blueprint $table) {
            // Which association the family belongs to. `created_by` carried this
            // implicitly, which broke the moment an admin touched the file.
            $table->foreignId('association_id')->nullable()->after('region_id')
                ->constrained('users')->nullOnDelete();
            // Null means "follow the association", which in turn may follow the
            // platform default. An admin sets this only to except one family.
            $table->string('transfer_mode')->nullable()->after('wallet_encrypted');
        });

        // Files an association created already belong to it.
        DB::table('beneficiaries')
            ->where('source', 'association')
            ->whereNotNull('created_by')
            ->update(['association_id' => DB::raw('created_by')]);
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropForeign(['association_id']);
            $table->dropColumn(['association_id', 'transfer_mode']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['wallet_encrypted', 'transfer_mode']);
        });
    }
};
