<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-289 — the association's points 8 and 9, 10 November: «هل تضاف تكلفة
 * الأدوية والعلاج إلى الاحتياج مباشرة», and «نحتاج الفصل بين تسجيل قيمة
 * الدين للتقييم، وبين اعتماد مبلغ لسداد دين ضروري. لا نريد إضافة كل دين
 * تلقائيًا إلى الاحتياج الشهري».
 *
 * That separation is exactly right and the system already keeps it: a
 * medical cost and a documented debt raise the priority score and are added
 * to no month's need. What was missing is the other half — the deliberate
 * act. The engine has accepted an approved manual addition since it was
 * written, and no screen in the system ever passed one, so the association
 * could record a debt and never decide to pay towards it.
 *
 * An addition is a decision, so it carries what a decision carries: an
 * amount, what it is for in words, who approved it, and the months it runs
 * for. It is never automatic and never derived from a figure elsewhere on
 * the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('need_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            // medical | debt | other
            $table->string('kind');
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->text('reason_ar');
            // The months it is part of the need. An open end runs until stopped.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['beneficiary_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('need_additions');
    }
};
