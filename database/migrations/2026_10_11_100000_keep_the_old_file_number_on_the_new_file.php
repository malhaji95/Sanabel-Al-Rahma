<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-50 — the old system's file number, kept on the new file.
 *
 * The association decided on 11 October that Sanabel is the single source of
 * truth after the import, and that the old system stays only as an archive.
 * The number it gave each family travels with the family, so a file imported
 * twice is refused and anyone holding an old paper number can still find it.
 *
 * Unique, and nullable: in PostgreSQL a unique index allows many nulls, so a
 * family that never existed in the old system simply carries none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('legacy_file_no')->nullable()->unique()->after('file_number');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropUnique(['legacy_file_no']);
            $table->dropColumn('legacy_file_no');
        });
    }
};
