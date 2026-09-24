<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The association's decision sheet of 24 Sep 2026 says three times that a money
 * value it has not approved stays empty, and that zero must never be assumed
 * for it. The columns were NOT NULL, so there was nowhere to record "not
 * approved yet" other than a zero that the engine then quietly believed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('region_rates', function (Blueprint $table) {
            $table->bigInteger('amount')->nullable()->change();
        });

        Schema::table('region_rent_reference', function (Blueprint $table) {
            $table->bigInteger('reference_rent')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('region_rates', function (Blueprint $table) {
            $table->bigInteger('amount')->nullable(false)->change();
        });

        Schema::table('region_rent_reference', function (Blueprint $table) {
            $table->bigInteger('reference_rent')->nullable(false)->change();
        });
    }
};
