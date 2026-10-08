<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The last signature on the money, as the association set it out on 6
 | October: the family themselves.
 |
 | A payment that has been transferred waits for the household to say it
 | arrived. They confirm and it closes; they say it did not arrive, or say
 | nothing within the window, and it goes back to the finance desk. The
 | person who moved the money never confirms its receipt.
 |
 | A family's account points at their file, and at nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->after('file_number')
                ->constrained()->nullOnDelete();
        });

        Schema::table('disbursements', function (Blueprint $table) {
            $table->timestamp('confirm_due_at')->nullable()->after('executed_at');
            $table->timestamp('beneficiary_responded_at')->nullable()->after('confirm_due_at');
            $table->text('dispute_reason_ar')->nullable()->after('beneficiary_responded_at');
            $table->foreignId('complaint_id')->nullable()->after('dispute_reason_ar')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('complaint_id');
            $table->dropColumn(['confirm_due_at', 'beneficiary_responded_at', 'dispute_reason_ar']);
        });
    }
};
