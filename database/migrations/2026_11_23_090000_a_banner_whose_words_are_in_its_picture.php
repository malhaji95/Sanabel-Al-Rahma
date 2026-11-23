<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-310 — the association's five wide messages, re-exported at 1920×720 on
 | 23 November, are finished compositions: the headline, the sentence under
 | it and the button are all drawn into the artwork.
 |
 | The carousel was built for the other kind of picture — a photograph that
 | says nothing on its own — so it lays a dark scrim over the whole frame and
 | centres a heading, a line and a button on top. Put one of these five
 | behind that and the page says everything twice, dims the artwork it was
 | sent to show, and stands its own button over a painted one.
 |
 | So the row says which kind it is. The title stays required, because it is
 | what the association reads on its own screen and what the slide is found
 | by; it simply is not drawn over a picture that already carries it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->boolean('text_in_image')->default(false)->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('text_in_image');
        });
    }
};
