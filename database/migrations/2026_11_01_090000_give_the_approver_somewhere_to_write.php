<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-177 — «نحتاجه يطلع كامل ويقدر يكتب ملاحظات ويستطيع التعديل على التصنيف
 * فقط», 1 November, answering the question this report left open about the
 * executive director and the family file.
 *
 * Every note an approver could leave until now was attached to a decision:
 * a rejection carries its reason, a return to the field carries the sentence
 * that sent it back. There was nowhere to write «راجعتُ الملف, والدخل يحتاج
 * ورقة» on a file being kept, which is the ordinary case.
 *
 * One column, and the audit log keeps every version of it, so a note replaced
 * is not a note lost. The rest of the file stays read-only to that account,
 * which is the separation of duties the association asked for: they decide
 * about the family, they do not type the family's data in.
 *
 * It is never published. `MaskedCaseResource` carries no free text at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->text('management_note_ar')->nullable()->after('previous_aid_ar');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn('management_note_ar');
        });
    }
};
