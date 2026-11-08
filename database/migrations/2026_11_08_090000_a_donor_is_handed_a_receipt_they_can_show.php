<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | T-262 — «عرض للمتبرع ايصال عند التبرع», 8 November, with their own design.
 |
 | «إيصال» already meant something in this table, and the opposite way round:
 | `receipt_media_id` is the deposit slip the donor uploads to us. This is the
 | one we hand back — a page at an address of its own, carrying the platform's
 | number for the donation, its amount, its channel, the bank's reference and
 | the status it is actually in.
 |
 | The number itself is not new: T-203 gave every donation `DN-26-00001`
 | already, and that is what the receipt prints. What is new is a token, so
 | the page can be opened without an account — a guest donation has no account
 | to open it from — and so that the address of one receipt says nothing about
 | the address of another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('receipt_token', 64)->nullable()->unique()->after('reference_no');
        });

        // Every donation already recorded gets one too: a receipt that exists
        // only for donations made after today would be a receipt nobody trusts.
        DB::table('donations')->whereNull('receipt_token')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('donations')
                    ->where('id', $row->id)
                    ->update(['receipt_token' => Str::lower(Str::random(32))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropUnique(['receipt_token']);
            $table->dropColumn('receipt_token');
        });
    }
};
