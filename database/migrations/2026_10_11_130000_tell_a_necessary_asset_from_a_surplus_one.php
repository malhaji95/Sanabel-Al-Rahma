<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-53 — a necessary asset is not a reason to give a family less.
 *
 * The association decided on 11 October that owning something must not lower
 * entitlement by itself. An asset needed for the home, for work or for a
 * living — the tractor its owner works on, a vehicle the job depends on — is
 * recorded and lowers nothing. A surplus, investment or non-essential asset
 * that could be sold has an effect on eligibility or priority, under criteria
 * they will approve separately; and they were explicit that it must be a rule
 * inside the system rather than a clerk's judgement.
 *
 * So the delegate answers one question per asset, and the answer is stored
 * beside it. Null means nobody has answered yet, which is not the same as
 * "surplus", so an unanswered asset counts towards nothing.
 *
 * The criteria themselves are the association's to set and are not in here:
 * the threshold lives in settings as data, with no default, and while it is
 * unset the surplus total is reported and changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // essential | surplus
            $table->string('necessity')->nullable()->after('kind_other_ar');
            $table->string('necessity_reason_ar')->nullable()->after('necessity');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['necessity', 'necessity_reason_ar']);
        });
    }
};
