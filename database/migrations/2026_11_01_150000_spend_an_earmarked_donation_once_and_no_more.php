<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | T-213 — «لازم نمنع صرف نفس التبرع مرتين», 1 November.
 |
 | Money in and money out were two chains that never touched. A verified
 | donation earmarked for a family writes a `donation_allocations` row; a
 | payment to that family writes a `disbursements` row; and nothing joined
 | them. The only guard against paying twice was the family's own monthly
 | need — enough to stop two payments in one month, and no help at all
 | against one donation of 100,000 answering a payment in November and
 | another in December.
 |
 | So an allocation now carries what has been drawn from it, and the
 | database refuses to let that pass what was given. A CHECK rather than a
 | rule in PHP, because §3 of CLAUDE.md prefers it and because the one
 | thing that must never happen here is a race between two treasurers
 | reading the same balance.
 |
 | The pivot is not bookkeeping for its own sake: a payment that is
 | rejected or fails has to give its money back, and giving it back means
 | knowing which allocations it took it from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_allocations', function (Blueprint $table) {
            // Rule 9 — an integer in the smallest unit, like the amount
            // beside it, and never negative.
            $table->bigInteger('drawn_amount')->default(0)->after('amount');
        });

        DB::statement(
            'alter table donation_allocations add constraint donation_allocations_drawn_within_amount'
            .' check (drawn_amount >= 0 and drawn_amount <= amount)'
        );

        Schema::create('disbursement_fundings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disbursement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('donation_allocation_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->timestamps();

            // One line draws from one allocation once; a second draw on the
            // same pair is an addition to the first, never a new row.
            $table->unique(['disbursement_id', 'donation_allocation_id'], 'disbursement_fundings_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursement_fundings');

        DB::statement('alter table donation_allocations drop constraint if exists donation_allocations_drawn_within_amount');

        Schema::table('donation_allocations', fn (Blueprint $table) => $table->dropColumn('drawn_amount'));
    }
};
