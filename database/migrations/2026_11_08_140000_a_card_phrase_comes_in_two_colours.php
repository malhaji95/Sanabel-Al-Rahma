<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-264 — «نحتاج يقدر ينتقل بين بنرين, لأنه كل عبارة بتكون عندنا بلونين
 | وليس لون واحد», 8 November, with the إحسان page they take as a reference:
 | one phrase, two cards side by side under «شكل البطاقة».
 |
 | A design keeps the picture it has and may be given a second. Neither is
 | «the» colour: the giver switches between them before sending, and what
 | they chose is kept on the gift, so the card the recipient opens months
 | later is the one that was chosen and not whichever is first in the row.
 |
 | Two and not many: the association said two, and a design with no second
 | picture goes on behaving exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->string('image_path_alt')->nullable()->after('image_path');

            // What the giver reads under each: «وردي» and «أزرق», or
            // «للمولودة» and «للمولود». Optional, because two pictures of the
            // same phrase often need no words at all.
            $table->string('variant_ar', 40)->nullable()->after('image_path_alt');
            $table->string('variant_alt_ar', 40)->nullable()->after('variant_ar');
        });

        Schema::table('gifts', function (Blueprint $table) {
            // 'a' is the design's own picture and 'b' the second one. Every
            // gift already issued was issued on the only picture there was.
            $table->string('card_variant', 1)->default('a')->after('gift_card_id');
        });
    }

    public function down(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropColumn(['image_path_alt', 'variant_ar', 'variant_alt_ar']);
        });

        Schema::table('gifts', function (Blueprint $table) {
            $table->dropColumn('card_variant');
        });
    }
};
