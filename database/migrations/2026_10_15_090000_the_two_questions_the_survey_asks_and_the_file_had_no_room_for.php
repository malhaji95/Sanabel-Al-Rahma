<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-100 — the association read the file against their own survey on 15
 | October and found two questions it had no room for.
 |
 | «يدخن» — asked of the head of the household and of every dependant. It was
 | already asked of the caregiver, which is where the survey asks it last; the
 | two places it asks first had nowhere to put the answer. It is a question
 | about expenditure, not about character: a household with a monthly tobacco
 | spend and a household without one are not the same household, and the
 | assessor is told to ask.
 |
 | «حالة الفقير» — a paragraph in the assessor's own words. Every other column
 | on this file is a band, a count or an amount, and that is deliberate: it is
 | what makes the need computable and the ranking defensible. But a survey that
 | can only be ticked loses the sentence that explains why this family is
 | different, and the association asked for somewhere to write it.
 |
 | It changes no computation. The need and the score are built from the
 | numbered columns, and nothing reads this one — by design, so that nobody
 | can move a family up the list by writing a better paragraph.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->boolean('smokes')->nullable()->after('works');
            $table->text('situation_ar')->nullable()->after('furnishing_ar');
        });

        Schema::table('household_members', function (Blueprint $table) {
            $table->boolean('smokes')->nullable()->after('works');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['smokes', 'situation_ar']);
        });

        Schema::table('household_members', function (Blueprint $table) {
            $table->dropColumn('smokes');
        });
    }
};
