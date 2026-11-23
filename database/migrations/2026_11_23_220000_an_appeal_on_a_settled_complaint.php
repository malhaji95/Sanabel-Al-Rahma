<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-319 — phase two, item 3: «استئناف».
 |
 | A complaint could be opened, followed and settled. What its reporter could
 | not do was say «you did not do me justice»: a resolution was the end of the
 | road, and the only way back was to open a second complaint about the same
 | thing, which arrives as a new ticket with none of the first one's history.
 |
 | Three columns: when it was appealed, why, and who held it when the appeal
 | came — the last so the system can refuse to hand it back to the same desk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->timestamp('appealed_at')->nullable()->after('resolution_ar');
            $table->text('appeal_reason_ar')->nullable()->after('appealed_at');
            $table->foreignId('appealed_from_owner_id')->nullable()->after('appeal_reason_ar')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appealed_from_owner_id');
            $table->dropColumn(['appealed_at', 'appeal_reason_ar']);
        });
    }
};
