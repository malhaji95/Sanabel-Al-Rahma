<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T-58 — FILE-01 of the security roadmap: an upload is held until it has
 * passed its checks, and nothing is served that has not.
 *
 *   clean    — passed every check the system can run, and may be served
 *   pending  — accepted and stored, waiting on a scanner outside the system
 *   rejected — failed a check; kept, because rule 3 forbids erasing anything
 *
 * Rows written before this gate existed are marked clean: they were accepted
 * under the old rules and the association is still working with them, so
 * withholding them retroactively would take papers off live files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('scan_status')->default('pending')->after('size_bytes');
            $table->timestamp('scanned_at')->nullable()->after('scan_status');
            $table->string('scan_note')->nullable()->after('scanned_at');
            $table->index('scan_status');
        });

        DB::table('media')->update(['scan_status' => 'clean', 'scanned_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['scan_status']);
            $table->dropColumn(['scan_status', 'scanned_at', 'scan_note']);
        });
    }
};
