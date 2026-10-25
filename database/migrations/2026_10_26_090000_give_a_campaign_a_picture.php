<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-123 — «عند إضافة حملة نحتاج أن يكون متوفر خيار رفع صورة», 26 October.
 |
 | A campaign had a title, a story and a goal, and nothing to look at. The
 | same shape as a home page card's picture: a path on the public disk, so
 | the file is served like any other published image and nothing about it is
 | private — a campaign is a public page by definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('image')->nullable()->after('body_ar');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('image'));
    }
};
