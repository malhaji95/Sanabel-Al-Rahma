<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-73 — the association's answer of 13 October on the zakat question.
 |
 | Their filled dossier carries «التوصية: مستحق للزكاة» in the researcher's
 | hand, and we asked whether that feeds the zakat field or stays an opinion.
 | Their answer: the field is the management's authority.
 |
 | So the researcher's view gets a column of its own and is recorded as what it
 | is — a recommendation — while `zakat_category`, the channel the fund is paid
 | from, stays a decision the management signs. The two are not the same
 | question and are no longer the same column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->boolean('zakat_recommended')
                ->nullable()
                ->comment('The researcher view from the survey. An opinion, never the decision.');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn('zakat_recommended');
        });
    }
};
