<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | T-127 — «بخصوص خيار هل يعمل صاحب العلاقة, الافضل يكون نعم, لا, يبحث عن عمل.
 | في حال نعم يذكر المهنة والراتب الحالي, في حال يبحث عن عمل تظهر عنده ما هي
 | المهنة التي يستطيع العمل بها وهل لديه شهادة وكم سنوات الخبرة واضف خيار هل
 | يستطيع تدريس القرأن الكريم», 27 October.
 |
 | «هل يعمل» was a yes/no toggle, and a family whose breadwinner is looking for
 | work answered it «لا» — the same answer as a household with nobody of
 | working age in it. The two are not the same thing: one of them is a man who
 | would take a job this week, and the association runs a job market for
 | exactly that.
 |
 | So three answers, and the questions that follow each one. `works` stays and
 | is kept in step by the model, because the masked card and the change-request
 | allowlist both read it; nothing has to learn a new column to keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            // yes | no | seeking — a string column, not a state machine.
            $table->string('work_status')->nullable()->after('works');
            $table->string('profession_ar')->nullable()->after('work_status');
            // What a jobseeker can do, and what they can show for it.
            $table->string('sought_trade_ar')->nullable()->after('work_income');
            $table->boolean('has_certificate')->nullable()->after('sought_trade_ar');
            $table->string('certificate_ar')->nullable()->after('has_certificate');
            $table->smallInteger('experience_years')->nullable()->after('certificate_ar');
            $table->boolean('can_teach_quran')->nullable()->after('experience_years');
        });

        // The answer the file already holds, in the new three-valued column.
        DB::table('beneficiaries')->whereNotNull('works')->update([
            'work_status' => DB::raw("case when works then 'yes' else 'no' end"),
        ]);
    }

    public function down(): void
    {
        Schema::table('beneficiaries', fn (Blueprint $table) => $table->dropColumn([
            'work_status', 'profession_ar', 'sought_trade_ar',
            'has_certificate', 'certificate_ar', 'experience_years', 'can_teach_quran',
        ]));
    }
};
