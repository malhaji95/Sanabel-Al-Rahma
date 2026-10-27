<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-175 — «لازم يتم تعبئة المعلومات تباعًا», 1 November.
 *
 * A dependant had one box called «الاسم» and nothing else, so «محمد» in a
 * household of forty files is not a person the association can look up, and a
 * row that says «أم» tells nobody whose mother. Their own rule for filling the
 * rest, in their words:
 *
 *   «فقط الأب مطلوب الكنية والابن ياخد كنية الأب, لكن بنفس الوقت يتم طلب كنية
 *    الزوجة التي هي الأم, لكن للابن يتم تعبئة اسم الأم مباشر بعد تعبئة معلومات
 *    أمه وهكذا» — and «إذا كان المعال غير الابن والبنت نحتاج اسم الأب والكنية».
 *
 * Three columns, and the form fills them from the file for a son or a daughter
 * and asks for them of anybody else. A wife is asked for her own surname
 * because it is her own, which is the one place their rule says to ask twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $table->string('family_name_ar')->nullable()->after('name_ar');
            $table->string('father_name_ar')->nullable()->after('family_name_ar');
            $table->string('mother_name_ar')->nullable()->after('father_name_ar');
        });
    }

    public function down(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $table->dropColumn(['family_name_ar', 'father_name_ar', 'mother_name_ar']);
        });
    }
};
