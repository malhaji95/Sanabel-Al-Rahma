<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-78 — the association's funding target, decided 13 October.
 |
 | «ممكن 30% ممكن 50% ممكن 70% ممكن 100%»: the study and the monthly need stay
 | exactly as they are, and what the donor is shown is a share of that need —
 | a target the association moves whenever it wants.
 |
 | The share is a setting for the whole system, and a region may hold its own.
 | A column on the region rather than a table of its own, read up the lineage
 | the way reference values already are: a village with nothing of its own
 | follows its area, and that its governorate, and that the system setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->unsignedSmallInteger('funding_target_percent')
                ->nullable()
                ->comment('A share of the monthly need shown to donors here. Null follows the parent, then the setting.');
        });
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn('funding_target_percent');
        });
    }
};
