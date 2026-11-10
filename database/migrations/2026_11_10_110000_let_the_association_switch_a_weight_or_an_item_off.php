<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-288 — «نحتاج آلية اننا نستطيع ايقاف اي وزن او تعديل, مثلا موضوع الأدوية
 * ممكن ايقافه», 10 November.
 *
 * It could already be done by setting a weight or an amount to zero, and
 * that is not the same thing. A zero reads as a value nobody has filled in
 * yet; the association wants to say «this does not count with us», and to be
 * able to say it back. A switch says it, survives being read months later,
 * and is written into every study's snapshot beside the figure it silenced.
 *
 * Switching off is not deleting: the row keeps its amount and its history,
 * and turning it back on restores exactly what was there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scoring_weights', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('weight');
        });

        Schema::table('adjustments_catalog', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('scoring_weights', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('adjustments_catalog', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
