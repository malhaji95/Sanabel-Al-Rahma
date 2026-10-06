<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Two decisions of 5 October.
 |
 | Zakat: the reason is picked from the eight categories named in the verse,
 | never typed by hand, so the fund can be reported on and a free-text box
 | cannot quietly become a second description of the family. The donor is told
 | that zakat may be paid on a file — not which category it falls under, which
 | is a statement about the household and belongs behind rule 2.
 |
 | Sharing: a published file carries a link anyone can pass on. The association
 | asked to be able to close that door on a file without unpublishing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('zakat_category')->nullable()->after('support_type');
            $table->boolean('is_shareable')->default(true)->after('zakat_category');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['zakat_category', 'is_shareable']);
        });
    }
};
