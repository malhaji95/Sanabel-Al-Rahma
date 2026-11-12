<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-299 — «نحتاج إضافة سؤال إلزامي في دراسة الأسرة: كيف قامت الأسرة بتغطية
 * مصاريف معيشتها خلال آخر ٣٠ يومًا؟», 12 November.
 *
 * «الهدف ليس اعتبار جميع هذه الموارد دخلًا, بل معرفة الواقع المالي للأسرة
 * والتحقق من صحة بياناتها. إذا كانت الأسرة تسجل دخلًا صفريًا, يجب توضيح
 * مصادر معيشتها الفعلية دون رفضها تلقائيًا.»
 *
 * So this is description, not arithmetic: nothing here reaches the need, the
 * income, the deficit or the score, and a household that lives on debt and
 * the charity of neighbours is not thereby credited with an income. What it
 * answers is the question a file of zero income cannot answer by itself —
 * how, then — and it is asked of every file rather than only of those, because
 * a question asked of the suspicious alone is an accusation.
 *
 * Several answers at once, because a family that borrows also cuts back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            // The means, as the association listed them. Null is «nobody has
            // been asked yet», which is not the same as «nothing», and the
            // form is what refuses to move on without an answer.
            $table->json('livelihood_means')->nullable()->after('declared_monthly_income');
            $table->string('livelihood_means_other_ar')->nullable()->after('livelihood_means');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['livelihood_means', 'livelihood_means_other_ar']);
        });
    }
};
