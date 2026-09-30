<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `receipt_media_id` is the donor's own deposit slip — what they uploaded to
 * prove they transferred. This is the other half: the record of the transfer
 * the association itself made afterwards, so a donor can see that their money
 * actually went out, not only that it came in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_record_media_id')->nullable()->after('receipt_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('donations', fn (Blueprint $table) => $table->dropColumn('transfer_record_media_id'));
    }
};
