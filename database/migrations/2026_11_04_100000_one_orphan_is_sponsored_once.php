<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | T-245 — «كيفية منع ازدواج الكفالة», 4 November.
 |
 | T-238 gave a sponsorship the member it is for. What it did not do is
 | stop two donors reaching the same child, and that cannot be left to the
 | screen: two people pressing the button in the same second both read an
 | empty list of sponsorships for that orphan and both write one.
 |
 | A rule the database holds instead of the application (CLAUDE.md §3): at
 | most one live sponsorship per member. Partial, because the rule is about
 | live ones — a sponsorship that lapsed, completed or was cancelled leaves
 | the child available again, which is the association's own expectation,
 | and a soft-deleted row is not a sponsorship at all (rule 3).
 |
 | Household-wide sponsorships name no member, so they are untouched: the
 | index only sees rows that name one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX sponsorships_one_live_per_member'
            .' ON sponsorships (household_member_id)'
            ." WHERE household_member_id IS NOT NULL AND deleted_at IS NULL AND status = 'active'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sponsorships_one_live_per_member');
    }
};
