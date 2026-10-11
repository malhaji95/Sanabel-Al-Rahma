<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-51 — support paid by another body answers one month, and only that month.
 *
 * The association decided on 11 October that money another association or body
 * actually pays towards a family's need in a given month comes off that
 * month's remaining need, exactly as a partial donation does. It is coverage,
 * not income: it never enters stable income, and a one-off payment does not
 * travel to the following month.
 *
 * So a row here is a payment, not a standing arrangement: it carries the month
 * it answers and the day it was paid. `kind` records whether the body pays
 * every month or paid once, which is what the delegate was told; it does not
 * project a future payment. A row with no `coverage_month` is one of the rows
 * written before this decision, where the source was noted but no payment was
 * recorded, and it deducts nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_sources', function (Blueprint $table) {
            // recurring | one_off
            $table->string('kind')->nullable()->after('name_ar');
            $table->date('coverage_month')->nullable()->after('currency');
            $table->date('paid_on')->nullable()->after('coverage_month');
            $table->index(['beneficiary_id', 'coverage_month']);
        });
    }

    public function down(): void
    {
        Schema::table('support_sources', function (Blueprint $table) {
            $table->dropIndex(['beneficiary_id', 'coverage_month']);
            $table->dropColumn(['kind', 'coverage_month', 'paid_on']);
        });
    }
};
