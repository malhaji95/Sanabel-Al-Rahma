<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-304 — «ربط المساعدة المخصصة ببند الاحتياج الذي تغطيه», 12 November.
 *
 * T-280 stopped an earmarked payment from being deducted from the family's
 * living need, which was right: nobody's grocery bill shrinks because a
 * neighbour paid for their medicine. But it left the payment deducted from
 * nothing at all — recorded, shown, and attached to no expense.
 *
 * So the association's own case: Sanabel approves an amount towards a course
 * of treatment, another body pays for that same treatment, and the approved
 * amount goes on being collected. The money is raised twice and the system
 * never notices, because the two facts live in two tables that do not know
 * about each other.
 *
 * An earmarked payment can name the approved item it answers now, and the
 * item is funded for what is left after it. Which gives their third thing
 * for nothing: uncovered, partly covered, covered — read off the sums rather
 * than typed by anybody.
 *
 * The health path is the other way an expense gets covered, and it carries no
 * figure: it routes a family to a provider who treats them. So it is not an
 * amount but a statement — this item is being answered elsewhere — and an
 * item so marked is recorded and funded at nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_sources', function (Blueprint $table) {
            $table->foreignId('need_addition_id')->nullable()->after('purpose_note_ar')
                ->constrained('need_additions')->nullOnDelete();
        });

        Schema::table('need_additions', function (Blueprint $table) {
            $table->boolean('covered_by_health_path')->default(false)->after('reason_ar');
            $table->string('coverage_note_ar')->nullable()->after('covered_by_health_path');
        });
    }

    public function down(): void
    {
        Schema::table('support_sources', fn (Blueprint $t) => $t->dropConstrainedForeignId('need_addition_id'));
        Schema::table('need_additions', fn (Blueprint $t) => $t->dropColumn(['covered_by_health_path', 'coverage_note_ar']));
    }
};
