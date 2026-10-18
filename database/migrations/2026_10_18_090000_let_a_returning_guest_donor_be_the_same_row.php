<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-108 — a gift given without signing in still belongs to somebody.
 |
 | A guest who gives every month should be one row in the donor list and not
 | twelve, and the only thing they hand over to be recognised by is their
 | telephone number. That number is encrypted on the row, as every telephone
 | number in this system is, so it cannot be searched for — which leaves
 | either reading every donor into memory to compare them one by one, or a
 | column that can be looked up.
 |
 | The same answer as `beneficiaries.national_id_hash`, for the same reason
 | and by the same method: a keyed hash, so the column finds a match without
 | being able to give the number back to anybody who reads the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donors', function (Blueprint $table) {
            $table->string('phone_hash')->nullable()->after('phone_encrypted');
            $table->index('phone_hash');
        });
    }

    public function down(): void
    {
        Schema::table('donors', function (Blueprint $table) {
            $table->dropIndex(['phone_hash']);
            $table->dropColumn('phone_hash');
        });
    }
};
