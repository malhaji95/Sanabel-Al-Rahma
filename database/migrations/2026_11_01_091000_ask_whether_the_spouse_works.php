<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-172 — «لما يتم اختيار لأنثى الحالة الاجتماعية متزوجة ويظهر تحت بيانات
 * الزوج, لازم يتم أيضًا ظهور حقول إدخال بيانات عمل الزوج إذا كان يعمل»,
 * 1 November.
 *
 * T-128 gave the head's spouse a name and a number, and stopped there. For a
 * widow that was the whole of it; for a married woman it left out the thing
 * the file most wants to know about the man she is married to — whether he
 * earns, and what.
 *
 * Four columns, mirroring the head's own four so the two blocks read the same
 * way, and `spouse_work_status` carries the association's three answers from
 * T-127 rather than a yes/no: «لا يعمل» and «يبحث عن عمل» are different men,
 * and the job market only knows about the second.
 *
 * Recorded for the file to read, like the head's. The monthly need is still
 * computed from the «الدخل» step and from nowhere else, so nothing here can
 * be counted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('spouse_work_status')->nullable()->after('spouse_national_id_encrypted');
            $table->string('spouse_profession_ar')->nullable()->after('spouse_work_status');
            $table->string('spouse_workplace_ar')->nullable()->after('spouse_profession_ar');
            $table->unsignedInteger('spouse_work_income')->nullable()->after('spouse_workplace_ar');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn([
                'spouse_work_status', 'spouse_profession_ar', 'spouse_workplace_ar', 'spouse_work_income',
            ]);
        });
    }
};
