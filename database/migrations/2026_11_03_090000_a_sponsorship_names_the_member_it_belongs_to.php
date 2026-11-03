<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-238 — «كل كفالة ترتبط داخليًا باليتيم المعني، وتنعكس على إجمالي تغطية
 | الأسرة دون ازدواج», 3 November.
 |
 | Until now a sponsorship pointed at a household and no further. The
 | association's own example is four orphans in one family with three of
 | them sponsored separately: three rows that are indistinguishable once
 | written, because nothing on the row says which child it is for.
 |
 | The column is nullable on purpose. Every sponsorship written before
 | today names a family and not a member, and a sponsorship of the whole
 | household (a widow's family, say) still has no member to name. The link
 | is a refinement where one applies, not a new requirement everywhere.
 |
 | The member must belong to the sponsored family. That is a rule across
 | two tables, which no CHECK can express, so `Sponsorship` guards it on
 | save and a test holds the guard in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsorships', function (Blueprint $table) {
            $table->foreignId('household_member_id')->nullable()->after('beneficiary_id')
                ->constrained('household_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sponsorships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('household_member_id');
        });
    }
};
