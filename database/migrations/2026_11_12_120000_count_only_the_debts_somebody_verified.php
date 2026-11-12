<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-302 — «ربط عامل المديونية تلقائيًا بالديون التي تم التحقق منها واعتماد
 * أهليتها», 12 November.
 *
 * The file held a table of creditors and, quite separately, a box in which a
 * researcher typed a figure called «الدين الموثَّق». The score read the box.
 * So the number that moved a family up the queue need not have matched the
 * debts the family actually named, and nothing in the system would notice.
 *
 * Three states, as the association asked for them:
 *
 *   recorded — the row exists, which is what the survey collected;
 *   verified — somebody with the standing to say so checked it, by a means
 *              they had to name;
 *   eligible — verified, and for a basic need rather than anything else.
 *
 * Only the third reaches the score. The first two are the record, and
 * recording a debt is still not a decision to pay it: that stays where it
 * was, which is the separation they asked us to keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            // «هل الدين ناتج عن احتياجات أساسية؟» — null is nobody has been
            // asked, which is not «no», and an unanswered debt scores nothing
            // rather than scoring on an assumption.
            $table->boolean('is_essential')->nullable()->after('reason_ar');

            // «وسيلة التحقق» — named, not implied.
            $table->string('verification_method')->nullable()->after('is_essential');
            $table->string('verification_note_ar')->nullable()->after('verification_method');

            $table->foreignId('verified_by')->nullable()->after('verification_note_ar')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['is_essential', 'verification_method', 'verification_note_ar', 'verified_at']);
        });
    }
};
