<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-227 — «يُلغى أمر الصرف غير المنفّذ من صاحب الصلاحية المحددة. يُسجّل سبب
 | الإلغاء واسم المسؤول وتاريخه», 1 November.
 |
 | `cancelled` has been a declared status since the order table was written,
 | with a colour in the list and nothing anywhere that sets it. So an
 | approved order carrying a wrong amount was frozen for good: it cannot be
 | edited, which is right, and it could not be withdrawn either, which is
 | not. The only way out was to execute money the association knew was
 | wrong, or to leave the order sitting approved for ever.
 |
 | Three columns rather than a note: a withdrawal of an approved decision is
 | itself a decision, and it is answered for by name and by date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursement_orders', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('settled_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->text('cancel_reason_ar')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('disbursement_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason_ar']);
        });
    }
};
