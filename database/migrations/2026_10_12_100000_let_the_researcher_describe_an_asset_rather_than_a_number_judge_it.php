<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-64 — the association's answer of 12 October on what a family owns.
 *
 * They were asked for a value above which surplus assets bear on eligibility.
 * Their answer was that there is no such number: «يجب تقييمها يدوي». The
 * committee judges each case, and what the system owes them is the researcher's
 * description rather than an arithmetic verdict.
 *
 * So the threshold is gone — it was data with no value, and leaving it in
 * would imply an automatic rule exists — and these two questions take its
 * place, both from their own words:
 *
 *   is_exploited  مستغلة أم غير مستغلة
 *   has_control   موجودة عنده، أم لا يملك القرار عليها
 *
 * The second one is the one a number could never have answered: a quarter of
 * an inherited field, held jointly with five brothers and unsellable without
 * them, is worth a great deal and feeds nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('is_exploited')->nullable()->after('necessity_reason_ar');
            $table->boolean('has_control')->nullable()->after('is_exploited');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['is_exploited', 'has_control']);
        });
    }
};
