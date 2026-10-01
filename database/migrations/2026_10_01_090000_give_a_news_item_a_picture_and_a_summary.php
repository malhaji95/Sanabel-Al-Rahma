<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The news list showed the first lines of the body as the summary, which read
 | badly whenever a piece opened mid-sentence, and the image column existed but
 | no form ever filled it. An editor gets both as fields of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->text('excerpt_ar')->nullable()->after('title_ar');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('excerpt_ar');
        });
    }
};
