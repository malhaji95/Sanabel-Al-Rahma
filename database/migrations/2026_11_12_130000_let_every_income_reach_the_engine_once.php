<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-303 — «ربط جميع مصادر الدخل بمحرك الاحتساب تلقائيًا», 12 November.
 *
 * Three kinds of money the family lives on reached the engine as nought:
 *
 *   the animals, whose steady income was counted only if a switch nobody
 *   had to touch happened to be on;
 *
 *   the standing arrangements — neighbours, relatives, a daughter's husband,
 *   another association — which the file could record but which deducted
 *   from nothing, because the table they live in was built for a payment
 *   against a named month;
 *
 *   and the rent of a property, a plot or a vehicle, for which the file had
 *   no field at all: the assets table records what a thing is worth, never
 *   what it brings in.
 *
 * Each gets the same two answers the income rows have carried since T-287 —
 * is it steady, and if not how many times a year does it come — so that one
 * rule turns all of them into a monthly figure.
 *
 * And a standing arrangement must be confirmed as arriving before it counts:
 * «عدم احتساب الوعود غير المؤكدة كدخل». A promise is recorded and is not
 * income.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('livestock', function (Blueprint $table) {
            $table->unsignedSmallInteger('times_per_year')->nullable()->after('is_stable');
        });

        Schema::table('support_sources', function (Blueprint $table) {
            $table->unsignedSmallInteger('times_per_year')->nullable()->after('amount');
            // Does it actually arrive? Null is nobody has been asked, which
            // is not a «yes»: an arrangement nobody has confirmed is a
            // promise, and a promise is not income.
            $table->boolean('is_confirmed')->nullable()->after('times_per_year');
        });

        Schema::table('assets', function (Blueprint $table) {
            // Net of what it costs to keep: «احتساب صافي دخل المواشي
            // والزراعة والإيجارات بعد المصاريف المرتبطة بها».
            $table->bigInteger('net_income')->nullable()->after('estimated_value');
            $table->boolean('is_stable')->nullable()->after('net_income');
            $table->unsignedSmallInteger('times_per_year')->nullable()->after('is_stable');
        });
    }

    public function down(): void
    {
        Schema::table('livestock', fn (Blueprint $t) => $t->dropColumn('times_per_year'));
        Schema::table('support_sources', fn (Blueprint $t) => $t->dropColumn(['times_per_year', 'is_confirmed']));
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn(['net_income', 'is_stable', 'times_per_year']));
    }
};
