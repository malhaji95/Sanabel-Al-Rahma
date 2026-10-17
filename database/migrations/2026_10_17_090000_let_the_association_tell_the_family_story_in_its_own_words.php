<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-106 — «وضروري شرح»، 16 October, and the reason given with it: «قدر ما
 | بكون فيه معلومات كافية وافية للمتبرع، قدر ما بنرغّب المتبرع إنه يدفع».
 |
 | Every field a donor reads today is a count, a band or a label out of a
 | list. That was deliberate and it is what made the rest of T-101
 | publishable. But a column cannot say why this family is different, and the
 | association is right that a donor gives to a household rather than to a
 | table.
 |
 | So: a paragraph written for the donor, and nothing like the two paragraphs
 | already on this file.
 |
 |   `situation_ar` (T-100) is the researcher writing to the committee. It
 |   stays internal, and the test that no service reads it stays.
 |
 |   `story_ar` is written knowing it will be read by strangers, and it does
 |   not reach them until somebody who may approve a file has put their name
 |   to it. Editing it takes the approval away again, or the approval would
 |   mean the version that was approved and not the version on the screen.
 |
 | This is the one free-text field a donor ever sees, and the approval is the
 | whole of why it may be one. A name, a village, a telephone number in it is
 | a disclosure the association made on purpose, with a person attached to
 | the decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->text('story_ar')->nullable()->after('situation_ar');
            $table->foreignId('story_approved_by')->nullable()->after('story_ar')->constrained('users');
            $table->timestamp('story_approved_at')->nullable()->after('story_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('story_approved_by');
            $table->dropColumn(['story_ar', 'story_approved_at']);
        });
    }
};
