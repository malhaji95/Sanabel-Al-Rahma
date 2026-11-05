<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        /*
         | T-259 — the same rule on either engine.
         |
         | PostgreSQL says it directly. MySQL has no partial index, so the
         | «only where» is carried by a generated column that is null
         | unless the sponsorship is live — and a unique index counts nulls
         | as distinct, which is what a partial index means. Virtual and
         | not stored, because MySQL refuses a stored generated column over
         | a column a foreign key cascades on.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX sponsorships_one_live_per_member'
                .' ON sponsorships (household_member_id)'
                ." WHERE household_member_id IS NOT NULL AND deleted_at IS NULL AND status = 'active'"
            );

            return;
        }

        DB::statement(
            'alter table sponsorships add column live_member_key bigint unsigned'
            ." generated always as (case when deleted_at is null and status = 'active'"
            .' then household_member_id end) virtual'
        );

        DB::statement(
            'create unique index sponsorships_one_live_per_member on sponsorships (live_member_key)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS sponsorships_one_live_per_member');

            return;
        }

        Schema::table('sponsorships', function (Blueprint $table) {
            $table->dropIndex('sponsorships_one_live_per_member');
            $table->dropColumn('live_member_key');
        });
    }
};
