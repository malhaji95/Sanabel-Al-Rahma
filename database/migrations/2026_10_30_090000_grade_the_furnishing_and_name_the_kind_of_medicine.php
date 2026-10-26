<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-164 — two questions the association asked to show a donor, turned into
 * lists so that they can be shown at all.
 *
 * «وضع فرش المنزل» and «نوع الأدوية» were free text written by the researcher
 * for the committee, and rule 2 keeps every sentence off a donor's screen: a
 * sentence may carry a neighbour's name, an address, a doctor. The
 * association chose on 30 October to ask both as lists instead.
 *
 * The sentences stay exactly where they were. These are asked beside them,
 * and these are what travel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('housing', function (Blueprint $table) {
            $table->unsignedSmallInteger('furnishing_band')->nullable()->after('has_kitchen');
        });

        Schema::table('health_records', function (Blueprint $table) {
            $table->json('medicine_kinds')->nullable()->after('medicines_ar');
        });
    }

    public function down(): void
    {
        Schema::table('housing', function (Blueprint $table) {
            $table->dropColumn('furnishing_band');
        });

        Schema::table('health_records', function (Blueprint $table) {
            $table->dropColumn('medicine_kinds');
        });
    }
};
