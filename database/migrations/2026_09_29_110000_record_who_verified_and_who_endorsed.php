<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The statuses `pending_visit`, `verified` and `pending_approval` were defined
 * from the start and nothing ever moved a file into them: a case went from
 * draft straight to approved on the admin's word alone, which is why the
 * dashboard's "awaiting approval" always read zero.
 *
 * The master phases document says verification passes through the delegate and
 * then the area supervisor, so each of those now leaves a name and a time on
 * the file rather than being an unrecorded convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->foreignId('field_verified_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('field_verified_at')->nullable()->after('field_verified_by');

            $table->foreignId('endorsed_by')->nullable()->after('field_verified_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('endorsed_at')->nullable()->after('endorsed_by');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropForeign(['field_verified_by']);
            $table->dropForeign(['endorsed_by']);
            $table->dropColumn(['field_verified_by', 'field_verified_at', 'endorsed_by', 'endorsed_at']);
        });
    }
};
