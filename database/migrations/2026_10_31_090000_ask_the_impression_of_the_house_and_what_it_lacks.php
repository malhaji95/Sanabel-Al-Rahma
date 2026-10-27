<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-169 — «بالنسبة وضع فرش المنزل نحتاجه بهذي الطريقة», 31 October, with the
 * two questions written out by hand:
 *
 *   ١ الانطباع العام عن المنزل: ممتاز، جيد، مقبول، سيّئ جدًا، شبه معدوم.
 *   ٢ نواقص احتياجات المنزل: برّاد، غسّالة، كاز، بطارية للإنارة، فرشة أرضية،
 *     فرشات نوم، أغطية، مكواة، وغيرها تُذكر.
 *
 * T-164 had turned «وضع فرش المنزل» into a single grade so that it could be
 * shown to a donor at all. The association has now said what they actually
 * wanted in its place, so the grade goes and these two take over: one is a
 * judgement of the house, the other is a list of what is missing from it —
 * and the second is the one that can be acted on, because a donor reading
 * «برّاد، أغطية» knows what to give.
 *
 * The researcher's own sentence (`furnishing_ar`) stays where it was, for the
 * committee, as does the free text beside «غيرها».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('housing', function (Blueprint $table) {
            $table->dropColumn('furnishing_band');

            $table->string('home_impression')->nullable()->after('has_kitchen');
            $table->json('missing_items')->nullable()->after('home_impression');
            $table->string('missing_items_other_ar')->nullable()->after('missing_items');
        });
    }

    public function down(): void
    {
        Schema::table('housing', function (Blueprint $table) {
            $table->dropColumn(['home_impression', 'missing_items', 'missing_items_other_ar']);

            $table->unsignedSmallInteger('furnishing_band')->nullable()->after('has_kitchen');
        });
    }
};
