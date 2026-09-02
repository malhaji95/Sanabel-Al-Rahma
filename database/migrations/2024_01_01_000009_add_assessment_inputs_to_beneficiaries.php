<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The U (urgency) and B (debt) factors in docs/03-rules.md need an input that
 | docs/02-data-model.md does not name. These two columns are those inputs.
 | Logged in docs/07-decisions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->timestamp('urgency_deadline_at')->nullable()->after('support_type');
            $table->bigInteger('documented_debt')->default(0)->after('urgency_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['urgency_deadline_at', 'documented_debt']);
        });
    }
};
