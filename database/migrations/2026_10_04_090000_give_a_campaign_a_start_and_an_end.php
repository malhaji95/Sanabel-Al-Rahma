<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A campaign closed itself when it reached its goal and otherwise ran forever,
 | so one that never got there sat on the public page indefinitely. It now has a
 | window: before it opens nothing is collected, and when it closes without
 | reaching the goal it becomes 'lapsed' and the campaign's own failed-campaign
 | policy — the one the donor agreed to before paying — decides what happens to
 | what was raised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('goal_amount');
            $table->date('ends_on')->nullable()->after('starts_on');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
