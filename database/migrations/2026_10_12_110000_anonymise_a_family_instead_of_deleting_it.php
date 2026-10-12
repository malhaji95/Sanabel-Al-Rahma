<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-65 — the association's resolution of 12 October to the one real conflict
 * between the security roadmap and this system's own rules.
 *
 * The roadmap asks for legal deletion of personal data (FILE-04, DATA-07).
 * Rule 3 forbids erasing a beneficiary, a case or a donation. Their answer was
 * not to drop either but to separate them: the record is never deleted, and
 * the personal data that identifies a human being is destroyed when the law
 * requires it and the statutory retention period has passed.
 *
 * Their own example: file SR-10542 keeps its aid history, its approvals, its
 * financial reports and its audit trail. The name, the telephone number, the
 * address and the identity number become nothing.
 *
 * Two people, as on the disbursement path they already approved: one asks and
 * states the legal basis, another carries it out. It cannot be undone, so one
 * person acting alone is not enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->foreignId('anonymise_requested_by')->nullable()->after('legacy_review_json')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('anonymise_requested_at')->nullable()->after('anonymise_requested_by');
            // Why the law requires it, and which retention period has run out.
            $table->text('anonymise_reason_ar')->nullable()->after('anonymise_requested_at');
            $table->foreignId('anonymised_by')->nullable()->after('anonymise_reason_ar')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('anonymised_at')->nullable()->after('anonymised_by');
            $table->index('anonymised_at');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropIndex(['anonymised_at']);
            $table->dropConstrainedForeignId('anonymise_requested_by');
            $table->dropConstrainedForeignId('anonymised_by');
            $table->dropColumn(['anonymise_requested_at', 'anonymise_reason_ar', 'anonymised_at']);
        });
    }
};
