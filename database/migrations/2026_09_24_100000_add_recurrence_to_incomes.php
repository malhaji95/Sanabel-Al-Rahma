<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approved 24 Sep 2026: an unstable income records المصدر + المبلغ + الاستقرار
 * + التكرار — monthly, seasonal or intermittent. Nullable because a stable
 * income has no recurrence to record; the form requires it only when the
 * income is marked unstable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->string('recurrence')->nullable()->after('is_stable'); // monthly|seasonal|intermittent
        });
    }

    public function down(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->dropColumn('recurrence');
        });
    }
};
