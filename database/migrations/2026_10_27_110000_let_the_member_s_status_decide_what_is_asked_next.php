<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | T-129 — «بخصوص الصفة طالب يطلع مباشر خيار بأي مرحلة وأي صف حتى يتم
 | الاختيار, في حال اختيار موظف يتم اظهار مكان العمل والراتب الشهري, نفس الشي
 | اذا كان عامل, الباحث عن عمل يتم ذكر العمل الذي يستطيع العمل به, المتقاعد
 | يتم ذكر الراتب التقاعدي, ازالة صفة اليتيم من هذا المكان, وعند اختيار غير
 | قادر على العمل يظهر الوضع الصحي الخاص مع رفع ملف او وصف مع رقم الطبيب»,
 | 27 October.
 |
 | The status was a label with nothing behind it: a row could say «متقاعد» and
 | the file held no pension, or «طالب» and the file held a free-text grade with
 | no stage to read it against. Each status now asks its own question, and the
 | columns are the ones those answers need.
 |
 | «يتيم» leaves the status list because it is not a status: a child is an
 | orphan and also a student, and the file has carried `is_orphan` since T-69.
 | The rows that answered it that way are moved onto the flag rather than
 | losing the answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            // طالب — the stage, which the free-text grade is read against.
            $table->string('school_stage')->nullable()->after('school_grade_ar');
            // باحث عن عمل — the same four answers the head is asked (T-127).
            $table->string('sought_trade_ar')->nullable()->after('work_income');
            $table->boolean('has_certificate')->nullable()->after('sought_trade_ar');
            $table->smallInteger('experience_years')->nullable()->after('has_certificate');
            $table->boolean('can_teach_quran')->nullable()->after('experience_years');
            // متقاعد.
            $table->bigInteger('pension_income')->nullable()->after('can_teach_quran');
            /*
             | غير قادر على العمل — the researcher's note at the point of
             | asking, and the doctor who can be telephoned about it. The
             | banded assessment the need engine reads still lives in
             | `health_records`, and the medical report itself is uploaded as a
             | family document of kind `medical`, where the type and size gate
             | is: a second upload path would be a second gate to keep.
             */
            $table->text('condition_description_ar')->nullable()->after('has_documented_condition');
            $table->text('doctor_phone_encrypted')->nullable()->after('condition_description_ar');
        });

        // «يتيم» was never a status. The answer is kept, on the flag that holds it.
        DB::table('household_members')->where('occupation', 'orphan')->update([
            'is_orphan' => true,
            'occupation' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('household_members', fn (Blueprint $table) => $table->dropColumn([
            'school_stage', 'sought_trade_ar', 'has_certificate', 'experience_years',
            'can_teach_quran', 'pension_income', 'condition_description_ar', 'doctor_phone_encrypted',
        ]));
    }
};
