<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The association settled the window on 8 October: seven days, and running
 | out of them is not an answer.
 |
 | A payment nobody answered for stays exactly where it was — waiting on the
 | household, with both their buttons still live — and is raised for
 | follow-up. It is not read as confirmed, and it does not close itself.
 | This column is that raising, and nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('confirm_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }
};
