<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | T-249 — the association's graduation policy, 4 November, in their own
 | words:
 |
 |   «المندوب الميداني بيعمل دراسة حديثة لدخل الأسرة ووضعها. مشرف المنطقة
 |    بيراجع الدراسة وبيقدم توصيته. المدير التنفيذي هو صاحب صلاحية اعتماد
 |    التخرج النهائي. ما بيتم التخرج إلا إذا ثبت أن الأسرة صار عندها دخل
 |    كافٍ ومستقر يغطي احتياجاتها الأساسية … نقترح يكون استقرار الدخل مثبت
 |    لمدة ثلاثة أشهر على الأقل، وتكون المدة قابلة للتعديل من الإعدادات …
 |    السبب والدراسة والتوصية والقرار كلها بتنحفظ بسجل التدقيق.»
 |
 | And the sentence the whole table exists to honour: «مجرد تسليم مشروع
 | تنموي أو إغلاق معاملة ما بيعني أن الأسرة تخرجت من الفقر». Closing is
 | one act and graduating is another; T-243 split the two words and this
 | gives the second one the only road it will ever have.
 |
 | Three hands and three timestamps, because a decision with one name on it
 | is a decision nobody can check. The study's own figures are on the row —
 | the income the delegate found, the month it has been steady since, and
 | the family's computed need at that moment — so the judgement stays
 | readable years later even after the reference amounts have moved. That
 | is rule 8's habit applied to a decision instead of an assessment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graduation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();

            // The fresh study: what the family now earns a month, and since
            // when it has been steady. Money is an integer (rule 9).
            $table->bigInteger('monthly_income');
            $table->string('currency', 3)->default('SYP');
            $table->date('stable_since');
            $table->text('study_note_ar');

            // The family's monthly need as it stood when the study was read,
            // so «دخل كافٍ» can be checked again afterwards against the same
            // figure the decision was taken on.
            $table->bigInteger('need_snapshot');

            $table->string('status')->default('pending_supervisor');
            // pending_supervisor|pending_director|approved|refused|withdrawn

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('recommendation')->nullable(); // support|object
            $table->text('recommendation_note_ar')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note_ar')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'beneficiary_id']);
        });

        // A study claiming no income at all is not a graduation study, and a
        // need of nothing is not a need. A database constraint over
        // application logic (CLAUDE.md §3).
        DB::statement(
            'ALTER TABLE graduation_requests ADD CONSTRAINT graduation_requests_money_positive'
            .' CHECK (monthly_income > 0 AND need_snapshot > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('graduation_requests');
    }
};
