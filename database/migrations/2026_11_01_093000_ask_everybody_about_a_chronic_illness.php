<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-176 — «هل يوجد مرض مزمن؟ نعم لا. إذا نعم, نوع المرض», 1 November, and the
 * question that came with it: «بخصوص الحالة الصحية, هل فقط تظهر في حال كان لا
 * يعمل؟».
 *
 * It did. T-129 attached «الوضع الصحي الخاص» and the doctor's number to a
 * member who answered «غير قادر على العمل», which was the association's own
 * instruction and is the right place for those two: they explain that answer.
 * A chronic illness is a different question and belongs to everybody — a child
 * with asthma works nothing and answers «طالب», so the question never reached
 * them.
 *
 * A screening answer, and nothing reads it. The banded record the need engine
 * prices still lives in the health step, where the severity, the care burden
 * and the monthly cost are asked, so no illness is counted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $table->boolean('has_chronic_illness')->default(false);
            $table->string('chronic_illness_ar')->nullable()->after('has_chronic_illness');
        });
    }

    public function down(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $table->dropColumn(['has_chronic_illness', 'chronic_illness_ar']);
        });
    }
};
