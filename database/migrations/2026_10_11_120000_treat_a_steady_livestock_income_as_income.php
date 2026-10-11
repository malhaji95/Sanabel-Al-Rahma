<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-52 — a steady net income from animals is income; a seasonal one is not.
 *
 * The association decided on 11 October that where animals bring in a real,
 * steady, repeating net income it is treated as stable income and enters the
 * need calculation, and that where the income is seasonal or intermittent it
 * is recorded and nothing is deducted.
 *
 * `monthly_income` was already on the table and holds the net figure — what is
 * left after feed and care. The flag is what decides whether the engine reads
 * it, and the delegate answers it from the survey rather than the office
 * guessing afterwards. Null means nobody has answered yet, which is not the
 * same as "no", so nothing is deducted until somebody says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('livestock', function (Blueprint $table) {
            $table->boolean('is_stable')->nullable()->after('monthly_income');
        });
    }

    public function down(): void
    {
        Schema::table('livestock', function (Blueprint $table) {
            $table->dropColumn('is_stable');
        });
    }
};
