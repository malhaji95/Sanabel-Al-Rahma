<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | T-263 — «والمبالغ تروح على رقم ملف الباب, تكون مقيدة له», 5 November.
 |
 | The association asks for money by a file number, and until now only a
 | family had one. A door is the other thing money is asked for by name, so
 | it is given one in the same scheme the association chose on 29 October
 | (T-191): a family is `SWA-0001`, a door is `SWB-0001`. One letter apart,
 | so whoever reads it on a transfer knows at once which of the two it is.
 |
 | Every campaign gets one, not only the doors: a campaign opened on a
 | family is quoted on a transfer exactly as often.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('reference_no', 32)->nullable()->unique()->after('id');
        });

        foreach (DB::table('campaigns')->orderBy('id')->get(['id']) as $row) {
            DB::table('campaigns')->where('id', $row->id)->update([
                'reference_no' => 'SWB-'.str_pad((string) $row->id, 4, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropUnique(['reference_no']);
            $table->dropColumn('reference_no');
        });
    }
};
