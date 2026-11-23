<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-323 — phase two, item 9: «الإقفال».
 |
 | Nothing stopped a payment being raised for a month whose books had been
 | gone over and reported. So a figure the association had put its name to
 | could move after it had been read, and the report and the ledger would not
 | agree — with no row anywhere saying which came first.
 |
 | One row a month. Closing is not a lock on the past: every payment already
 | raised stays exactly as it is, and the orders in flight finish their path.
 | What closing refuses is the new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_periods', function (Blueprint $table) {
            $table->id();
            // The first day of the month it governs, so a date comparison is
            // the whole of the lookup.
            $table->date('period')->unique();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason_ar')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_periods');
    }
};
