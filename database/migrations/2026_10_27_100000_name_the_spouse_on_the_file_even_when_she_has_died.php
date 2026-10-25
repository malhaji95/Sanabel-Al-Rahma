<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-128 — «ضمن ملف المستفيد: مافي بيانات الزوجة, او بيانات الزوج, يجب تصحيح
 | الامر وتضمينهم حتى لو متوفية يتم ذكرهم», 27 October.
 |
 | The file said «أرمل» and stopped. Every dependant in the household carries
 | her husband's name and number (T-69), and the one person the file is named
 | after carried neither — so a widow's file did not say who she had lost, and
 | a married head's file named his wife only if somebody had also entered her
 | as a household member.
 |
 | Two columns, the same two the dependants already have, with the same
 | encryption on the identity number. The money is untouched: the engine counts
 | `household_members`, and a name on the head's own row is not a member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('spouse_name_ar')->nullable()->after('marital_status');
            $table->text('spouse_national_id_encrypted')->nullable()->after('spouse_name_ar');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', fn (Blueprint $table) => $table->dropColumn([
            'spouse_name_ar', 'spouse_national_id_encrypted',
        ]));
    }
};
