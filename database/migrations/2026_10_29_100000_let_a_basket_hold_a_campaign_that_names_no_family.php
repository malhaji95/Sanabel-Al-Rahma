<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | T-190 — a pledge to a campaign rides the donor's basket, and the basket
 | could not hold one.
 |
 | `basket_items.beneficiary_id` was written in 2024, when the only thing a
 | basket held was a family. A campaign may name no family at all — the
 | association's own words on 17 October were «هذه ليست حالات فقراء, هذه
 | الحملات» — so `addCampaign()` wrote null into a NOT NULL column and every
 | pledge to such a campaign died where it stood.
 |
 | The unique index moves with it, as two partial ones. A donor still cannot
 | hold the same family twice, nor the same campaign twice, and may now do
 | both at once for a campaign opened on a family they already support —
 | which the single old index forbade.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | T-259 — the same two rules on either engine.
         |
         | PostgreSQL states them directly, as partial unique indexes.
         | MySQL has no partial index, so the family rule is carried by a
         | generated column that is null unless the row names a family and
         | no campaign — and a unique index counts nulls as distinct, which
         | is exactly the «only where» a partial index means.
         |
         | The campaign rule needs no trick on either engine: a plain
         | unique on (basket_id, campaign_id) already ignores the rows
         | whose campaign is null, for the same reason.
         */
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('basket_items', function (Blueprint $table) {
                $table->dropUnique(['basket_id', 'beneficiary_id']);
            });

            DB::statement('alter table basket_items alter column beneficiary_id drop not null');

            DB::statement(
                'create unique index basket_items_family_unique on basket_items (basket_id, beneficiary_id)'
                .' where campaign_id is null'
            );
            DB::statement(
                'create unique index basket_items_campaign_unique on basket_items (basket_id, campaign_id)'
                .' where campaign_id is not null'
            );

            return;
        }

        // MySQL will not drop an index a foreign key is leaning on, so it is
        // given another to lean on first.
        DB::statement('create index basket_items_basket_id_index on basket_items (basket_id)');

        Schema::table('basket_items', function (Blueprint $table) {
            $table->dropUnique(['basket_id', 'beneficiary_id']);
        });

        DB::statement('alter table basket_items modify beneficiary_id bigint unsigned null');

        DB::statement(
            'alter table basket_items add column family_key bigint unsigned'
            // Virtual and not stored: MySQL refuses a stored generated
            // column over a column a foreign key cascades on, and both of
            // these cascade. A virtual one it indexes happily.
            .' generated always as (case when campaign_id is null then beneficiary_id end) virtual'
        );

        DB::statement('create unique index basket_items_family_unique on basket_items (basket_id, family_key)');
        DB::statement('create unique index basket_items_campaign_unique on basket_items (basket_id, campaign_id)');
    }

    public function down(): void
    {
        DB::table('basket_items')->whereNull('beneficiary_id')->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop index if exists basket_items_family_unique');
            DB::statement('drop index if exists basket_items_campaign_unique');
            DB::statement('alter table basket_items alter column beneficiary_id set not null');
        } else {
            Schema::table('basket_items', function (Blueprint $table) {
                $table->dropIndex('basket_items_family_unique');
                $table->dropIndex('basket_items_campaign_unique');
                $table->dropColumn('family_key');
                $table->dropIndex('basket_items_basket_id_index');
            });

            DB::statement('alter table basket_items modify beneficiary_id bigint unsigned not null');
        }

        Schema::table('basket_items', function (Blueprint $table) {
            $table->unique(['basket_id', 'beneficiary_id']);
        });
    }
};
