<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-63 — three decisions the association made on 12 October about the import.
 *
 * `case_classification` — their survey's own «تصنيف الحالة». They said it was
 * added for filtering, not as a zakat category, so it is its own filterable
 * column and the zakat category stays the management's own call. The two are
 * not the same question: فقير and مسكين are two of the eight zakat channels,
 * but مرض and أرمل are not channels at all.
 *
 * `legacy_review_json` — the old system's researcher review: his opinion, his
 * recommendation, his name, the form serial, the dossier PDF link and the
 * inspection date. Kept as an archive to look back at, because the file
 * itself re-enters as a draft and goes through this system's own chain
 * (delegate, then area supervisor, then management). Nothing reads it and no
 * screen lets it be edited: it is a record of what the old system said.
 *
 * Housing gains an «أخرى» type, which their own survey has and this system
 * did not. It is deliberately a type with no engine effect: rent calls up the
 * rent reference and a shelter calls up an adjustment, and «أخرى» calls up
 * neither, which is what makes it safe to import into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('case_classification')->nullable()->after('zakat_category');
            $table->json('legacy_review_json')->nullable()->after('legacy_file_no');
            $table->index('case_classification');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropIndex(['case_classification']);
            $table->dropColumn(['case_classification', 'legacy_review_json']);
        });
    }
};
