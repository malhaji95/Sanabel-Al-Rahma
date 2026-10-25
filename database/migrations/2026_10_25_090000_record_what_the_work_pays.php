<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-121 — «عند ماذا يعمل كان يوجد مبلغ الذي يأخذه من العمل, الأن غير مسجل,
 | نحتاج خانة يتم وضع المبلغ», 25 October.
 |
 | The form asked whether a person works and where, and then stopped. What the
 | work pays was recorded only as a household total in the income step, so a
 | researcher who had just been told a figure by the person in front of them
 | had nowhere to write it.
 |
 | Rule 9 — an integer in the smallest unit, like every other amount.
 |
 | It is recorded on the person and is deliberately not fed into the score:
 | the engine reads the `incomes` rows, and a figure that counted in both
 | places would count twice. The form says so where it is typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->bigInteger('work_income')->nullable()->after('workplace_ar');
        });

        Schema::table('household_members', function (Blueprint $table) {
            $table->bigInteger('work_income')->nullable()->after('workplace_ar');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', fn (Blueprint $table) => $table->dropColumn('work_income'));
        Schema::table('household_members', fn (Blueprint $table) => $table->dropColumn('work_income'));
    }
};
