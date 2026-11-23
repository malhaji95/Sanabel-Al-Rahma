<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-321 — phase two, item 13: «قواعد مخاطر قابلة للضبط, تُحال إلى المراجعة
 | ولا ترفض وحدها».
 |
 | Two of the three were already true: a rule that fires holds the file for
 | review, and nothing in the system rejects a family by itself.
 |
 | What was missing is the half a reviewer needs. `duplicate_review_flag` is a
 | yes or a no: it says a file was held and not a word about why, so whoever
 | opens it has to guess which of the checks fired and go looking for the
 | other file themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', fn (Blueprint $table) => $table
            ->json('risk_reasons_json')->nullable()->after('duplicate_review_flag'));
    }

    public function down(): void
    {
        Schema::table('beneficiaries', fn (Blueprint $table) => $table->dropColumn('risk_reasons_json'));
    }
};
