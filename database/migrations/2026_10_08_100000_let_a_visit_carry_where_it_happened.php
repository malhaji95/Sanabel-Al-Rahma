<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A field visit may record where it took place (decision of 6 October). It is
 | optional: a delegate with no signal, or a family who would rather not, can
 | leave it empty and the visit is complete without it.
 |
 | A coordinate points at a family's door, so it is not part of what a donor
 | or a partner association ever sees. Only the delegate, their supervisor and
 | the executive line read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('visited_at');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
