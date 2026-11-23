<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-317 — phase two, item 5: «تسجيل قراءة البيانات الحساسة».
 |
 | Every row in this log until now was about something that existed: a file
 | created, a donation verified, a permission changed. The first read it
 | records is not — somebody asks whether a particular identity number is on
 | file, and the answer is sometimes no.
 |
 | That no is worth recording. It says a real person was asked about, and a
 | morning of a hundred of them is the shape of somebody fishing. So the
 | subject may now be absent.
 |
 | A widening only: no row changes, nothing is dropped, and the log stays
 | append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('entity_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('entity_id')->nullable(false)->change();
        });
    }
};
