<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The reservation warning runs on the same five-minute schedule that releases
 | expired holds, so without a mark on the basket a donor would be told the
 | same thing a dozen times. This records that they were told, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baskets', function (Blueprint $table) {
            $table->timestamp('expiry_warned_at')->nullable()->after('reserved_until');
        });
    }

    public function down(): void
    {
        Schema::table('baskets', function (Blueprint $table) {
            $table->dropColumn('expiry_warned_at');
        });
    }
};
