<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-214 — «ردود المستفيدين بترجع عنده بصفحة التبرعات، وبيشوف مين استلم ومين
 | ما استلم. اللي أكد الاستلام وكان عنده إثبات تحويل صحيح، بيقفل متابعة
 | الطلب. واللي ما استلم أو عنده مشكلة، بيرجعه لأمين الصندوق للمعالجة،
 | وبيظل مفتوح لحد ما تنحل المشكلة», 1 November — and, in the same message:
 | «إغلاق متابعة المستفيد غير إغلاق التدقيق المالي».
 |
 | Those two closings existed as one. A line went `received` when the family
 | answered and `reconciled` when the accountant matched it to the bank, and
 | between them there was nothing the data desk could mark: no way to say
 | «I have followed this one up and it is finished», and no way to hand a
 | disputed one back to the treasurer and see that it had been handed back.
 |
 | Two timestamps rather than two statuses, deliberately. The status belongs
 | to the money and its chain; these record what a desk did about a line,
 | and a line that is followed up is still `received`, still unreconciled,
 | still the accountant's to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->timestamp('followup_closed_at')->nullable()->after('beneficiary_responded_at');
            $table->foreignId('followup_closed_by')->nullable()->after('followup_closed_at')
                ->constrained('users')->nullOnDelete();

            // Handed back over a dispute, so the desk can tell the ones it
            // has passed on from the ones still sitting in front of it.
            $table->timestamp('returned_to_treasurer_at')->nullable()->after('followup_closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('followup_closed_by');
            $table->dropColumn(['followup_closed_at', 'returned_to_treasurer_at']);
        });
    }
};
