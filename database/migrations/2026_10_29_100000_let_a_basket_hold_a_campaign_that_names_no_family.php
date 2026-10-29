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
    }

    public function down(): void
    {
        DB::statement('drop index if exists basket_items_family_unique');
        DB::statement('drop index if exists basket_items_campaign_unique');

        DB::table('basket_items')->whereNull('beneficiary_id')->delete();

        DB::statement('alter table basket_items alter column beneficiary_id set not null');

        Schema::table('basket_items', function (Blueprint $table) {
            $table->unique(['basket_id', 'beneficiary_id']);
        });
    }
};
