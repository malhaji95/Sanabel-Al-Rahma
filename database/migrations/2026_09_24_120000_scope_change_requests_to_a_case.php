<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A change request may target the record that actually holds the field — the
 * housing row, an income, a member — rather than always the beneficiary. The
 * case it belongs to is recorded alongside, so the review queue can still show
 * whose file it is and the recompute can find it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->foreignId('beneficiary_id')->nullable()->after('entity_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('beneficiary_id');
        });
    }
};
